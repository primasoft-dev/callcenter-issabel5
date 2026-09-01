# Issabel Call Center - Pre-Release Change History

Numbered entries, newest first, from before the project used version
numbers. Frozen - do not add to it. New changes go in `CHANGES.md`.

---

## 70. Attended Transfer: Caller Stranded on Hold, and a Console Left With Dead Buttons
**Date**: 2026-08-30

Three defects on the attended-transfer path, all rooted in state that is
asynchronous with respect to the transfer it describes. Diagnosis in
`issues/ISSUE_attended-transfer-customer-stranded-on-hold_INVESTIGATION.md`.

### A. The caller was left alone on Music On Hold

An agent starts an attended transfer, the consultation fails instantly, and
instead of the agent being reconnected their channel hangs up and the **caller is
left alone in `[atxfer-hold]`'s `MusicOnHold(,1800)` for up to 30 minutes**, with
the call gone from the agent console. Observed twice back to back on 2026-08-30
(01:41:12 and 01:43:52).

`ECCPConn::Request_agentauth_atxfercall()` starts the transfer with a **single**
AMI `Redirect` carrying an `ExtraChannel`. `action_redirect` performs an
**independent async goto on each channel**, so the agent and the caller then run
in separate PBX threads with nothing synchronising them. When the consult
`Dial()` fails *synchronously inside the channel driver* - an unregistered
`chan_sip` peer allocates no channel and sends no packet - the agent thread runs
all 14 priorities of `[cbxfer-consult]` inside one scheduler slice and reaches
the reconnect **before the caller has even left the previous bridge**:

```
01:41:12 SIP/101-00000002 left 'simple_bridge' basic-bridge <5466d439-...>
01:41:12 [103@cbxfer-consult:10] Dial("SIP/101-00000002", "SIP/103,120,gF(...)U(...)")
01:41:12 NOTICE app_dial.c: Unable to create channel of type 'SIP' (cause 20 - Subscriber absent)
01:41:12 [103@cbxfer-consult:13] Bridge("SIP/101-00000002", "SIP/120Issabel4-00000001")
01:41:12 [103@cbxfer-consult:14] Hangup("SIP/101-00000002", "")
01:41:12 SIP/120Issabel4-00000001 left 'simple_bridge' basic-bridge <5466d439-...>   <- only now
01:41:12 [s@atxfer-hold:3] MusicOnHold("SIP/120Issabel4-00000001", ",1800")
```

`Bridge()` cannot take a channel that is still leaving a dissolving bridge, so it
returned `BRIDGERESULT=FAILURE` - one of the two outcomes `bridge_exec` sets
**silently**, which is why this produced no diagnostics on either side. All 21
consultations in the retained log were classified: the 3 whose `Dial()` failed
instantly account for both strandings (2 of 3), and nothing outside that bucket
has ever stranded.

**Fix**: a shared `[atxfer-rebridge]` context that retries while `BRIDGERESULT` is
`FAILURE` - 20 attempts at 100 ms, ~2 s - and treats `SUCCESS`, `NONEXISTENT`
(the caller has genuinely gone) and `LOOP` as final. The caller only has to run
three dialplan priorities to become bridgeable, so the window is microseconds
wide and the budget is orders of magnitude more slack than it needs. The result
is finally logged, and a `SoftHangup` backstop releases the caller rather than
leaving them on music should the retries ever be exhausted.

| context | `extensions_custom.conf` | `installer.php` | call |
|---|---|---|---|
| `[atxfer-consult]` | 87 | 411 | `Gosub(atxfer-rebridge,s,1(${ATXFER_HELD_CHAN},${ATXFER_ON_HOLD}))` |
| `[atxfer-cancel-consult]` | 113 | 437 | `Gosub(atxfer-rebridge,s,1(${ATXFER_HELD_CHAN},${ATXFER_ON_HOLD}))` |
| `[cbxfer-consult]` | 147 | 471 | `Gosub(atxfer-rebridge,s,1(${ATXFER_HELD_CHAN}))` |
| `[cbxfer-cancel-consult]` | 158 | 482 | `Gosub(atxfer-rebridge,s,1(${ATXFER_HELD_CHAN}))` |

One line replaced by one line at each site, so every priority number and every
existing tail - including the three `Wait(900)` holdwait timers from Change #69 -
is untouched. `Bridge()` returns only when the reconnected conversation ends, so
`Return()` lands back on each site's own tail exactly as the direct call did.

### B. The console's Hold and Transfer buttons could stay disabled forever

Doing quick transfers left the console with Hold and Transfer greyed out and no
message at all; only a page reload cleared it.

`Request_agentauth_atxfercall()` answers `<consultation>true</consultation>` as
soon as its Redirect succeeds, before it can know whether the consultation took.
`do_transfer()` disables Hold/Transfer on that reply, but the *re-enable* lives
only in the `consultationend` event handler.

The consultation is marked by an **asynchronous** message
(`marcarConsultationIniciada`) sent just before the Redirect. When the consult
fails instantly the `ConsultationEnd` UserEvent overtakes that message, which is
then discarded on purpose - that discard defends a different race and must stay,
see the comment in `_marcarConsultationIniciada()` - so `ConsultationStart` is
never emitted and `estadoCliente.consultation` stays `'none'`. The resync in
`manejarSesionActiva_checkStatus()` then sees client and server agreeing on
`'none'` and synthesizes nothing:

```
15:37:08 ConsultationEnd received for agent=SIP/101  was_in_consultation=NO
15:37:08 _marcarConsultationIniciada - ignoring late consultation mark for SIP/101:
         its ConsultationEnd was already processed
```

The intermittency is an ordering coin-flip between two concurrent connections:
the original `ConsultationEnd` is emitted *while the browser is still waiting for
the `atxfercall` HTTP reply*. If the event wins it enables the buttons and the
reply then disables them - stuck; if the reply wins the event re-enables them and
nothing is wrong. No message appeared because `do_transfer()` only alerts on
`action == 'error'`.

**Fix**: `arm_consultation_watchdog()`, armed by the same speculative disable,
undoes it 5 s later if the console never learned that a consultation started. A
real consultation moves `estadoCliente.consultation` to `'ringing'`/`'answered'`
and makes the watchdog a no-op; its call and hold guards mirror the ones the
`consultationend` and `holdenter` handlers already use, so it can never
re-enable a button another handler meant to keep disabled. Being purely local it
is immune to both the ordering race and event loss.

### C. The agent was never told why the consultation ended

Same race. The dialer stores the consult `DIALSTATUS` in `_ultimaConsultaFallida`
and exposes it as `consultation_reason`, but the resync only ships it on a state
mismatch - and here both sides read `'none'`, so the "colleague busy /
unavailable" notice was silently dropped.

**Fix**: `_marcarConsultationIniciada()` re-emits `ConsultationEnd` with the
stored reason when it discards a late mark.

```php
+            $sDialStatus = isset($this->_ultimaConsultaFallida[$sAgente])
+                ? $this->_ultimaConsultaFallida[$sAgente] : '';
+            $this->_tuberia->msg_ECCPProcess_emitirEventos(array(
+                array('ConsultationEnd', array($sAgente, $sDialStatus))
+            ));
             return;
```

This fires after the async message is processed, i.e. after the HTTP reply has
almost certainly landed, so it is the delivery that actually reaches the console.
Re-emitting is harmless when the first one did arrive: `mostrar_mensaje_info()`
rewrites the text of a single banner.

### Rejected

- **Reordering `UserEvent(ConsultationEnd)` after the reconnect** - on the success
  path the reconnected conversation can last minutes, and the console would show
  "in consultation" for its whole duration.
- **Gating the ConsultationEnd cleanup on the flag already being set** - that is
  the race the unconditional `unset` exists to defend; it would wedge the
  console's "Cancel transfer" cue until a reload.
- **Setting `estadoCliente.consultation = 'ringing'` in `do_transfer()`** - fixes
  the buttons, but makes client and server agree on `'ringing'`, suppressing the
  `consultationstart` resync that is the only reliable delivery of that event and
  leaving the Hangup button label stuck.
- **Dropping the speculative disable and relying on `consultationstart`** - opens
  a double-submit window during exactly the rapid-transfer pattern that already
  produces `msg_Dial: canal remoto en conflicto`.
- **Copy-pasting the `[cbxfer-consult]` fix into the two `atxfer-*` sites** - when
  `ATXFER_ON_HOLD=yes` the held caller is *parked* (Change #69) and `Bridge()` on
  a parked channel legitimately succeeds, so a retry-then-`SoftHangup` there could
  force-release a caller already capped by the `callcenter_hold` lot's
  `parkingtime`. Those two sites pass `ATXFER_ON_HOLD` as `ARG2`, which suppresses
  the release backstop and nothing else.

Left alone deliberately: `[atxfer-bridge]`, where a `NONEXISTENT` is the caller
having genuinely hung up before the transfer completed and is the correct
outcome; and `[atxfer-unhold]`, driven by a single-channel Redirect out of
parking with no concurrent `ExtraChannel`, so it is not exposed to the race.

**Verification performed**: `php -l` clean on both changed PHP files,
`node --check` clean on the JS. `dialplan reload` (never an Asterisk restart);
`dialplan show atxfer-rebridge` loads 16 priorities with every label resolved,
and the four call sites keep their original priorities - `cbxfer-consult` is
still 14 priorities with the reconnect at 13.

The give-up branch, the one path with no prior evidence behind it, was exercised
with scratch contexts (`Bridge()` forced to `FAILURE`) against a real channel
parked in `[atxfer-hold]`'s MusicOnHold: 20 attempts spanning 15:22:55 to
15:22:57, the WARNING logged with both channel names and the attempt count,
`SoftHangup` releasing the held channel - `Stopped music on hold` followed by
`Spawn extension (atxfer-hold, s, 3) exited non-zero`, the same shape as a caller
hangup, which is what the dialer's finalization expects - and `Return()` landing
back on the caller's own tail. The `ARG2="yes"` guard was exercised the same way:
priority 10 took `1?done`, no WARNING, no `SoftHangup`, parked caller untouched.
The scratch contexts were removed afterwards.

Confirmed on a real call at 15:32:15, an Agent-type attended transfer to a busy
102:

```
[102@atxfer-consult:7] Gosub("SIP/101-00000001", "atxfer-rebridge,s,1(PJSIP/PJSIP120Issabel4-00000004,)")
[s@atxfer-rebridge:5] Bridge("SIP/101-00000001", "PJSIP/PJSIP120Issabel4-00000004")
Spawn extension (atxfer-hold, s, 3) exited non-zero on 'Surrogate/PJSIP/PJSIP120Issabel4-00000004'
```

Reconnected on attempt 1; the `Surrogate/` prefix is the signature of a clean
yank out of MusicOnHold. The `[atxfer-hold]`..`[cbxfer-done]` region of
`setup/installer.php` is byte-identical to the live
`/etc/asterisk/extensions_custom.conf`, so a fresh install ships what is running.
Dialer restarted clean. Live operator testing across both agent types found no
issues.

**Test steps**: with a colleague extension defined but **not** registered, take an
inbound queue call and attended-transfer to it ten times - the agent must be
reconnected every time and no run may leave the caller alone on hold. Repeat with
the caller hanging up during the consult: `BRIDGERESULT=NONEXISTENT` returns at
once with no 2 s spin. Transfer to a registered colleague and complete it, then
transfer and cancel while it rings - both unchanged. As an Agent-type login,
press Hold during a consultation and then end the consultation: the caller stays
parked and End Hold still retrieves them, with no forced release. After a hard
refresh of the console, a transfer to an unregistered or busy extension must
re-enable Hold and Transfer on its own and show the busy/unavailable notice.

```bash
# Did the reconnect need retries, and did it ever give up? (previously silent)
grep -aE "reconnect finished BRIDGERESULT|could not reconnect|no held caller channel" \
     /var/log/asterisk/full | tail -20

# Stranding detector: a caller left in atxfer-hold with no following new bridge
grep -aE "Executing \[s@atxfer-hold:3\] MusicOnHold" /var/log/asterisk/full | tail -20

# The consultation-state race firing, and the re-emitted ConsultationEnd after it
grep -aE "ConsultationEnd UserEvent received|ignoring late consultation mark" \
     /opt/issabel/dialer/dialerd.log | tail -20

asterisk -rx "dialplan show atxfer-rebridge"
```

**Files affected**:
- `/etc/asterisk/extensions_custom.conf` (live; new `[atxfer-rebridge]`, four
  `Gosub` call sites)
- `setup/installer.php` - the same contexts in the generator, so a reinstall does
  not regenerate the racy dialplan
- `/opt/issabel/dialer/AMIEventProcess.class.php` (live) and its repo copy under
  `setup/dialer_process/dialer/`
- `/var/www/html/modules/agent_console/themes/default/js/javascript.js` (live) and
  its repo copy under `modules/agent_console/`
- `TODO.md`, `issues/ISSUE_attended-transfer-customer-stranded-on-hold_INVESTIGATION.md`

---

## 69. Beep on End Hold, But Only Sometimes
**Date**: 2026-08-30

Putting a call on hold and resuming it played a beep to the agent *and* to the
customer - but only on an ordinary hold. A hold taken after a failed or cancelled
attended transfer was silent at both ends. Same button, same call, two behaviours.

Hold always parks the customer, but the two paths retrieve them by different means
and only one of them touches the parking API:

| | ordinary hold | hold after a failed transfer |
|---|---|---|
| park | AMI `Park` -> lot `default` | AMI `Park` -> lot `default` (same) |
| resume | `Originate` agent -> `7001@from-internal` -> `ParkedCall()` | `Redirect` agent -> `[atxfer-unhold]` -> `Bridge()` |
| beeps | `AgentRequest()` alert **+** the lot's `courtesytone` | none |

`/etc/asterisk/res_parking_additional.conf` carries `courtesytone=beep` with
`parkedplay=both`, so `ParkedCall()` beeps at both ends. `Bridge()` never consults
the parking lot, hence the silence. Traced on a live Agent-type login (1001 on
`SIP/101`):

```
--- ordinary hold / End Hold --------------------------------------------
12:51:57  Parking 'PJSIP/PJSIP120Issabel4-00000000' in 'default' at space 7001
12:51:57  Started music on hold, class 'Primasoft', on PJSIP/...-00000000
12:52:03  AgentRequest(Local/1001@agents-00000001;2, "1001")
12:52:03  <SIP/101-00000000>              beep   #1  custom_beep    -> AGENT
12:52:04  ParkedCall(Local/1001@agents-00000001;1, "default,7001")
12:52:04  <Local/1001@agents-00000001;1>  beep   #2  courtesytone   -> AGENT
12:52:04  <PJSIP/PJSIP120Issabel4-...>    beep   #3  courtesytone   -> CUSTOMER

--- hold / End Hold after a transfer to 102 was rejected ----------------
12:53:10  [102@atxfer-consult:5] "Consultation ended DIALSTATUS=BUSY - reconnecting"
12:53:20  Parking 'PJSIP/PJSIP120Issabel4-00000001' in 'default' at space 7001
12:53:24  [s@atxfer-unhold:2] Bridge(SIP/101-00000001, PJSIP/...-00000001)
          (no beep.gsm in this window)
```

**Fix**: agent hold now parks into a dedicated `callcenter_hold` lot that defines no
`courtesytone`, so beeps #2 and #3 are gone in every path.

```php
         // Don't pass AnnounceChannel to suppress parking slot announcement to customer.
+        // Park into the call center's own lot: it carries no courtesytone, so
+        // neither the agent nor the customer hears a beep when the hold ends.
         $ami->asyncPark(
             $callable, $call_params,
-            $this->actualchannel);
+            $this->actualchannel,
+            NULL,                   // AnnounceChannel - keep the slot announcement suppressed
+            NULL,                   // Timeout - use the lot's own parkingtime
+            'callcenter_hold');     // Parkinglot - silent call center lot
```

The lot is written to `/etc/asterisk/res_parking_custom_general.conf`, an
IssabelPBX `_custom` file that survives regeneration. It **shares the `parkedcalls`
context** with the PBX lot: Asterisk only forbids overlapping generated
*extensions*, and `70000`/`70001-70100` does not overlap `7000`/`7001-7010`.
Sharing the context means the slots are already reachable from `from-internal`, so
`ECCPConn::Request_agentauth_unhold()` keeps Originating to
`${park_exten}@from-internal` unchanged and no new dialplan context is needed.
Lot settings: `parkingtime=900` (was 300 - the maximum hold time), `parkpos`
100 slots (was 10 - the cap on concurrent holds system-wide), `comebacktoorigin=no`,
`parkedmusicclass=default`.

Turning `courtesytone` off on the PBX `default` lot was rejected: it would silence
parked-call pickup for every extension on the box, and IssabelPBX regenerates
`res_parking_additional.conf` anyway.

Beep #1 is untouched by design. It is the `agents.conf` `custom_beep` that
`app_agent_pool` plays whenever a call is offered to an Agent-type agent - the same
alert they get for a new inbound call, which matters because agents auto-accept.
End Hold genuinely re-offers the call, so the alert still fires there. Callback
logins (SIP/PJSIP/IAX2) never go through `AgentRequest()` and are now silent in
both paths.

Two consequences that came with the change:

- **`Wait(300)` -> `Wait(900)`** at the `holdwait` label of `[atxfer-consult]`,
  `[atxfer-unhold]` and `[atxfer-cancel-consult]`. On a hold taken around a
  transfer the customer is capped by `parkingtime` while the agent waits at
  `holdwait` capped by `Wait`. Both were 300 s and expired together; leaving `Wait`
  behind would have opened a 10-minute window in which the agent is back in
  `AgentLogin` and available for a new queue call while a customer is still parked.
- **A hold left past `parkingtime` now ends the call** instead of ringing the
  agent's device back. `park-return-routing` only lists 7000-7010, so a 70xxx
  timeout finds no extension and Asterisk hangs the parked channel up. That is an
  already-handled outcome, not a new failure: the dialer does not subscribe to
  `ParkedCallTimeOut` (`AMIEventProcess.class.php:360`, commented out) and
  `msg_Hangup()`'s `OnHold` branch exists precisely for it - its comment names *"the
  park timeout return dialed an invalid target and Asterisk hung up the parked
  channel directly"* - closing the hold audit and finalizing the call so no record
  is left stuck. No return route was added, deliberately.

**Verification performed**: `php -l` clean on both changed PHP files; `bash -n`
clean on both changed shell scripts. `module reload res_parking` loaded both lots
with no overlap complaint, and `dialplan show parkedcalls` reports 112 extensions -
`7000` + `7001-7010` from `res_parking/default` alongside `70000` +
`70001-70100` from `res_parking/callcenter_hold`. `dialplan show
70001@from-internal` resolves to `ParkedCall(callcenter_hold,70001)`, the exact
target the unhold `Originate` uses, while `7001@from-internal` still resolves to
`Macro(parked-call,7001,default,parkedcalls)` so PBX parking is untouched. The
emitted AMI action was captured by driving the real `AMIClientConn::__call`
arg-mapping with the socket stubbed out:

```
before: Action: Park / Channel: PJSIP/...-00000099
after:  Action: Park / Channel: PJSIP/...-00000099 / Parkinglot: callcenter_hold
```

`AnnounceChannel` and `Timeout` are correctly omitted, so the parking-slot
announcement stays suppressed and the lot's own `parkingtime` applies.
`instalarLoteParqueoCallCenter()` was run against a scratch file: it produces
byte-identical content to the live file, is idempotent across repeated runs, and
preserves unrelated `[general]` content above its markers; the uninstaller `sed`
removes the block and leaves that content intact. Dialer restarted clean.
**Live call testing is still pending** - the beep counts below have not yet been
re-observed on a real call.

**Test steps**: as Agent-type 1001, take a queue call, Hold, End Hold - the
customer should hear nothing and the agent only the single call-entry alert. Then
attempt an attended transfer to 102, have 102 reject it, and Hold/End Hold again -
silent, as before. Confirm a new inbound queue call still beeps once. Repeat as a
callback login for zero beeps at both ends in both paths.

```bash
# One beep per End Hold, on SIP/<agent-device> only - nothing on the customer's
# PJSIP trunk leg and nothing on Local/1001@agents-...;1
grep -aE "Playing 'beep.gsm'|Parking '|ParkedCall\(|AgentRequest\(|atxfer-unhold" \
     /var/log/asterisk/full | tail -50

# Must read callcenter_hold, never default
grep -a "parking/parking_bridge.c: Parking " /var/log/asterisk/full | tail -10

grep -E "DEBUG_HOLD|asyncPark|_cb_Park|park_exten|ParkedCallGiveUp|RegresaHold" \
     /opt/issabel/dialer/dialerd.log | tail -40
```

Worth watching on the first real calls: the retrieval leg no longer runs
IssabelPBX's `macro-parked-call`, which used to restart MixMonitor and run
`macro-user-callerid`. Recording should be unaffected - the original MixMonitor
sits on the customer's channel, which is continuous across park and retrieval - and
the agent's display after End Hold should now show the customer rather than the
agent's own extension, since `macro-user-callerid` is no longer overwriting the
CallerID the dialer sets on the `Originate`.

**Files affected**:
- `/opt/issabel/dialer/Llamada.class.php` (live) and its repo copy under
  `setup/dialer_process/dialer/`
- `/etc/asterisk/res_parking_custom_general.conf` (live; new `callcenter_hold` lot)
- `/etc/asterisk/extensions_custom.conf` (live; three `Wait(900)`)
- `setup/installer.php` - new `instalarLoteParqueoCallCenter()`, called beside
  `instalarContextosEspeciales()`; `Wait(900)` in the contexts heredoc
- `build/5.0/install-issabel-callcenter.sh` - post-install notice now reports the
  call center lot instead of advising changes to the PBX lot
- `build/5.0/remove-issabel-callcenter.sh` - strips the parking block on uninstall
- `README.md`, `TODO.md`

---

## 68. Scheduled-Call Agent Reservation Crashed on PHP 7.4
**Date**: 2026-08-30

`Agente::setReserved()` called `_incrementarPausas($ami)` with one argument.
That method has taken three since commit `c9a1c5d` (2017-06-02, "Record pause
reason in queue_log"), which added `$reason` and `$nombre_pausa` and updated
`setBreak()` and `setHold()` - but missed `setReserved()`. On PHP 5 the
shortfall was a "Missing argument" warning and execution continued; PHP 7.1+
raises `ArgumentCountError`, and the dialer installs no `set_exception_handler`
and catches no `Throwable`, so the reservation would take `AMIEventProcess`
down with it.

The path is reached whenever a `calls` row has `agent IS NOT NULL` inside its
schedulable window and that agent is logged in:
`_actualizarLlamadasAgendables()` -> `AMIEventProcess::_agentesAgendables()` ->
`Agente::setReserved()`. It was latent on this box - `SELECT COUNT(*) FROM calls
WHERE agent IS NOT NULL` is 0 and `dialerd.log` has no occurrences - so it had
never been exercised since the PHP 7.4 port.

**Fix**: pass the two missing arguments.

```php
-            $this->_incrementarPausas($ami);
+            $this->_incrementarPausas($ami, NULL, 'Reserved');
```

`$reason` is unused inside `_incrementarPausas()`; only `$nombre_pausa` is used,
as the `Reason` field of the AMI `QueuePause` action, so the pause is now
recorded as `Reserved` instead of an empty string. Queue scope is unchanged:
`QueuePause` is still sent with no `Queue` field, so a reserved agent is paused
in every queue they belong to, which is the intended behaviour.

**Verification performed**: `php -l` clean. Runtime proof with a reflection
harness that builds an `Agente` without its constructor and calls
`setReserved()` - against the pre-fix file it returns `ArgumentCountError: Too
few arguments to function Agente::_incrementarPausas(), 1 passed ... and exactly
3 expected`, against the fixed file it completes with no error. With queues
attached and a mock AMI, the action goes out as `QueuePause Queue=(omitted -
all queues) Paused=true Reason='Reserved'`. Dialer restart to load the fix is
still pending.

**Test steps**: create an outgoing campaign call assigned to a specific agent
(`UPDATE calls SET agent = 'Agent/1001' WHERE id = <id>`), log that agent in,
and let the campaign cycle run. The agent should go to pause and the scheduled
call should be placed to them, with no dialer crash.

```bash
grep -E "agentesAgendables|setReserved|Reserved|reservado" /opt/issabel/dialer/dialerd.log | tail -20
grep -iE "ArgumentCountError|Too few arguments|Fatal error" /opt/issabel/dialer/dialerd.log | tail -10
```

**Files affected**:
- `/opt/issabel/dialer/Agente.class.php` (live system; the repo copy under
  `setup/dialer_process/dialer/` is unchanged)

---
## 67. Remove the Dead Static Queue Member Warning
**Date**: 2026-08-29

`SQLWorkerProcess` warned when a queue had a static member, testing each
`member=` line of `queues_additional.conf` with
`stripos($regs[2], 'SIP/') === 0 || stripos($regs[2], 'IAX2/') === 0`.

Issabel writes **every** static member as a Local channel, whatever the
extension's technology:

```
member=Local/101@from-queue/n,0,User101,hint:101@ext-local
member=Local/102@from-queue/n,0,User102PJSIP,hint:102@ext-local
member=Local/105@from-queue/n,0,User105IAX,hint:105@ext-local
```

None start with `SIP/` or `IAX2/`, so the warning could never fire - for any
technology, not just PJSIP. Confirmed live: 101 (SIP), 102 (PJSIP) and 105
(IAX2) were all added to queue 502 as static members and the dialer restarted;
the file was parsed and zero warnings were logged.

This was first filed as "the warning is missing PJSIP". Adding `PJSIP/` would
have fixed nothing. Re-targeting it at the `Local/<ext>@from-queue` form would
warn on every normal static member, which is noise, so the check is removed
instead.

**Fix**: dropped the `elseif` branch. The surrounding loop keeps its real job,
reading `eventmemberstatus` and `eventwhencalled` per queue.

**Verification performed**: `php -l` clean; the rewritten loop run against the
live `queues_additional.conf` still returns `eventmemberstatus=true`,
`eventwhencalled=true` for queues 501, 502 and 503; deployed and dialer
restarted with `_requerir_nuevaListaAgentes` and queue-membership verification
running normally, no errors.

**Files affected**:
- `setup/dialer_process/dialer/SQLWorkerProcess.class.php` (also applied to
  `/opt/issabel/dialer/`)

---

## 66. PJSIP Trunks Accepted by Outgoing Campaigns
**Date**: 2026-08-29

`CampaignProcess::_construirPlantillaMarcado()` whitelisted `SIP/`, `Zap/`,
`DAHDI/`, `IAX/` and `IAX2/` when a campaign pins an explicit trunk, but not
`PJSIP/`. The check is `strpos($sTrunk, 'SIP/') === 0`, and `'PJSIP/'` does not
start with `'SIP/'` - `strpos` returns 2, not 0 - so a PJSIP trunk fell through
to the "unknown trunk type" branch.

The GUI offers the trunk regardless: `paloSantoTrunk::getTrunks()` builds
`upper(tech)/channelid`, so `PJSIP/<name>` appears in the campaign's trunk
dropdown. Selecting it produced a campaign that selected contacts but never
dialled, logging this **every 3 seconds indefinitely**:

```
ERR: trunk 'PJSIP/...' es un tipo de trunk desconocido. Actualice su versión de CallCenter.
ERR: no se puede construir plantilla de marcado a partir de trunk 'PJSIP/...'!
```

Only the whitelist was at fault. `_leerPropiedadesTrunk()` already handles PJSIP
correctly: it lowercases the technology and queries `asterisk.trunks` with
`tech = 'pjsip'`, which matches the row issabelPBX writes.

Note this only affects campaigns with an **explicitly pinned** trunk. A campaign
left in dialplan mode (`trunk` = NULL) builds `Local/$OUTNUM$@from-internal` and
lets the outbound route pick the trunk, so PJSIP trunks already worked that way
and never touched this code.

**Fix**: added `stripos($sTrunk, 'PJSIP/') === 0` to the whitelist.

### Second half: the dial-string format

Allowing `PJSIP/` through the whitelist was necessary but not sufficient. The
template was still built in chan_sip's shape:

```
SIP/TRUNKLABEL/<PREFIX>$OUTNUM$   ->   PJSIP/PJSIP120Issabel4/0100100102
```

chan_pjsip does not accept `TECH/trunk/number`. Its dial string is
`PJSIP/<user>@<endpoint>`, so Asterisk rejected the channel string before
creating a channel and the Originate failed instantly:

```
[Response] => Failure   [Channel] => PJSIP/PJSIP120Issabel4/0100100102
[Reason] => 0           [Uniqueid] => <unknown>
```

The dialer then held the call waiting for a failure cause that never arrived,
and reaped it a minute later:

```
ERR: llamada 14903-7-65 espera causa de fallo desde hace 76.69 segundos, se elimina.
```

issabelPBX itself does exactly the rewrite that was missing. In
`macro-dialout-trunk` it builds the chan_sip shape first and then converts it:

```
exten => s,n,Set(DIALSTR=${OUT_${DIAL_TRUNK}}/${OUTNUM})
exten => s,n,GosubIf($["${DIALSTR:0:5}" = "PJSIP"]?pjsipdial,1())
exten => pjsipdial,1,Set(PJ=${CUT(DIALSTR,/,2)})
exten => pjsipdial,n,Set(DIALSTR=PJSIP/${OUTNUM}@${PJ})
```

with `OUTNUM = ${OUTPREFIX_${DIAL_TRUNK}}${DIAL_NUMBER}`, so the dial-out prefix
belongs on the number, ahead of the `@`.

**Fix**: for a `PJSIP/` trunk the template is now
`PJSIP/<PREFIX>$OUTNUM$@ENDPOINT`; every other technology keeps
`TECH/TRUNKLABEL/<PREFIX>$OUTNUM$` unchanged.

| trunk | template |
|---|---|
| `SIP/120Issabel4` | `SIP/120Issabel4/$OUTNUM$` (unchanged, CID `1500` preserved) |
| `PJSIP/PJSIP120Issabel4` | `PJSIP/$OUTNUM$@PJSIP120Issabel4` |
| with a `9` prefix | `PJSIP/9$OUTNUM$@PJSIP120Issabel4` - matches `PJSIP/${OUTNUM}@${PJ}` |

Note the three-part form is not universally invalid - `PJSIP_DIAL_CONTACTS()`
returns `PJSIP/102/sip:102@192.168.1.77:61859;ob`, which chan_pjsip accepts
because the third field there is a full SIP URI. A bare dialled number is not a
URI, which is why the trunk case failed.

Live confirmation of an actual outbound call through a pinned PJSIP trunk is
still outstanding; the campaign was stopped at the time of the fix.


**Verification performed**:
- `php -l` clean.
- Branch-logic table over eleven trunk forms - NULL, `SIP/`, `PJSIP/`, lowercase
  `pjsip/`, `IAX2/`, `IAX/`, `DAHDI/`, `Zap/`, a `$OUTNUM$` custom trunk, a
  `Local/` custom trunk, and `H323/`. Only the two PJSIP forms change; `H323/`
  is still correctly rejected and every other form is untouched.
- Drove the **real shipped method** through reflection against the live
  `asterisk.trunks` table:

  | trunk | result |
  |---|---|
  | `NULL` | `Local/$OUTNUM$@from-internal` |
  | `SIP/120Issabel4` | `SIP/120Issabel4/$OUTNUM$` + CID `1500` (unchanged) |
  | `PJSIP/PJSIP120Issabel4` | `PJSIP/PJSIP120Issabel4/$OUTNUM$` (was rejected) |
  | `IAX2/nosuchtrunk` | `NULL` - still rejected, no such row |

- Deployed and dialer restarted clean.

**Files affected**:
- `setup/dialer_process/dialer/CampaignProcess.class.php` (also applied to
  `/opt/issabel/dialer/`)

---

## 65. ECCP XML Hardening: Escaping Helper, Serialization Fail-Safe, DB Charset
**Date**: 2026-08-29

Resolves the **Critical** "ECCP Response Silently Dropped When Serialization
Fails" and **High** "Hand-Rolled XML Escaping in the ECCP Protocol" TODOs, both
raised by a field report: a WebRTC client made the dialer log
`SimpleXMLElement::asXML(): invalid character value` while database connections
climbed until they hit `max_connections`.

### (a) One escaping helper instead of 54 copies of the same expression

Every value written into an ECCP response went through
`str_replace('&', '&amp;', $v)` before `addChild()` - 35 times in
`ECCPConn.class.php` and 19 times in `ECCPProxyConn.class.php`. Confirmed before
touching anything that all 54 were **byte-for-byte the same expression** applied
to a plain variable or array element, with no variants, so the replacement is
purely mechanical. (Three other `str_replace()` calls in these files strip a
date prefix and are unrelated; they were left alone.)

Measured what `addChild()` actually does on PHP 7.4 before changing it:

| input | `addChild()` alone | with the old `str_replace` |
|---|---|---|
| `<` and `>` | escaped correctly | escaped correctly |
| `&` | **value dropped entirely** ("unterminated entity reference") | correct |
| `&amp;` as literal text | round-trips | round-trips |
| control char (e.g. `0x0B`) | value emptied, libxml warning | value emptied, libxml warning |
| invalid UTF-8 | **silently truncated at the bad byte, no warning** | same |

So the `&` escaping is load-bearing and had to be preserved exactly - verified by
round-tripping `A & B`, `A &amp; B`, `R&D` and `100% & <b>` through
`addChild()` -> `asXML()` -> re-parse, all byte-identical before and after. The
two real gaps are the last two rows.

**Fix**: added `xmlSafe()` to `ECCPHelper.lib.php` (the library `ECCPConn` and
`SQLWorkerProcess` already load; `ECCPProxyConn` now loads it too) and replaced
all 54 call sites. It repairs invalid UTF-8 to `U+FFFD`, strips the characters
forbidden in XML 1.0 (`0x00-0x08`, `0x0B`, `0x0C`, `0x0E-0x1F`) while keeping
tab, newline and carriage return, then applies the original `&` escape.

### (b) The client is now always answered

`do_eccprequest()` ended with `$s = $response->asXML();` and returned `$s`
unchecked. When libxml refuses to serialize the tree, `asXML()` returns `FALSE`,
which reaches `MultiplexServer::encolarDatosEscribir()` and is concatenated with
`.=` - appending an empty string. **Nothing was written to the socket and the
request was never answered**, with no log line saying a response had been lost.

That is what pumped the database. A client that retries or reconnects on timeout
multiplies in-flight requests; each concurrent request is dispatched to a
*distinct* free `ECCPWorkerProcess` (`HubProcess.class.php:307-326`), a new one
is spawned when none is free, each holds its own PDO connection, and idle
workers are never reaped - so the connection count only ever climbs. The
unbounded worker pool is intended behaviour and was left as is; the silent drop
was the defect.

**Fix**, two layers:
- `ECCPConn.class.php` - if `asXML()` returns `FALSE`, log it and send a
  well-formed `<failure>` response carrying the original request id, with a
  fixed literal-XML last resort if even that cannot be serialized.
- `MultiplexServer.class.php` - `encolarDatosEscribir()` now rejects and logs a
  non-string payload instead of silently appending nothing. This covers the 17
  further `asXML()` call sites on `ECCPProxyConn`'s asynchronous event paths,
  which previously could drop an event without a trace.

Note the reporting client's libxml differs from this box's. On libxml 2.9.7 the
same input degrades gently - `xmlEscapeEntities : char out of range`, the value
is emptied and a string is still returned. The `invalid character value` message
and the outright `FALSE` come from a newer libxml. Same root cause, worse
failure mode there; (a) prevents it and (b) contains it either way.

### (c) `call_center` database default charset

Change #32 converted every table to `utf8mb4`, but the **database itself** was
still `latin1`: `paloSantoInstaller::createNewDatabaseMySQL()` issues a bare
`CREATE DATABASE $db_name` with no charset, so it inherits the server default.
Every existing table was already `utf8mb4` so nothing was broken, but any future
`CREATE TABLE` without an explicit charset would have inherited `latin1` - the
exact trap Change #32 had to clean up for five tables. The installer's
`convertirCharsetUtf8mb4()` fixes tables, not the schema default.

**Fix**: `setup/installer.php` now runs
`ALTER DATABASE call_center CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci`
before the per-table conversion. Applied to the live database as well.
`paloSantoInstaller.class.php` is Issabel core, shared by every module and
replaced on upgrade, so it was deliberately not modified.

**Verification performed** (live box, idle: 0 channels, 0 agents online, no
active campaigns; Asterisk never restarted, uptime continuous throughout):
- `php -l` clean on all five edited files.
- Confirmed all 54 escaping call sites were one identical expression before
  replacing them, and that zero occurrences of the old idiom remain.
- Unit-tested `xmlSafe()` over 14 inputs - ampersands, literal `&amp;`, angle
  brackets, a script tag, accented and CJK and emoji UTF-8, vertical tab, NUL,
  ESC/BEL, invalid latin1 bytes, tab/newline, an integer and NULL. All 14 now
  serialize with **no libxml warning at all**; previously four of them warned or
  silently lost data. Clean input still round-trips byte-identical.
- End-to-end through the running dialer: stored
  `R&D <b>x</b>\x0B café \x00 ñ` into `break.name`, then read it back over
  ECCP with `getpauses.php`. The client received
  `R&D <b>x</b> café  ñ` - ampersand and markup intact, control characters
  stripped, UTF-8 preserved. The same bytes through the old expression produced
  an **empty** value plus `xmlEscapeEntities : char out of range`. Value
  restored afterwards.
- Both fallback layers in (b) were parsed to confirm they are well-formed.
- Confirmed the two `asterisk`-database connections changed in #64 are safe:
  that database and all 98 of its tables are already `utf8mb4`, and
  `SELECT user, dial FROM devices` plus `SHOW TABLES LIKE 'trunks'` return
  identical results with and without the charset in the DSN.
- Regression sweep after deploy and dialer restart: `getpauses`,
  `getcampaignlist`, `agentstatus` for SIP/PJSIP/IAX2, `getagentqueues` for all
  three techs and `getextensionstatus` against ground truth - all correct, no
  `ERR`/`WARN` in the dialer log.

**Files affected** (repo and the live copies under `/opt/issabel/dialer/`):
- `setup/dialer_process/dialer/ECCPHelper.lib.php` - new `xmlSafe()`
- `setup/dialer_process/dialer/ECCPConn.class.php` - 35 call sites, `asXML()` fail-safe
- `setup/dialer_process/dialer/ECCPProxyConn.class.php` - 19 call sites, library include
- `setup/dialer_process/dialer/MultiplexServer.class.php` - non-string write guard
- `setup/installer.php` - `ALTER DATABASE ... utf8mb4`

---

## 64. Device-Type Defects Found in the PJSIP / IAX2 Test Pass
**Date**: 2026-08-29

All prior call-center testing had been done with `chan_sip` devices. A targeted
pass over the code that actually branches on channel technology turned up four
defects, three of them confirmed live with a same-run SIP control proving the
failure was device-type-specific. All four are fixed here.

### (a) IAX2 agents could never log in - wrong dynamic-queue prefix letter

`AMIEventProcess::_cb_Command_DatabaseShow()` derived the AstDB member key from
the **first letter of the agent type**:

```php
$extension = $tupla['type'][0] . $tupla['number'];   // 'IAX2'[0] === 'I' -> I105
```

issabelPBX's own convention, in
`/var/www/html/admin/modules/queues/agi-bin/queue_devstate.agi:96`, is:

```php
$member_prefix = array('A'=>'AGENT','S'=>'SIP','P'=>'PJSIP','X'=>'IAX2','Z'=>'ZAP','D'=>'DAHDI');
```

`S`/`P`/`A` happen to agree with `$type[0]`; **`IAX2` maps to `X`, not `I`.** So
for IAX2 - and only IAX2 - the dialer looked up a key that nothing ever writes.
The GUI writes `X105`, the dialer looked for `I105`, found nothing, and refused
login with "dynamic agent IAX2/105 is not dynamic member of any queue".

Adjudicated by tracing all three consumers of the QPENALTY keys rather than
assuming: the dialplan and `generate_queue_hints.php` read bare numbers, and
`queue_devstate.agi` maps the prefix letter to `MEMBERTECH`, which becomes
`AddQueueMember(${QUEUENO},${MEMBERTECH}/${CALLBACKNUM},...)`. `X` is correct
and `I` is not a valid prefix anywhere, so **the dialer was the wrong side**.

**Fix**: replaced `$type[0]` with an explicit technology-to-prefix map matching
`queue_devstate.agi`, falling back to `substr($type,0,1)` for any technology not
in the map so unknown types behave exactly as before.

### (b) A dead PJSIP phone reported as registered

`Request_eccpauth_getextensionstatus()` decided a PJSIP endpoint was registered
with:

```php
if (stripos($line, 'Contact:') !== false && stripos($line, 'Avail') !== false)
```

Asterisk 18 contact statuses are `Avail`, **`Unavail`**, `Unknown`, `NonQual`,
`Created`, `Removed`. `'Unavail'` contains `'Avail'` as a substring, so an
endpoint whose contact had gone stale reported `registered: yes` and the agent
was allowed to log in on a phone that could not receive calls. Confirmed live:
PJSIP 102 with an `Unavail` contact returned `yes`, while SIP 101 in the same
state (`UNREACHABLE`) correctly returned `no`.

**Fix**: split the contact line on whitespace and compare the status **token by
token** against an explicit whitelist, instead of substring-matching the whole
line. This also removes a second, subtler false positive: a contact URI that
merely contained the letters "avail" used to be enough.

`NonQual` (contact present, qualify disabled) deliberately still counts as *not*
registered, exactly as before - this change is scoped to the `Unavail` bug and
introduces no new permissive case. The whitelist is a named array so accepting
`NonQual` later is a one-token edit.

### (c) Dialer database connections spoke latin1 to utf8mb4 tables

Change #32 converted every `call_center` table to `utf8mb4`, and noted that
`paloSantoDB.class.php` and the `agent_console` copy already pass
`charset=utf8mb4` in the PDO DSN. **The dialer's own five DSNs did not.** They
therefore negotiated `latin1` against `utf8mb4` columns, so any non-ASCII text
written by the web GUI and read back by the dialer (or vice versa) came back as
mojibake or as invalid UTF-8 - which then feeds straight into
`SimpleXMLElement::asXML()` on the ECCP path.

Verified on the live box: without the charset the connection reported
`character_set_client=latin1`; with it, `utf8mb4`, and a round trip of
`café ñ 日本 😀 & <tag>` came back byte-identical.

**Fix**: appended `;charset=utf8mb4` to all five dialer PDO DSNs (three to
`call_center`, two to the issabelPBX `asterisk` DB).

**Related**: the `call_center` *schema* default was still `latin1` at the time of
this change. Fixed in Change #65(c).

### (d) Removed a dead configuration key

`modules/agent_console/configs/default.conf.php` defined
`'hardware' => 'SIP|IAX2|ZAP|H323|OH323'`, a stale technology whitelist that
predates PJSIP. Nothing in the repo or the live tree ever read the key, so it
was misleading rather than harmful. Removed.

**Verification performed** (live box, idle: 0 channels, no agents logged in, no
active campaigns; Asterisk never restarted):
- `php -l` clean on all six edited files.
- Unit-tested the new PJSIP parser against ten real `pjsip show endpoint`
  output shapes (header-only, Avail, Unavail, Unknown, NonQual, Created, two
  contacts in each combination, `Not Found`, and a URI containing "avail").
  All ten produce the expected verdict; the only behaviour changes versus the
  old parser are the intended ones.
- Confirmed the prefix map changes `IAX2` only - `Agent`, `SIP`, `PJSIP`,
  `ZAP`, `DAHDI` and unknown technologies all keep their previous letter. Those
  are the only four types present in `call_center.agent` on this box.
- End-to-end after deploy and dialer restart, with the GUI-written `X105` key
  in AstDB: `getagentqueues IAX2/105` returns queue `502` (it returned empty
  before the fix), while `SIP/101` and `PJSIP/102` still return `[502, 503]`
  and `SIP/103` still returns `502` - no regression.
- `getextensionstatus` against ground truth after deploy: `SIP/101` yes
  (peer OK), `IAX2/105` yes (peer OK), `PJSIP/102` no (no contacts). The
  live `Unavail` case is covered by the unit test rather than a live capture,
  because the box has no working PJSIP soft client.
- Dialer restarted clean with no `ERR`/`WARN` in the log.

**Files affected** (repo and the live copies under `/opt/issabel/dialer/` and
`/var/www/html/modules/`):
- `setup/dialer_process/dialer/AMIEventProcess.class.php` - prefix map
- `setup/dialer_process/dialer/ECCPConn.class.php` - PJSIP status parser, DSN
- `setup/dialer_process/dialer/CampaignProcess.class.php` - two DSNs
- `setup/dialer_process/dialer/ECCPWorkerProcess.class.php` - DSN
- `setup/dialer_process/dialer/SQLWorkerProcess.class.php` - DSN
- `modules/agent_console/configs/default.conf.php` - dead `hardware` key

---

## 63. TLS Encryption for the ECCP Protocol
**Date**: 2026-08-29

Resolves the **Critical** "ECCP Authentication Security" TODO (open since
pre-2011). The ECCP port carried everything in the clear, so the dialer's login
password — and every call record, contact name and phone number that follows it
— was readable by anyone able to sniff the link. The port also listens on
`0.0.0.0` with no firewall rule, so this was not confined to loopback.

The original FIXME in `ECCPConn.class.php` framed the problem exactly:

> It's not clear to me in what way it's more secure to send the password hash
> than the plaintext password on an unencrypted connection, since in both cases
> it can be captured with a sniffer.

The answer is that it is not — a sniffed MD5 was directly replayable, because
the login accepts `md5_password = ?` as readily as `md5(?)`. Rather than trade
one wire secret for another, the transport itself is now encrypted, which
removes the FIXME's premise and protects the whole stream instead of just the
login.

### Design decisions

- **TLS-only, no plaintext fallback.** Keeping a cleartext port for
  compatibility would have left the sniffable path in place and defeated the
  purpose. A client that opens the port without negotiating TLS gets no reply.
  Blast radius on a deployed system is nil: `agent_console` and all
  `eccp-examples` scripts reach the dialer through the single
  `ECCP::connect()`, so one client edit covers every caller.
- **TLS 1.3 only**, negotiating `TLS_AES_256_GCM_SHA384`. TLS 1.2 is accepted
  only where the PHP/OpenSSL build predates TLS 1.3. Note that TLS 1.3
  ciphersuite selection cannot be pinned from PHP — the `ciphers` context option
  only governs TLS 1.2 and below — but every TLS 1.3 suite is AEAD and
  OpenSSL's default order selects AES-256-GCM.
- **A dedicated self-signed ECDSA P-256 certificate is generated at install**,
  placed at `/etc/issabel/dialer/eccp.pem` (key `eccp.key`) and owned by
  `asterisk`, which the unprivileged dialer needs in order to read it.
  Generated rather than copied from Apache for three reasons: **pinning
  stability** (a pinned fingerprint must not move, and a web certificate is
  renewed — every Let's Encrypt renewal would break every pinned client);
  **handshake cost** (ECDSA P-256 signs ~29x faster than RSA-2048, measured
  0.026 ms vs 0.767 ms, and that signing happens inside the single-threaded
  `ECCPProcess` loop); and **identity separation**, so an ECCP key compromise
  does not also impersonate the web interface and SIP/WSS TLS. It is explicitly
  *not* about keeping the web key away from the `asterisk` user — Issabel
  already ships that same key and certificate as `/etc/asterisk/keys/asterisk.pem`
  owned by `asterisk`, so `copy` mode exposes nothing new. The certificate
  carries no SAN, because nothing checks names; `ECCP_CERT_MODE=generate-san`
  adds them for clients that also want hostname verification, and
  `ECCP_CERT_MODE=copy` reuses Issabel's Apache certificate (sensible when that
  is a real CA-issued certificate). A failed generate falls back to copying.
- **The client does not verify the certificate by default.** Deliberate, and
  the reason the change is safe to deploy anywhere: verification would tie
  connectivity to names and addresses, and installs routinely reach the dialer
  as `localhost`, by hostname, or by bare LAN IP with no local DNS to resolve
  it. With verification off, an IP change, a missing DNS record and even
  certificate expiry are all non-events. This protects against **passive
  interception**; on its own it does not authenticate the server.
- **Optional certificate pinning closes the man-in-the-middle gap** for
  deployments that need it. Clients pin the server certificate by **SHA-256
  fingerprint** — not the chain, not the hostname — so pinning costs nothing in
  reachability: `localhost`, hostname and raw IP keep working with no SAN or DNS
  dependency. `ECCP::setCaFile()` takes a copy of the certificate,
  `ECCP::setPeerFingerprint()` takes the fingerprint alone (for remote clients
  that only have that), and the `ECCP_CA_FILE` / `ECCP_PEER_FINGERPRINT`
  constants enable it globally without touching call sites. `eccp-cert.sh`
  prints the fingerprint on install. A `cafile` chain approach was tried first
  and rejected: OpenSSL will not treat a self-signed leaf as its own trust
  anchor without `X509_V_FLAG_PARTIAL_CHAIN`, which PHP does not expose, so it
  refused the genuine certificate along with impostors.
- **Fail closed, and on the right things.** `ECCPProcess` validates the TLS
  material at startup and declines to start the listener — logging a distinct
  bilingual `FATAL` — when the certificate or key is unreadable, when either is
  not parseable by OpenSSL, or when the key does not match the certificate.
  Checking readability alone was not enough: a corrupt certificate or a
  mismatched key left the port open while every handshake failed with only a
  generic per-connection warning, which is far harder to diagnose than not
  starting. An expired certificate logs a `WARN` and keeps running, since
  nothing verifies expiry but it usually signals a failed renewal.
- **The login credential itself is unchanged.** `eccp_authorized_clients` still
  accepts a password with or without an MD5 hash. Inside TLS it is no longer
  sniffable, which is what the TODO asked for. It remains replayable by anyone
  who obtains it another way (a database read, a compromised client host) — the
  separate "ECCP Client Authorization" TODO covers that ground and stays open.

### Implementation

- `MultiplexServer.class.php` — TLS is **opt-in** via a new optional
  `$rContextoSSL` constructor argument, so the four processes that use this
  class for internal pipes and `HubServer` are untouched. With a context set,
  the listener is still created on `tcp://` and the handshake is driven manually
  after `accept()`, so `stream_socket_accept()` can never block the
  single-threaded server. New `_continuarHandshakeTLS()` advances a
  non-blocking handshake: `stream_socket_enable_crypto()` returning `0` means
  "needs more data" and is retried on the next readable event; the connection is
  not handed to the subclass (`procesarInicial()` is deferred) until it
  completes. Connections that never finish negotiating are reaped after
  `TIMEOUT_HANDSHAKE_TLS` (15 s) by a sweep placed outside the activity branch,
  so a stalled handshake is cleaned up even on an idle server — a
  denial-of-service guard the class previously lacked.
- **`MultiplexServer` empty-read fix (would have broken consoles at random).**
  The read path treated `fread() == ''` as "connection closed remotely". That
  holds for plain TCP but not for TLS, where a socket can be readable while
  OpenSSL holds only a *partial record* and yields no plaintext yet — the old
  logic would have dropped live agent consoles unpredictably. The empty-read
  branch now closes only on a true `feof()`. Correct for plain sockets too.
- `ECCPServer.class.php` — passes the SSL context through to the parent.
- `ECCPProcess.class.php` — reads `tls_cert` / `tls_key` from a new optional
  `[eccp]` section of `dialerd.conf` (defaulting to the `ECCP_TLS_CERT` /
  `ECCP_TLS_KEY` constants), verifies both are readable, and builds the server
  context.
- `modules/agent_console/libs/ECCP.class.php` — `connect()` now dials `tls://`
  with `verify_peer`/`verify_peer_name` off and TLS 1.3 requested, plus the new
  `setCaFile()` / `setPeerFingerprint()` opt-in pinning. Single change point;
  all 28 `paloSantoConsola` call sites and every example inherit it.
- `ECCPConn.class.php` — the obsolete FIXME is replaced with a note recording
  that the transport is now encrypted. The adjacent `eccp_authorized_clients`
  TODO is deliberately left in place: it belongs to a different open item.
- `eccp-cert.sh` (new, shipped in the dialer directory) — `install [--force]`
  and `remove`. It keeps an existing certificate rather than replacing it, so a
  reinstall never churns working TLS material, proves the key is readable *as
  `asterisk`* before reporting success, and prints the SHA-256 fingerprint for
  distribution to clients that pin.
- Certificate lifecycle wired into all three installation paths:
  `install-issabel-callcenter.sh` installs the certificate after the dialer is
  in place and **before** the service starts (it now fails closed without one);
  `remove-issabel-callcenter.sh` deletes it, using inlined `rm`/`rmdir` because
  `eccp-cert.sh` lives in the directory removed on the next line, and `rmdir`
  only removes the directories when empty so an unrelated `/etc/issabel` is
  never clobbered; `issabel-callcenter.spec` calls the same script from `%post`
  and removes the certificate in `%preun` **inside the `$1 -eq 0` guard**, so an
  RPM upgrade keeps it.
- `Protocolo ECCP.txt` and `ECCP_Protocol.md` document the TLS 1.3 transport,
  the default absence of verification, how to opt into pinning, and the
  replacement for `telnet` when debugging
  (`openssl s_client -connect localhost:20005`). The stale `ECCP_Protocol.md`
  claims about "Authorized IPs only" and a five-minute inactivity timeout —
  neither of which was ever implemented — were dropped.

### Verification performed

`php -l` clean on all five PHP files, `bash -n` clean on all three shell
scripts. Dialer restarted with no `ERR:`/`FATAL:` lines after startup.

- **Wire is encrypted (the acceptance test).** `tcpdump` on port 20005 while
  running `getrequestlist.php`: 22 packets captured, TLS record headers
  (`1603 01` ClientHello, `1603 03`) present, and **zero** occurrences of
  `agentconsole`, `<request` or `<login>` — previously all three were plainly
  visible. `openssl s_client` confirms **TLSv1.3 / TLS_AES_256_GCM_SHA384**
  with an ECDSA signature.
- **Plaintext is refused.** A `tcp://` client sending a well-formed
  `<getrequestlist>` receives no response at all.
- **Name/address independence** (the reason verification is off by default):
  login succeeds identically via `localhost`, `127.0.0.1` and the LAN IP.
- **Pinning works and blocks impostors.** With the certificate pinned, login
  succeeds via `localhost`, `127.0.0.1` and the LAN IP — proving pinning keeps
  address independence — both from a copy of the certificate and from a bare
  fingerprint string; pinning to a *different* certificate refuses the
  connection before the login is sent.
- **Handshake guard.** A socket opened and held silent is reaped after 16 s, and
  a normal client logs in successfully *while* that handshake is stalled —
  proving the event loop is not blocked by a slow or hostile peer.
- **Long-lived connection** (exercises the `feof` fix): one TLS connection held
  open for 4 minutes across repeated `wait_response()` polls and requests — the
  agent console's SSE pattern — with no spurious disconnect.
- **Concurrency:** 25 simultaneous TLS sessions opened with no failures, all
  still serving requests afterwards.
- **Client regression sweep:** `getrequestlist`, `agentstatus`, `getpauses`,
  `campaignlog`, `getincomingqueuelist`, `getcampaignlist`, `dumpstatus` all
  pass unmodified, as does the agent console's own credential path (login with
  the stored MD5 hash) and an agent-hash authenticated request.
- **Certificate lifecycle:** install creates `eccp.pem` (0444) and `eccp.key`
  (0400) owned `asterisk:asterisk` and verifies the key is readable as
  `asterisk`; a second run keeps the existing certificate; `--force` refreshes
  it; removal deletes both and leaves a non-empty `/etc/issabel` intact.
- **Fail-closed:** pointing `tls_cert` at a missing file logs the bilingual
  `FATAL` and leaves port 20005 closed.

### Log collection

```bash
grep -E "negociación TLS|TLS negotiation" /opt/issabel/dialer/dialerd.log | tail -20
grep -E "certificado TLS de ECCP|ECCP TLS certificate" /opt/issabel/dialer/dialerd.log
grep -E "escuchando peticiones|listening" /opt/issabel/dialer/dialerd.log | tail -5
grep -cE "FATAL|ERR:" /opt/issabel/dialer/dialerd.log
openssl s_client -connect localhost:20005 -brief </dev/null
openssl x509 -in /etc/issabel/dialer/eccp.pem -noout -fingerprint -sha256
```

---

## 62. ECCP Examples for the Consultation Events
**Date**: 2026-08-28

`eccp-examples/` is the reference client set for the ECCP protocol — one small
script per protocol feature, used to exercise the dialer from a shell without
the agent console. Changes #59 and #60 added a server-side event and extended
another, but shipped no examples for them:

- **`consultationanswered`** — new in #59/#60
  (`ECCPProxyConn::notificarEvento_ConsultationAnswered()`), the signal that
  lets a client offer to *complete* an attended transfer rather than only
  cancel it.
- **`consultationend`** — gained an optional `<reason>` child carrying the
  `${DIALSTATUS}` of the consultation `Dial()`.

Three examples close the gap, one per event, following the trigger-then-listen
pattern `agentlogin.php` already uses: perform the request that produces the
event, then loop on `wait_response()` / `getEvent()` until it arrives. Each one
also breaks on the event that means "this will never arrive", so none of them
can hang.

### Files affected

- `setup/dialer_process/dialer/eccp-examples/consultationstart.php` (new) →
  `/opt/issabel/dialer/eccp-examples/`: starts the consultation with
  `atxfercall()` and waits for `consultationstart`. Also exits on
  `consultationend`, which is what arrives when `_verificarColegaDisponible()`
  refuses a busy-with-Call-Waiting-off or DND colleague before any channel is
  moved.
- `setup/dialer_process/dialer/eccp-examples/consultationanswered.php` (new):
  waits for the colleague to pick up. The extension argument is optional — the
  answer happens on a physical phone and cannot be driven from ECCP, so the
  example either starts the consultation itself or just listens while the
  console does. Exits on `consultationend` when nobody answers.
- `setup/dialer_process/dialer/eccp-examples/consultationend.php` (new):
  demonstrates both shapes of `<reason>`. Listening only shows a natural end
  (`BUSY`/`NOANSWER`/`CONGESTION`/`CHANUNAVAIL`); passing an extension starts
  the consultation and calls `hangup()` on `consultationstart` to cancel it,
  producing the reason-less form.

No dialer or console code changed — these are clients. The examples directory
is copied wholesale by `build/5.0/install-issabel-callcenter.sh` and the RPM
spec, so no packaging change was needed.

### Test steps

With an agent logged in and on an active call:

1. `consultationstart.php Agent/9000 <pass> 9001` → prints `consultationstart`
   as soon as 9001 rings.
2. `consultationanswered.php Agent/9000 <pass> 9001`, then answer 9001 →
   prints `consultationanswered`.
3. `consultationend.php Agent/9000 <pass> 9001` → prints `consultationstart`,
   cancels, then `consultationend` with `(none - cancelled or completed)`.
4. `consultationend.php Agent/9000 <pass>` while transferring from the console
   to a busy or unreachable extension → `consultationend` with the reason.

```bash
grep -E "ConsultationAnswered|ConsultationEnd|CANCELAR CONSULTA" /opt/issabel/dialer/dialerd.log
```

---

## 61. On-Hold State for the Agent Console Status Bar
**Date**: 2026-08-28

The agent console status bar had four states — blue "No active call", purple
"Waiting for call", green "Connected to call" and red "On break". Putting a
call on hold changed none of them: `describirEstadoBarra()` returned
`llamada` as soon as `calltype` was set and never looked at `onhold`, so the
agent saw the same green bar and the same call timer whether the customer was
on the line or parked.

Adds a fifth state: **orange `#F57C00`, "Call on hold"**, with a counter for
the current hold. The colour is the same value `.shift-stat-hold` already uses
for the shift bar's Total Hold Time box — that box stays cumulative for the
shift, while the new bar counter is this hold only.

This covers items (1) and (2) of the "Hold Timeout Countdown" feature request.
Item (3), a countdown of the time *remaining* before the parked call
auto-returns, is **not** implemented and remains tracked in TODO.md.

### The gap that had to be closed first

Hold is not a separate mechanism here — it is a break row with
`break.tipo = 'H'`, writing an `audit` row with `datetime_init`, and `Agente`
tracks it in fields parallel to the break ones (`id_audit_hold` beside
`id_audit_break`). The break half loads its start time through
`cargarInfoPausa()` and publishes it as `pausestart`; the hold half never did,
because nothing had needed it. The console was therefore told *that* an agent
was on hold but not *since when*, so a refresh could not restore the counter.

`cargarInfoPausa()` now loads `holdstart` from `id_audit_hold` using the same
query — it is keyed on `audit.id`, so it serves both — and the agent status
XML publishes `<holdstart>` beside the `<onhold>` it already sent.

### Behaviour

- **Survives F5 mid-hold.** The initial render reads server truth
  (`onhold` + `holdstart`), so the bar comes back orange and the counter
  continues from the real hold start rather than restarting at zero.
- **Not shown while transferring.** During an attended-transfer consultation
  the customer also hears music, but the agent is talking to a colleague, so
  the bar stays green. Structurally an attended-transfer hold is not a dialer
  hold at all — the customer is `Redirect`ed into `[atxfer-hold]` rather than
  parked, so `id_hold` stays null — and a `consultation` guard additionally
  covers the Agent-type `ATXFER_ON_HOLD` path, where a real parked hold can
  coexist with transfer state. A hold taken *after* a consultation ends
  correctly shows orange.
- **Both login types.** Nothing on this path is type-specific: `onhold`
  derives from `id_hold`, set by `_iniciarHoldAgente()` for both, and
  `holdstart` comes from the audit row, written for both.
- Existing precedence is unchanged — hold is evaluated inside the "on a call"
  branch, so a break taken during a call still shows green.

### Bug fixed along the way

**The call timer came back seconds behind after a hold ended.**
`$iDuracionLlamada` was computed at the start of the request, but the long
poll then blocks until an event arrives; when `holdexit` landed N seconds
later, the bar returned to green using a value computed N seconds earlier, so
the timer restarted that far behind. A page reload corrected it. The duration
is now recomputed at the point of use, from whichever `linkstart` is current.

The staleness pattern predates this change — the bar could previously only
reach `llamada` via `agentlinked`, which already recomputed inside the loop,
so nothing exposed it. The hold state added a second route that did not.
Breaks are unaffected: `$iDuracionPausa` is recomputed inside the loop, and
the bar only reaches `break` through a pause event.

### Files affected

- `setup/dialer_process/dialer/ECCPHelper.lib.php` → `/opt/issabel/dialer/`:
  `cargarInfoPausa()` also loads `holdstart` from `id_audit_hold`.
- `setup/dialer_process/dialer/ECCPConn.class.php` → `/opt/issabel/dialer/`:
  `<holdstart>` published beside `<onhold>` in the shared agent-status
  emitter, so both the console and the supervisor view carry it.
- `modules/agent_console/libs/paloSantoConsola.class.php`: reads `holdstart`,
  with the same date normalisation `pausestart` gets.
- `modules/agent_console/index.php`: `hold` state in
  `describirEstadoBarra()`, the page-load and long-poll renderings, and the
  call-timer recomputation.
- `modules/agent_console/themes/default/{js/javascript.js,css/issabel-callcenter.css}`:
  new state class, and stripping it on the next state change.
- `modules/agent_console/lang/{en,es}.lang`: "Call on hold".

No dialplan change; `agent_console.tpl` untouched.

### Test steps

1. Press **Hold** → orange bar, "Call on hold", counter from zero.
2. **F5 while on hold** → orange returns and the counter continues from the
   real hold start.
3. **End Hold** → green returns with the true call duration.
4. Attended transfer, ringing and answered → bar stays green.
5. Cancel a transfer, then Hold → orange.
6. Second hold on the same call → counter restarts for the new hold.

Verified live for both Agent-type and callback-type logins, on inbound and
outgoing calls.

---

## 60. Attended Transfer for Callback Type Login
**Date**: 2026-08-28

Resolves the "Attended Transfer for Callback Type Login" TODO (High, added
2026-08-28). Change #59 fixed the Agent-type (`app_agent_pool`) flow and left
callback-type logins (agents on a plain SIP/IAX2/PJSIP extension) on their
original mechanism, which had four defects. That mechanism was Asterisk's
native `Atxfer` AMI action with `TRANSFER_CONTEXT=cbext-atxfer`, and it was
the root of three of them: it offers no ringing/answered distinction, no
`${DIALSTATUS}`, and no usable cancel. Callback now uses the same
Redirect-based flow as the Agent type, in its own `[cbxfer-*]` contexts, so
both agent types run one engine.

### Bugs fixed

- **Cancelling a transfer disconnected the customer.** All attended-transfer
  hangup handling was gated on `type == 'Agent'`, so callback fell through to
  a branch that made no ringing/answered distinction and always hung up the
  agent's original channel. After the colleague answered that happened to
  behave like a completion; while the colleague was still ringing it dropped
  the customer and left the agent on the consult leg. Cancel now redirects
  only the agent's channel to `[cbxfer-cancel-consult]`, which reconnects
  them to the held customer and hangs up nothing. Completion is an atomic
  dual `Redirect()` — colleague into `[atxfer-bridge]`, agent into
  `[cbxfer-done]`.
- **No "colleague answered" signal.** `[cbext-atxfer]` had no `U()` gosub, so
  the console could not tell ringing from answered and the Hangup button's
  transfer labels were suppressed for callback logins. `[cbxfer-consult]`
  now carries `U(cbxfer-consult-answered^...)`, emitting `ConsultationAnswered`
  with the colleague's channel. The agent id travels as a literal in the
  `Dial()` option string, so no channel-variable inheritance is involved.
- **A busy colleague was rung anyway.** The consultation dials the device
  directly (deliberately, to avoid FreePBX's 20-second `Busy(20)` tone),
  which bypasses `from-internal`/`ext-local` where Call Waiting and Do Not
  Disturb are enforced — so a colleague already on a call was rung
  regardless of their Call Waiting setting, and answering could drop their
  original call. New `_verificarColegaDisponible()` checks `ExtensionState`
  plus `DB(CW/<ext>)` and `DB(DND/<ext>)` *before* any channel is moved:
  busy with Call Waiting off, or DND, is refused instantly with no leg
  placed and no delay. Busy *with* Call Waiting on still proceeds.
- **Stale `transfer` column.** `_registrarTransferencia()` stamps
  `transfer=<exten>` when a consultation starts, for both agent types, but no
  callback path ever cleared it. Callback now reaches the shared
  `ConsultationEnd` handler, which clears it on every natural end.

### Two Agent-type bugs found while auditing the shared helper

`_manejarHangupLoginChannelEnConsulta()` is reached by both agent types,
because the `uniqueidlink` index it resolves through is populated for both.

- **It hung up the customer even when the colleague had answered.** The
  `Hangup(actualchannel)` ran unconditionally, and only then did the
  answered branch do its lightweight release "so the ongoing conversation
  still finalizes with its real duration" — but that conversation had just
  been killed. Reachable whenever an agent completes a transfer by
  physically hanging up their phone instead of clicking the button, which is
  why console-driven testing never hit it. Now conditional on the colleague
  not having answered.
- **It force-logged-off callback agents.** A callback agent whose device hung
  up mid-consultation was run through `_ejecutarLogoffAgente()` and kicked
  out of the console. Now gated to login-channel (Agent type) logins; a
  callback agent keeps its session and simply takes the next call.

### Consultation start/end ordering

`marcarConsultationIniciada` is an async message sent just before the
Redirect, so an instantly-failing colleague could produce a `ConsultationEnd`
UserEvent that was processed *first* — the cleanup was skipped and the late
mark then set a flag nobody would clear, wedging the Hangup button on
"Cancel transfer" until the call ended. Agent type was immune only because
the synchronous `prepararAtxferComplete` RPC had already set a second flag
the guard tested; callback has no such flag. Fixed with a monotonic guard
rather than a new synchronous RPC: `ConsultationEnd` records
`_consultaTerminadaEn[$agent]` and acts unconditionally, and
`_marcarConsultationIniciada()` discards a mark older than that timestamp.

### UI

- The Hangup button's amber **Cancel transfer** / green **Complete transfer**
  cues now apply to callback logins too; the `isAgentPoolType` suppression
  added in #59 and the `IS_AGENT_TYPE` template variable are removed.
- Busy and Do Not Disturb refusals surface as an immediate error notice
  through the existing ECCP failure path, before any consultation is placed.

### Also

- `[atxfer-hold]` bounded its music on hold at 30 minutes followed by
  `Hangup()`. It previously never returned, so a customer whose consultation
  collapsed in an unhandled way waited there indefinitely holding a channel.
  Applies to both agent types.
- `[cbext-atxfer]` is now emitted only for Asterisk 11/13, where the native
  `Atxfer` fallback is still used.

### Files affected

- `setup/installer.php` → `/etc/asterisk/extensions_custom.conf`: new
  `[cbxfer-consult]`, `[cbxfer-consult-answered]`, `[cbxfer-cancel-consult]`
  and `[cbxfer-done]`; `[atxfer-hold]` bounded; `[cbext-atxfer]` moved to the
  Asterisk 11/13 branch.
- `setup/dialer_process/dialer/ECCPConn.class.php` → `/opt/issabel/dialer/`:
  callback branch of `Request_agentauth_atxfercall()` rewritten as a dual
  `Redirect()`; new `_verificarColegaDisponible()`; callback
  attended-transfer block of `Request_agentauth_hangup()` given the
  ringing/answered split.
- `setup/dialer_process/dialer/AMIEventProcess.class.php` →
  `/opt/issabel/dialer/`: `_consultaTerminadaEn` ordering guard;
  `_manejarHangupLoginChannelEnConsulta()` and
  `_terminarConsultaSiClienteCuelga()` made agent-type aware;
  `_ejecutarLogoffAgente()` clears the consultation maps.
- `modules/agent_console/{index.php,themes/default/agent_console.tpl,
  themes/default/js/javascript.js}` → `/var/www/html/modules/agent_console/`:
  `isAgentPoolType` suppression removed.

No change was needed in `AMIClientConn.class.php` (`Redirect`, `SetVar`,
`Hangup`, `ExtensionState` and `database_get()` were all already available)
or `ECCPProxyConn.class.php` (its `ConsultationStart/Answered/End`
notifications were already agent-type agnostic).

### Test steps

Callback agent (`SIP/101`) on a live call, "Attended transfer" → colleague:

1. Colleague answers → **Complete transfer** → colleague and customer stay
   bridged, agent released and free for the next call.
2. **Cancel transfer** while ringing → agent reconnected, customer **not**
   dropped.
3. Complete by hanging up the phone instead of the button → same as 1.
4. Colleague busy with Call Waiting off, or on DND → refused instantly, no
   leg placed, their existing call untouched.
5. Colleague declines / never answers / unavailable → auto-reconnect with the
   matching notice; the next hangup ends a normal call and is not recorded
   as a transfer.
6. Customer hangs up mid-consultation → consultation torn down, no orphaned
   channel.
7. Agent's device dies mid-consultation → agent stays logged in.

Verified live end-to-end on inbound queue calls, together with the Change #59
Agent-type scenarios as a regression pass. `_verificarColegaDisponible()` and
the ordering guard were additionally unit-tested against the live Asterisk
(real `InUse`, `Ringing` and `Unavailable` device states, and both Call
Waiting settings), and `setup/installer.php` was confirmed to regenerate
`/etc/asterisk/extensions_custom.conf` byte-identically.

```bash
grep -E "cbxfer|ConsultationAnswered|ConsultationEnd UserEvent received|CANCELAR CONSULTA|COMPLETAR TRANSFERENCIA|_verificarColegaDisponible|ignoring late consultation mark" /opt/issabel/dialer/dialerd.log
grep "consultation state resynced" /var/log/callcenter-module/debug.log
```

### Not yet verified

Outgoing campaigns have since been tested and behave correctly, including the
deferred finalization that writes `end_time`, `duration` and `status` on
`calls` (incoming takes the `datetime_end`/`terminada` branch instead, so that
path had never been exercised).

One case remains unverified: a **predictive** campaign agent who had a
reservation when a transfer completed. `llamadaTransferidaDesdeAgente()`
(`Llamada.class.php:1130`) — the lightweight release every completed transfer
goes through — omits the `$a->reservado` →
`msg_CampaignProcess_verificarFinLlamadasAgendables()` call and the
`agente_agendado` un-pause that `llamadaFinalizaSeguimiento()` performs, so
such an agent could in principle be left `reservado` and skipped for the next
campaign call. The omission is pre-existing and shared with blind transfer and
transfer-to-agent, not introduced here. If it does show up, the fix is to add
the same reservation-release block to `llamadaTransferidaDesdeAgente()`.

---

## 59. Attended Transfer for Agent Type Login
**Date**: 2026-08-28

Resolves the "Attended Transfer for Agent Type Login" TODO (High, added
2026-03-09). Change #16 had hidden the "Attended transfer" radio button from
Agent-type (`app_agent_pool`) logins over "known edge cases"; those edge
cases are fixed below and the option is re-enabled.

### Bugs fixed

- **Stranded customer / lost call tracking.** The consultation never went
  through `llamadaEnviadaHold()`, so `uniqueidlink` still pointed at the
  agent's `login_channel` for the whole consultation. Any real hangup of the
  agent's phone — including the intended "hang up to complete" path — was
  matched as a customer hangup, finalizing the call in the DB while the
  customer was still live in `[atxfer-hold]`'s infinite MOH loop. No watchdog
  existed, so the customer stayed there indefinitely, holding a trunk slot.
  New `msg_Hangup()` branch (`_manejarHangupLoginChannelEnConsulta()`) now
  handles this, releasing without finalizing when the colleague had already
  answered so the ongoing colleague↔customer call still finalizes with its
  real duration.
- **Stale `transfer` column.** `_registrarTransferencia()` stamps
  `transfer=<exten>` when the consultation *starts*, and nothing cleared it
  when the consultation failed (busy/no-answer/declined/colleague hung up
  first — no code branched on `${DIALSTATUS}` anywhere). The agent's next,
  entirely normal hangup was then misdetected as "transfer completed": the
  customer was hung up and the call recorded as transferred to a colleague
  who never took it. New `_limpiarTransferPendiente()` clears it on every
  natural consultation end.
- **No "colleague answered" signal.** Nothing detected the consult leg
  answering, so `isInConsultation` stayed true for the entire consultation
  and clicking Hangup mid-conversation always cancelled. Completing a
  transfer from the console was impossible — only a literal phone hangup did
  it. New `U(atxfer-consult-answered^...)` gosub emits a
  `ConsultationAnswered` UserEvent at answer time, carrying the colleague's
  channel, which feeds a new dual-`Redirect()` "complete transfer" path.
- **Forced agent logoff mid-transfer.** The periodic `Agents` reconciliation
  saw `AGENT_LOGGEDOFF` (legitimate but temporary — the login channel is
  deliberately redirected out of the `AgentLogin()` bridge) and force-logged
  the agent off, leaving them stuck "oncall" and unable to log back in until
  the transferred call ended. Now skipped while a transfer is in progress,
  and `_ejecutarLogoffAgente()` finalizes the call if its `Hangup` is
  rejected, so the agent can never be left wedged.
- **Attended-transfer hold ignored the queue MOH.** `[atxfer-hold]` hardcoded
  `MusicOnHold(default)`, overriding the `CHANNEL(musicclass)` the queue had
  set. Now `MusicOnHold()`, which inherits it.

### UI

- Re-enabled the "Attended transfer" option for Agent-type logins
  (`{if !$IS_AGENT_TYPE}` guard removed).
- The Hangup button reflects what it will do: amber **Cancel transfer**
  while the colleague rings, green **Complete transfer** once they answer,
  reverting afterwards. Suppressed for callback-type logins, where that path
  makes no ringing/answered distinction and hanging up mid-consultation
  disconnects the customer (see the "Attended Transfer for Callback Type
  Login" TODO).
- A transient notice reports why a consultation ended by itself — busy, no
  answer, or unavailable — from the consult `Dial()`'s `${DIALSTATUS}`.

### Event-loss resync (the reason the UI cues are reliable)

`ConsultationStart/Answered/End` reach only the ECCP clients connected at
that instant, and a consultation ending usually coincides with a burst of
other events that leaves the console's long poll mid-reconnect — so the
event was frequently dropped, most reliably with a busy colleague, leaving
the button stuck until a page reload. The dialer now reports the
consultation state (`none`/`ringing`/`answered`) and the last failure
`DIALSTATUS` in the agent status the console already polls, and
`manejarSesionActiva_checkStatus()` reconciles and synthesizes the missing
event — the same pattern already used for the break and hold states. A
consultation flag is reported only while the agent still has an active call,
so a leaked flag (possible on the callback path) can never wedge the button.

### Also

- Removed dead debug code in `Llamada::llamadaFinalizaSeguimiento()` that
  rewrote `/var/www/html/modules/agent_console/archivo.txt` on every hangup
  in the system and re-sent a duplicate `sqlupdatecalls`. Nothing read it.

### Files affected

- `setup/installer.php` → `/etc/asterisk/extensions_custom.conf`:
  `[atxfer-consult]` gains `/n` + `U(atxfer-consult-answered^...)` and
  `Status: ${DIALSTATUS}` on its `ConsultationEnd`; new
  `[atxfer-consult-answered]`; `[atxfer-hold]` MOH fix.
- `setup/dialer_process/dialer/AMIEventProcess.class.php` →
  `/opt/issabel/dialer/`: consultation-answered tracking + RPC,
  `_manejarHangupLoginChannelEnConsulta()`, `_limpiarTransferPendiente()`,
  `_estadoConsultaAgente()`, `AgentsComplete` guard, `_ejecutarLogoffAgente()`
  hardening.
- `setup/dialer_process/dialer/ECCPConn.class.php` → `/opt/issabel/dialer/`:
  dual-`Redirect()` complete-transfer branch; consultation state/reason in
  `getagentstatus`.
- `setup/dialer_process/dialer/ECCPProxyConn.class.php` →
  `/opt/issabel/dialer/`: `notificarEvento_ConsultationAnswered()`,
  reason on `ConsultationEnd`.
- `setup/dialer_process/dialer/Llamada.class.php` → `/opt/issabel/dialer/`:
  dead-code removal.
- `modules/agent_console/{index.php,libs/paloSantoConsola.class.php}` and
  `themes/default/{agent_console.tpl,js/javascript.js,css/issabel-callcenter.css}`
  → `/var/www/html/modules/agent_console/`: event plumbing, state resync,
  button cues, notices.

### Test steps

Agent-type agent on a live call, "Attended transfer" → colleague extension:

1. Colleague answers → click **Complete transfer** → colleague and customer
   bridged, agent back to Ready with its session preserved.
2. Click **Cancel transfer** while ringing → agent reconnected to customer.
3. Colleague busy / no answer / unavailable → agent auto-reconnected, notice
   shown, button reverts; the *next* hangup ends a normal call and is not
   recorded as a transfer.
4. Customer hangs up mid-consultation → consultation torn down, no orphaned
   channel.
5. Two concurrent customers → completing the first leaves it running between
   colleague and customer while the agent takes the next call.
6. Confirm the held customer hears the queue's MOH class, not `default`.

Verified live end-to-end against a real external customer, agent `Agent/1001`
and colleagues `102`/`103`, including a ~2.5-minute conversation before
completing; `call_entry` rows confirmed `transfer`, `id_agent`, `status` and
`duration` correct.

```bash
grep -E "ConsultationAnswered|ConsultationEnd UserEvent received|CANCELAR CONSULTA|COMPLETAR TRANSFERENCIA|login_channel hangup during atxfer-consult|reporta AGENT_LOGGEDOFF pero esta en transferencia|Forced agent logoff" /opt/issabel/dialer/dialerd.log
grep "consultation state resynced" /var/log/callcenter-module/debug.log
```

### Known limitation

Completing a transfer to a plain extension finalizes call tracking at the
hand-off moment, so the recorded duration excludes the colleague↔customer
conversation. This is the pre-existing behaviour described by the "Transfer
Agent Attribution" TODO and is unchanged here.

---

## 58. Outgoing Campaigns List Improvements
**Date**: 2026-08-28

Three related improvements to the `campaign_out` (outgoing campaigns) list
grid: a new **Purge Pending Calls** action, no-selection feedback for all
per-row actions, and an activity-status guard on campaign deletion.

### Purge Pending Calls (new feature)

Resolves the "Campaign Purge Pending Calls" TODO (Medium, added 2026-03-09).
The module had no way to clear a campaign's pending calls without direct
database operations. The new button deletes all never-originated calls of
the selected campaign, leaving the campaign itself (and every call that was
actually dialed) untouched.

**Design decisions** (informed by the dialer code, see also the related
"Campaign Deletion Not Coordinated With Dialer" TODO):

- **Scope = `status IS NULL` only.** A `NULL`-status call has never been
  originated: `CampaignProcess` marks a call `'Placing'` in the same
  statement sequence that issues `Originate()` (`CampaignProcess.class.php`)
  and no code path ever reverts an originated call back to `NULL` (dialer
  startup recovery sets orphaned `Placing`/`Ringing`/`OnQueue` → `Failure`
  and `OnHold`/connected-`Success` → `Hangup`; `_cleanOrphanedPlacingCalls`
  sets `Failure`). Retry-eligible failed calls (`Failure`, `NoAnswer`,
  `ShortCall`, `Abandoned`) are therefore never purged, preserving call
  history and retry counts.
- **Guard: campaign must be Inactive (`campaign.estatus = 'I'`).** This
  eliminates even the narrow single-cycle race with `CampaignProcess`, which
  only selects/places calls of campaigns it considers active.
- **Child-table cleanup limited to `call_attribute`.** Of the five tables
  with FKs into `calls`, only `call_attribute` has rows for a `NULL`-status
  call (written at CSV contact-load time, `paloContactInsert.class.php`).
  `call_progress_log`, `call_recording`, `form_data_recolected` and
  `current_calls` only gain rows after `Originate()`. Since no FK uses
  `ON DELETE CASCADE`, `call_attribute` must be deleted first (otherwise the
  `calls` DELETE fails with FK error 1451); the other four provably have no
  rows for the purged set and are omitted.

### No-selection feedback for all row actions (bug fix)

Previously, clicking **Purge Pending Calls**, **Delete** or **Change
Status** without selecting a campaign radio button gave no feedback: the
purge and delete buttons still showed their confirm dialog and then silently
reloaded the page, and Change Status silently reloaded — because the
handlers were gated on `!is_null($id_campaign)` and an unchecked radio posts
no `id_campaign` at all, so the guard simply fell through.

### Delete guarded on activity status (new guard)

`delete_campaign()` could delete a campaign in any state, including
**Active** — the exact hot-deletion scenario behind the "Campaign Deletion
Not Coordinated With Dialer" TODO, where removing a campaign with in-flight
calls poisons the dialer's `SQLWorkerProcess` action queue (lost
`call_progress_log` rows). Deleting is now refused unless the campaign is
**Inactive (`I`) or Finished (`T`)**. This partially mitigates that TODO
(refuses the common hot-deletion case) but does not fully resolve it — an
Inactive campaign deactivated moments earlier could still have in-flight
calls, and no dialer notification is sent. The TODO entry remains open. The
same unguarded pattern also exists in `campaign_in`'s
`paloSantoIncomingCampaign::delete_campaign()` and was left unchanged.

### Implementation

- `modules/campaign_out/libs/paloSantoCampaignCC.class.php`:
  - New `purge_pending_calls($idCampaign)` — validates the campaign exists
    and is Inactive, then runs a two-statement transaction (`DELETE FROM
    call_attribute WHERE id_call IN (SELECT id FROM calls WHERE
    id_campaign=? AND status IS NULL)`, then `DELETE FROM calls WHERE
    id_campaign=? AND status IS NULL`), modeled on `delete_campaign()`.
  - `delete_campaign()` first reads `campaign.estatus` — a missing row fails
    with "Campaign not found", and `estatus = 'A'` fails with "Campaign must
    be inactive or finished to delete it" (both via `$this->errMsg`, so the
    GUI's existing delete-error branch displays them without further
    changes).
- `modules/campaign_out/index.php` (`listCampaign()`):
  - POST handler for `purge_pending` (mirrors the existing delete/activate
    handlers) and a `Purge Pending Calls` grid action button
    (`addSubmitAction` with JS confirmation, trash-o icon), reusing the
    radio-button campaign selection.
  - All three handlers (`delete`, `purge_pending`, `change_status`) now show
    a "You must select a campaign" message box (under "Delete Error" /
    "Purge Error" / new "Change Status Error" titles) when posted without a
    valid selection.
  - Client-side, a shared `$js_check_campaign` JS string is prepended to the
    Delete and Purge buttons' `onclick`: it checks that a campaign radio is
    checked and otherwise alerts and cancels before any confirm dialog
    appears. The `deleteList()` call was replaced by an `addHTMLAction()`
    button replicating `deleteList()`'s exact output (submit named
    `delete`, `fa fa-eraser` icon, red `#ec6459` styling) because
    `deleteList()` hardcodes its `confirmSubmit` onclick, accepts no custom
    one, and `arrActions` is private. The label keeps `_tr('Delete')`, which
    the framework's global lang already translates (es: "Eliminar"). No
    client-side check is possible for Change Status: the grid template's
    combo branch (`_list.tpl`) renders the combo's submit button without any
    onclick support, so the server-side message is the fix there.
- `modules/campaign_out/lang/{en,es}.lang`: 10 new key pairs — purge button
  label, confirm dialog, success/error messages, both purge guard errors,
  delete guard error, "You must select a campaign", and "Change Status
  Error" ("Campaign not found" shared by both guards).

### Verification performed

`php -l` clean on both PHP files; both lang files parse in `$arrLangModule`
(en + es) with every new key present. CLI harnesses driving the classes
directly plus SQL assertions (`/tmp/test_purge_pending.php` and
`/tmp/test_delete_guard.php`, 15/15 PASS each — the delete harness only
deletes campaigns it creates itself):

- **Purge**: refused on an Active campaign with "Campaign must be inactive
  to purge pending calls"; nonexistent id refused with "Campaign not found";
  on a disposable Inactive campaign populated with 2 `NULL` + 1 `Failure` +
  1 `Success` calls (each with a `call_attribute` row): only the `NULL`
  calls and their attributes were deleted, `Failure`/`Success` rows +
  attributes kept, campaign row survived, second purge idempotent.
- **Delete guard**: live Active campaign refused with the
  inactive-or-finished message and left untouched (row, status, calls);
  nonexistent id refused; disposable Active campaign refused and kept;
  disposable Inactive campaign (with `calls` + `call_attribute` rows) fully
  deleted; disposable Finished (`T`) campaign deletable; no test residue.
- **No-selection**: simulated POSTs — `delete`, `purge_pending` and
  `change_status` without `id_campaign` all route to their message branches;
  `delete` with a selection still routes to `delete_campaign()` exactly as
  before. The generated Delete-button HTML was checked for correct attribute
  quoting (double-quoted attributes, single-quoted JS strings), matching
  `_list.tpl`'s `{$accion.html}` raw-output branch in both `tenant` and
  `farsi_rtl` themes.
- **GUI** (user retest): purge guard and no-selection alert confirmed
  working; Delete without selection now alerts instead of
  confirm-then-nothing.
- Dialer stayed running throughout; `grep -E "ERR.*(1451|1452|23000|foreign
  key|call_progress_log)" /opt/issabel/dialer/dialerd.log` shows no FK
  errors.

---

## 57. Fix XSS in Agent Console Debug Function
**Date**: 2026-08-28

**Bug**: Resolves the "XSS in Debug Function" TODO (High, added 2026-03-07 /
Change #39). `_cc_debug_flush_html()` (in `issabel2.lib.php`, included by 31
web modules) collects debug messages — which can contain raw, attacker-
controlled `$_GET`/`$_POST` data (e.g. `agent_console/index.php`'s module-entry
logging: `_debug("module entry: $_GET = ...\n$_POST = ...")`) — and inlines
them as JS string literals into an inline `<script>...</script>` block
appended to the agent-console HTML page whenever `$GLOBALS['CALLCENTER_DEBUG']`
is enabled. The escaping was a hand-rolled `str_replace()` over 5 characters
(`\`, `'`, `\n`, `\r`, `</`) — fragile, non-standard, and only correct by
careful (undocumented) ordering rather than any verifiable guarantee.

**Verification performed** (live box, `CALLCENTER_DEBUG` enabled, real logged-
in agent-console session):
- Confirmed end-to-end reachability with a real HTTP request carrying an XSS
  payload in `$_GET` — it was reflected into the debug `console.log()` output
  as expected.
- Manually traced the old 5-item `str_replace` (incl. PHP's documented
  array-replace cascading-substitution pitfall) and tested a payload matrix
  (script-tag breakout, quote/backslash mixes, mixed-case `</SCRIPT>`, a
  `U+2028` line separator, and a benign message) against both the old and new
  code. Found no working bypass of the *old* code with this deployment's
  config (page is confirmed UTF-8 via `AddDefaultCharset UTF-8` + the page's
  own `<meta charset=utf-8>`, which rules out the classic multi-byte-charset
  trailing-byte bypass class this style of manual escaping is normally
  vulnerable to) — so this is hardening of a real anti-pattern rather than a
  fix for an actively pop-alert-today exploit; still correctly filed as a
  security bug given how easily this class of hand-rolled escaping breaks.
- Confirmed only 4 call sites exist, all in `agent_console/index.php` (lines
  292, 942, 1052, 1075), always `... . _cc_debug_flush_html()`; nothing
  parses the console output client-side, so the return contract (string,
  `<script>...console.log(...);...</script>`) is unchanged and all call
  sites keep working — the benign-message case logs identically before/after.
- `php -l` clean on both the live and repo copies after the edit.

**Fix**: Replaced the manual escaping with `json_encode()` (flags
`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT |
JSON_UNESCAPED_UNICODE`), with a safe placeholder fallback if encoding fails
(e.g. invalid UTF-8 in a message). This is strictly stronger than the old
code: `<`/`>` are hex-escaped outright (old code left raw `<` characters,
relying only on `/`-escaping) and the `U+2028`/`U+2029` gap is closed.

**Files affected**:
- `modules/agent_console/libs/issabel2.lib.php` — `_cc_debug_flush_html()`
  (also applied to the live served copy at
  `/var/www/html/modules/agent_console/libs/issabel2.lib.php`)

---

## 56. Asterisk Restart Detection in CampaignProcess
**Date**: 2026-08-28

**Feature**: Resolves the "Asterisk Restart Detection" TODO (Critical, pre-2011). When Asterisk restarts while the dialer keeps running, it forgets every in-progress call and logged-in agent, but `CampaignProcess` had no way to know that had happened — the reconnect-succeeded branch of `procedimientoDemonio()` was a stub `TODO` comment. In practice this meant `calls` rows left in `Placing`/`Ringing`/`Success`(connected)/`OnHold` from before the restart kept counting against `max_canales` for up to 5 minutes (`_cleanOrphanedPlacingCalls()`) or 2 hours (`_cleanOrphanedConnectedCalls()`) — their normal timeout-based sweep — and stale agent-login state in the DB was never re-verified.

`AMIEventProcess.class.php` already solves the equivalent problem for the calls/agents *it* tracks, by polling `CoreStatus` for `CoreStartupDate`/`CoreStartupTime` on every AMI reconnect and comparing against the previously seen value. `CampaignProcess` holds an independent AMI socket and reconnects on its own, so it needed the same detection.

**Fix**: Ported the `CoreStatus`-based restart-detection technique into `CampaignProcess::_iniciarConexionAMI()` (new `_asteriskStartTime`/`_bReinicioAsterisk` fields, mirroring `AMIEventProcess`). When a restart is detected on reconnect, `procedimientoDemonio()` now:
- Calls `_cleanOrphanedPlacingCalls(0)` and `_cleanOrphanedConnectedCalls(0)` — both helpers gained an optional timeout-override parameter (default unchanged: 300s / 7200s for the normal periodic sweep) so a confirmed restart flushes every such call immediately instead of waiting for the timeout, since none of them can possibly still be in progress.
- Sends `msg_SQLWorkerProcess_requerir_nuevaListaAgentes()` (the same async message `AMIEventProcess` already sends after its own reconnect/`Reload`) to force a fresh, authoritative check of which agents are actually still logged in.

No new inter-process message type was needed — `CampaignProcess` and `AMIEventProcess` already reconnect to AMI independently, so each detects the same physical restart on its own.

**Files affected**:
- `setup/dialer_process/dialer/CampaignProcess.class.php` — `_iniciarConexionAMI()` restart detection; `procedimientoDemonio()` reconnect-succeeded branch; `_cleanOrphanedPlacingCalls()`/`_cleanOrphanedConnectedCalls()` gained an optional timeout parameter.

**Log collection**:
```bash
# Restart detected and handled
grep -E "Asterisk fue reiniciado|Asterisk was restarted|esta instancia de Asterisk ha sido reiniciada" /opt/issabel/dialer/dialerd.log | tail -20

# Immediate cleanup right after a restart (not after 5min/2h)
grep -E "cleaned .* orphaned (Placing/Ringing|connected) calls" /opt/issabel/dialer/dialerd.log | tail -20
```

---

## 55. Fix Stuck Call When Parked Caller Hangs Up On Hold
**Date**: 2026-06-03

**Bug**: A call could remain stuck on an agent indefinitely (agent locked to a dead call; `current_call_entry` row never cleared, `call_entry.status` left as `activa` with `datetime_end=NULL`).

**Root cause**: When an agent puts a call on hold, the dialer parks the customer's channel. If the parked caller hangs up via a plain `Hangup` event rather than `ParkedCallGiveUp`, `AMIEventProcess::_procesarLlamadaColgada()` hit the `status == 'OnHold'` branch and unconditionally **ignored every Hangup**, so the call was never finalized. The dedicated `msg_ParkedCallGiveUp()` finalization path was bypassed entirely.

The trigger in the field: FreePBX parking `parkingtime` (45s, default lot, `comebacktoorigin=no`) expires and tries to return the parked call via `[park-dial]` → `Dial(${PARK_TARGET})`. Because the dialer parks the customer trunk leg directly, `PARK_TARGET` flattens to the trunk peer name (e.g. `SIP/WE`), so the return dial fails with `Cause 28 "Invalid number format"` and Asterisk hangs up the parked channel with a regular `Hangup`. The same leak also applies to a trunk BYE or network drop of a parked channel.

**Fix**: In the `OnHold` branch of `_procesarLlamadaColgada()`, if the Hangup is for the parked caller's own channel (`$params['Channel'] == $llamada->actualchannel`) and the call is not in an attended-transfer unhold (`!$llamada->atxfer_hold`), finalize the call exactly as `msg_ParkedCallGiveUp()` does — `llamadaRegresaHold()` then `llamadaFinalizaSeguimiento()`. Hangups for any other channel during HOLD (the agent leg, the failed `park-dial` auxiliary channel) are still ignored, and attended-transfer unhold behavior is unchanged.

**Files affected**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php` — `_procesarLlamadaColgada()` OnHold branch now finalizes a genuine parked-caller hangup instead of swallowing it.

**Related (not part of this code change)**: parking `parkingtime` was raised 45s → 300s on the live system as an interim mitigation; this only widens the window before the broken park-return drops the caller — the code fix above is what actually prevents the orphaned row. A cleaner follow-up is to give dialer holds a dedicated parking lot with no timeout (retrieval is dialer-driven), since FreePBX park-return cannot return the call to the agent here.

---

## 54. SQLWorkerProcess Pending-Action Queue Deadlock (Agent Console Freeze)
**Date**: 2026-08-13

**Problem**: A single database action that could never succeed permanently blocked the dialer's pending-action queue, freezing every agent console. Observed symptoms: after accepting the login call the console stayed on the login page instead of switching to the session page, and incoming call data never appeared — both only recovered with a manual page refresh, and fully only after a dialer restart.

`SQLWorkerProcess::_procesarUnaAccion()` removed an action from `$_accionesPendientes` with `array_shift()` **only on the success path**. The `catch (PDOException $e)` block logged and rolled back but never removed the failing action, so a permanently-failing action was retried forever at the head of a strict FIFO queue (`_encolarAccionPendiente()` uses `array_push`). Because `_lanzarEventos()` is only reached after a successful `commit()`, no ECCP event was ever emitted — and `_AgentLogin`, `_AgentLinked`, `_agentStateChange` and `_notificarProgresoLlamada` all share that one queue, which is why agent state and call data both stopped updating.

The trigger was `campaign_out`'s `delete_campaign()`, which deletes `call_progress_log`, `calls` and `campaign` rows in one transaction with no coordination with the dialer. Campaign 3 was deleted while a call-progress action for `id_call_outgoing=60029` was still queued in memory, so the resulting `INSERT INTO call_progress_log` failed the `call_progress_log_ibfk_6` foreign key (`23000 - 1452`) on every retry. The queue stayed jammed for roughly 48 hours (action dated 2026-08-11 13:56:32, still retrying at 2026-08-13 13:43:11).

Two aggravating factors: `procedimientoDemonio()` passes `0` (no wait) to `procesarActividad()` whenever actions are pending, so the retry ran as a busy loop (~27,000 log lines/second); and `$e->getTraceAsString()` was interpolated **twice** into a single line (ES and EN). The log reached **50 GB in 9.5 hours** — 41% stack traces, 36% `_volcarAccion` dumps, 16% error text, 7% `LOGDATA`.

**Implementation**: The existing `esReiniciable()` predicate was only ever used to decide *whether to log* — never to gate the retry, which was universal by omission. Note that retaining a failed action is deliberate and load-bearing (it is what prevents losing call and audit records across a MySQL restart), so the fix preserves retention and adds the missing notion of a *permanently impossible* error rather than dropping on any failure.

Errors are now classified three ways, with a bounded backstop:

| Class | Detection | Queue action |
| --- | --- | --- |
| 1. Connection loss | driver codes 2002, 2003, 2006, 2013, 1053, 1040 | **Retain**, close handle, reconnect |
| 2. Transient contention | `esReiniciable()` — 1213, 1205 | **Retain**, retry immediately, no logging |
| 3. Permanent logical | SQLSTATE 23000, 42S22, 42S02, 42000 | **Discard**, log once with the payload |
| 4. Unclassified | anything else | Retry with exponential backoff, discard after `MAX_ACTION_RETRIES` |

Class 4 is deliberate: an unanticipated error is still retried (preserving "wait for it to succeed later" semantics) but can no longer wedge the queue permanently. Previously only code 2006 was recognised as connection loss, so a naive "drop when not retriable" fix would have **discarded queued call records whenever MySQL returned 2013/2003/1053 instead** — class 1 now covers all of them.

Two latent crash paths in the same catch block were fixed in passing:
- `$this->_db->rollBack()` was called unconditionally, but `_verificarCambioConfiguracion()` and `_verificarActualizacionAgentes()` run DB reads inside the same `try` and **outside any transaction**. `rollBack()` with no active transaction throws, and that second exception escaped the catch and would abort the process. Now guarded by `inTransaction()` inside its own `try`.
- `implode(' - ', $e->errorInfo)` and `$e->errorInfo[0]` assumed an array, but PDO leaves `errorInfo` as `NULL` for exceptions it raises itself. New `infoErrorPDO()` normalizes it.

A `$bEjecutandoAccion` flag now distinguishes a failure inside the queued action from one raised by the periodic config/agent refresh that shares the `try`, so an innocent action that was never attempted is never discarded.

**Files affected**:
- `setup/dialer_process/dialer/ECCPHelper.lib.php` — new `infoErrorPDO()`, `esErrorConexion()`, `esErrorPermanente()`; `esDeadlockTransaccion()` and `esLockTimeout()` now read `errorInfo` through the NULL-safe accessor.
- `setup/dialer_process/dialer/SQLWorkerProcess.class.php` —
  - new constants `MAX_ACTION_RETRIES` (50), `BACKOFF_CAP_SECONDS` (30), `LOG_REPEAT_WINDOW_SECONDS` (60), `FAST_RETRIES_ON_CONTENTION` (5) and retry/log-suppression state. Class 2 retries immediately only for the first `FAST_RETRIES_ON_CONTENTION` attempts and then adopts the same backoff, so sustained contention cannot exhaust the retry cap in microseconds and discard a merely-contended action;
  - `catch` body extracted into `_manejarErrorAccion()` implementing the four-way dispatch;
  - `_hayAccionEjecutable()` gates both `procedimientoDemonio()`'s idle decision and action execution, so a backing-off queue idles at `procesarActividad(1)` instead of spinning;
  - `_debeRegistrarError()` suppresses identical repeats within the window and reports the suppressed count;
  - `_reiniciarEstadoReintento()` clears retry state on every successful commit;
  - `_volcarAccion()` gained a `$bForzar` argument so a discarded action's payload is logged even with debugging off, plus an `is_array()` guard;
  - stack trace emitted once instead of twice (bilingual label retained);
  - `limpiezaDemonio()` calls `_procesarUnaAccion(TRUE)` to bypass backoff so shutdown still drains within its 10 s budget and no longer hangs on a poison action;
  - the unconditional `LOGDATA: var_export($prop)` trace (marked `CUSTOMIZATIONS WC 05/08/2025`), which fired once per call state transition regardless of `dialer.debug`, is now gated behind `$this->DEBUG`.

**Not included**: `delete_campaign()` still deletes a campaign with no coordination with the dialer, so the poison action can still be generated — this change makes the consequence survivable, not impossible. Tracked in `TODO.md`.

**Log collection**:
```bash
# Queue jammed? (repeated identical FK errors) - should now be absent
grep -E "ERR:.*_procesarUnaAccion.*foreign key" /opt/issabel/dialer/dialerd.log | tail -20

# Classification outcomes
grep -E "acción descartada permanentemente|permanently discarded" /opt/issabel/dialer/dialerd.log
grep -E "acción descartada tras|action discarded after"           /opt/issabel/dialer/dialerd.log
grep -E "conexión a DB perdida|DB connection lost"                /opt/issabel/dialer/dialerd.log
grep -E "se suprimieron|suppressed"                               /opt/issabel/dialer/dialerd.log

# Runaway log growth (was ~1.5 MB/s during the jam)
watch -n5 'ls -l /opt/issabel/dialer/dialerd.log'

# Console updates arriving without a manual refresh
grep -E "AgentLogin|AgentLinked" /opt/issabel/dialer/dialerd.log | tail -20

# Shutdown must no longer need repeated SIGTERM
grep "no todas las tareas han terminado" /opt/issabel/dialer/dialerd.log
```

---

## 53. Popup External URL on Call Hangup
**Date**: 2026-05-14

**Feature**: Allow a campaign External URL to open **after the call hangs up** (on the `agentunlinked` event), in addition to the existing on-connect behavior. The three v5 URL slots were previously wired exclusively to call startup (`agentlinked`); this adds hangup-time delivery as a per-URL option. Ported from a v4 customization.

**Implementation**: Repurpose the `opentype` value instead of adding a 4th URL slot or changing the DB/ECCP schema. Four `_hangup` variants were added — `window_hangup`, `popup_hangup`, `iframe_hangup`, `jsonp_hangup` — that any of URL1/URL2/URL3 can use. They are parallel to the existing startup variants and preserve the v5 button-vs-auto-popup split:

| Startup opentype | Hangup opentype | Behavior |
| --- | --- | --- |
| `window` | `window_hangup` | Clickable button (agent opens manually) |
| `popup`  | `popup_hangup`  | Auto `window.open` on hangup |
| `iframe` | `iframe_hangup` | Embedded iframe tab |
| `jsonp`  | `jsonp_hangup`  | Background JSONP request |

Routing happens in the agent_console PHP backend: a `_hangup` URL is nulled out of the `agentlinked` response and emitted in the `agentunlinked` response instead, with the `_hangup` suffix stripped so the existing JS opener functions (`abrir_url_externo{,2,3}`) need no per-type changes. Non-hangup opentypes are unaffected.

This change also **increased three URL-template tokens** — `{__ANSWER__}` (`datetime_linkstart`), `{__END__}` (`datetime_linkend`), `{__DURATION__}` (`duration`) — and added `isset()` guards to every token in `construirUrlExterno()` so a missing field yields `''` instead of a PHP notice. The `agentunlinked` event delivers field names `call_type` / `call_id` / `phone`, while `construirUrlExterno()` expects `calltype` / `callid` / `callnumber`; `construirRespuesta_agentunlinked()` now merges the normalized `$callinfo` into `$infoLlamada` to bridge that naming gap.

**Files affected**:
- `modules/external_url/index.php` — `descOpenType()` now also returns the four `_hangup` entries so admins can pick them in the External URL form. No DB schema change (`campaign_external_url.opentype` is `varchar(16)`).
- `modules/external_url/libs/externalUrl.class.php` — the four `_hangup` values added to the `in_array()` opentype whitelist in both `createURL()` and `updateURL()`; without this the form save fails with `Validation Error (internal) Invalid URL open type`.
- `modules/agent_console/index.php` —
  - new helpers `_esOpentypeHangup()` / `_opentypeBase()` near `construirUrlExterno()`;
  - `construirRespuesta_agentlinked()` nulls out any slot whose opentype is a `_hangup` variant so it does not pop at startup;
  - `construirRespuesta_agentunlinked()` rewritten (was near-empty) to take `$smarty, $sDirLocalPlantillas, $oPaloConsola, $callinfo, $infoLlamada, &$infoCampania`, build the `_hangup` slots, strip the suffix, and substitute tokens;
  - both call sites updated — the initial-state path loads `$infoCampania` / `$infoLlamada` from the client's last-known state, the event-loop path builds `$nuevoEstado` from the `agentunlinked` event;
  - `construirUrlExterno()` restored `{__ANSWER__}` / `{__END__}` / `{__DURATION__}` and added `isset()` guards to all tokens.
- `modules/agent_console/themes/default/js/javascript.js` — the `case 'agentunlinked':` branch now calls `abrir_url_externo{,2,3}()` after the existing teardown; a null opentype is mapped to `"DELETE"` so a stale startup tab/button for that slot is removed.

**Known limitation**: The `agentunlinked` event from the dialer (`SQLWorkerProcess.class.php::_AgentUnlinked()`) carries `datetime_linkend`, `duration`, `call_type`, `campaign_id`, `call_id`, `phone` — but **not** `datetime_linkstart`, `uniqueid`, or `remote_channel`. So `{__ANSWER__}`, `{__UNIQUEID__}`, `{__REMOTE_CHANNEL__}` resolve to `''` in a hangup URL unless the dialer is also extended to include them in the event payload.

**Log collection**:
```bash
tail -f /var/log/httpd/ssl_error_log | grep -i 'agent_console\|external\|url\|opentype\|construirUrl'
tail -f /opt/issabel/dialer/dialerd.log | grep -i 'agentunlinked\|url\|opentype'
```

---

## 52. Auto-Popup External URL Option
**Date**: 2026-05-11

**Problem**: The original (v1) call center auto-opened the campaign External URL on call connect via `window.open(url, '_blank')`. v5 added support for up to three URLs per campaign and replaced auto-popup with a click button to avoid popup-blocker storms — but this removed the auto-popup behavior some deployments still need.

**Solution**: Added a new `opentype` value `popup` ("Auto popup") alongside the existing `window` / `iframe` / `jsonp`. URLs configured with `opentype = popup` are auto-opened the moment the call connects, restoring v1 behavior. The click button is still rendered so the agent can re-open the URL if the browser blocked the popup or they closed the window.

`'popup'` was also added to the opentype whitelist in `externalUrl.class.php::createURL()` / `updateURL()` — without it the form save failed with `Validation Error (internal) Invalid URL open type` because the dropdown was updated but the DB-write whitelist wasn't.

**Files affected**:
- `modules/external_url/index.php` — `descOpenType()` now includes `'popup' => _tr('Auto popup')`. No DB schema change (the existing `campaign_external_url.opentype` column is `varchar(16)`).
- `modules/external_url/libs/externalUrl.class.php` — `'popup'` added to the `in_array()` whitelist in both `createURL()` and `updateURL()`.
- `modules/agent_console/themes/default/js/javascript.js` — `abrir_url_externo()`, `abrir_url_externo2()`, `abrir_url_externo3()` now treat `'popup'` like `'window'` (build the button + click handler) and additionally fire `window.open(url, '_blank')` immediately for `'popup'`.

**Test steps**:
1. Go to **Call Center → External URLs → New URL**. Confirm the "Open URL in" dropdown shows four options including **"Auto popup"**.
2. Create a URL `https://www.example.com/{__PHONE__}` with opentype = **Auto popup**. Save succeeds (no validation error). Assign it to an outgoing campaign as URL 1.
3. Log in as an agent, place/receive a call on that campaign. Expected: a new browser tab opens automatically AND the labeled button appears in the console tab bar.
4. Set another URL on the same campaign to opentype = **New window**. Confirm it shows the button but does NOT auto-popup.
5. Set a third URL to **Embedded frame**. Confirm iframe tab still renders correctly.

**Log collection**:
```bash
tail -f /var/log/httpd/ssl_error_log | grep -i 'external\|url\|opentype'
tail -f /opt/issabel/dialer/dialerd.log | grep -i 'url\|opentype'
```

**Notes**: Browser popup blockers may still block the auto-popup since it isn't tied to a user gesture. Agents may need to whitelist the agent-console origin. The button is intentionally kept visible to cover this case. **Do not configure the popup URL to point back at the Issabel host** — the popup tab would load the agent console, see the same active call, and fire another popup recursively. Always use an external CRM/web URL.

---

## 51. Remove Webphone from Agent Console
**Date**: 2026-04-09

**Problem**: Webphone integration was embedded in the agent console, tightly coupling it to a specific WebRTC client (mhrgl.com) and requiring the external webphone module.

**Solution**: Removed all webphone-related code from agent console:
- `modules/agent_console/index.php` — removed webRTC detection, `$webphonePassword`/`$webphoneName` globals, SIP transport query, localStorage credential injection
- `modules/agent_console/themes/default/agent_console.tpl` — removed webPhone container, panel, and JS repositioning
- `modules/agent_console/themes/default/css/issabel-callcenter.css` — removed webphone CSS classes and responsive media queries for `.right-container`

**Test steps**:
1. Login as agent in console
2. Verify no JavaScript errors in browser console
3. Verify layout renders full-width without right panel gap
4. `grep -ri "webphone\|webRTC\|webPhone\|mhrgl" /var/log/httpd/ssl_error_log | tail -20`

---

## 50. Predictor AMI Enumeration Timeout (Startup Race Condition)
**Date**: 2026-03-28

**Problem**: Dialer stopped placing calls entirely after service restart. The CampaignProcess froze permanently on its first campaign review cycle, blocking all call placement. This occurred when the dialer service started before Asterisk AMI was fully ready.

**Root Cause**: Race condition on first campaign cycle after dialer restart. The CampaignProcess connected to AMI and immediately asked AMIEventProcess for queue prediction info. But AMIEventProcess had just connected to AMI at the same second and hadn't populated its QueueShadow yet, returning "queue not found". The CampaignProcess then fell into a Predictor fallback path that sent `CoreShowChannels`/`QueueStatus` AMI commands and waited for response events in `_esperarEnumeracion()`. This wait loop had **no timeout** — if the AMI response events were not received or not matched, the process hung in `select()` indefinitely (observed 1.5+ hours). The HubProcess did not detect the hang because the process was still alive (just blocked).

**Solution**: Added a 10-second timeout to `_esperarEnumeracion()` in `Predictor.class.php`. On timeout, the method logs a warning and returns `FALSE`. The callers in `examinarColas()` check the return value — on `FALSE`, they clean up event handlers and return `FALSE` to the caller. This causes `$queueInfo` to remain `NULL` in `_actualizarCampanias()`, gracefully skipping call placement for that cycle. On the next cycle (3 seconds later), the QueueShadow is populated and the Predictor fallback is not triggered.

**Files**:
- `setup/dialer_process/dialer/Predictor.class.php`

**Changes**:
- `_esperarEnumeracion()` (line 117): Added `$iTimeoutStart = time()` before the loop and a 10-second timeout check inside the loop. Returns `FALSE` on timeout with a WARN log, `TRUE` on success.
- `examinarColas()` (lines 78-85): Check `_esperarEnumeracion()` return after `CoreShowChannels` — on `FALSE`, clean up handlers and return `FALSE`
- `examinarColas()` (lines 89-96): Check `_esperarEnumeracion()` return after `QueueStatus` — on `FALSE`, clean up handlers and return `FALSE`

**Log Collection**:
```bash
# Check if timeout was triggered (indicates Asterisk/AMI startup race)
grep "timeout.*AMI enumeration" /opt/issabel/dialer/dialerd.log | tail -10

# Verify CampaignProcess is running cycles normally after restart
grep -E "CAMPAIGN_ALLOC|Pass 1 COMPLETE" /opt/issabel/dialer/dialerd.log | tail -10

# Verify CampaignProcess is not frozen (should show recent timestamps)
grep "CampaignProcess.*CAMPAIGN REVIEW CYCLE" /opt/issabel/dialer/dialerd.log | tail -5
```

---

## 49. Comprehensive Orphaned Call Cleanup (All Active Statuses)
**Date**: 2026-03-19

**Problem**: Outgoing campaigns stopped placing calls because orphaned `Success` calls with `end_time IS NULL` from previous dialer sessions (22+ days old) inflated `_countActiveCalls()`, consuming the entire channel budget (`effective_max = 0`). This was a gap exposed by Change #47 — it added `Success` calls to the active count but Change #46's cleanup only handled `Placing` status.

**Root Cause**: The startup cleanup from Change #46 only handled `Placing` calls. All other pre-hangup statuses (`Ringing`, `OnQueue`, `OnHold`, `Success` with NULL end_time) could become orphaned if the dialer crashed/restarted while calls were in progress. No runtime cleanup existed for connected calls.

**Solution**: Implemented comprehensive two-layer cleanup:

**Startup Cleanup** (runs once at dialer start):
- Extended `Placing` cleanup to include `Ringing` and `OnQueue` → mark as `Failure`
- Added new cleanup for `OnHold` and `Success` (NULL end_time) → mark as `Hangup` with `end_time = start_time`

**Runtime Cleanup** (runs every campaign cycle):
- Extended `_cleanOrphanedPlacingCalls()` to include `Ringing` (5-minute timeout)
- Added new `_cleanOrphanedConnectedCalls()` for `Success`/`OnHold` with NULL end_time (2-hour timeout)

**Files**:
- `setup/dialer_process/dialer/CampaignProcess.class.php`

**Changes**:
- Extended startup cleanup from `status = 'Placing'` to `status IN ('Placing', 'Ringing', 'OnQueue')` (lines 159-163)
- Added startup cleanup for connected calls `OnHold` and `Success` with NULL end_time (lines 169-187)
- Extended runtime cleanup from `status = 'Placing'` to `status IN ('Placing', 'Ringing')` (line 2420)
- Added `_cleanOrphanedConnectedCalls()` method for runtime connected call cleanup (lines 2446-2472)
- Added call to `_cleanOrphanedConnectedCalls()` in `_actualizarCampanias()` (line 456-458)

**Log Collection**:
```bash
# Verify orphaned calls were cleaned at startup
grep -E "orphaned.*(Placing|Ringing|OnQueue|OnHold|Success|connected)" /opt/issabel/dialer/dialerd.log | tail -20

# Monitor active call counts after fix
grep -E "_countActiveCalls|channel_budget|calls_to_place" /opt/issabel/dialer/dialerd.log | tail -50

# Verify no remaining orphaned calls in database
mysql -u root -p$(grep mysqlrootpwd /etc/issabel.conf | cut -d= -f2) call_center -e "
SELECT status, COUNT(*) as count FROM calls
WHERE status IN ('Placing','Ringing','OnQueue','OnHold')
   OR (status = 'Success' AND end_time IS NULL)
GROUP BY status;"
```

---

## 48. Outgoing Campaign Panel Trunk Display Delay
**Date**: 2026-03-10

**Problem**: Trunk column in Outgoing Campaign Panel showed blank until Asterisk channel was created. Users had to wait or refresh to see the trunk name.

**Root Cause**: `marcarLlamada()` passed trunk to progress notification but never stored it on the `Llamada` object (`$this->_trunk`). When panel polled `resumenLlamada()`, it returned NULL for trunk until channel was created and trunk was derived from channel name.

**Solution**:
1. Set `$this->_trunk` in `marcarLlamada()` so trunk is available immediately when call enters "Placing" status
2. Include trunk in `computeStateHash()` fingerprint so late trunk changes trigger frontend SSE update

**Files**:
- `setup/dialer_process/dialer/Llamada.class.php`
- `modules/rep_outgoing_campaigns_panel/index.php`

**Changes**:
- Added trunk assignment after `$paramProgreso` definition in `marcarLlamada()` (lines 604-608)
- Added trunk to active calls fingerprint in `computeStateHash()` (line 386)

---

## 47. Outgoing Campaign Maximum Channels Logic Fix
**Date**: 2026-03-10

**Problem**: Outgoing campaigns exceeded `max_canales` limit because connected calls (answered by agents) were not counted as active channels.

**Root Cause**: `_countActiveCalls()` only counted calls with status `Placing/Ringing/OnQueue/OnHold`. Calls with status `Success` (connected to agent) were excluded, causing undercounting and over-placement.

**Solution**: Include `Success` calls in active count only when `end_time IS NULL` (still connected). When a call ends, `end_time` is set and the call drops out of the active count.

**Files**:
- `setup/dialer_process/dialer/CampaignProcess.class.php`

**Changes**:
- Modified `_countActiveCalls()` SQL query to include connected calls (line 2412)
- Updated debug log message to show "Connected" status (lines 2420-2422)
- Updated Pass 1 comment to reflect expanded count (lines 596-597)

---

## 46. Orphaned "Placing" Call Cleanup + Effective max_canales Cap Fix
**Date**: 2026-03-08

**Problem**: Campaign 2 permanently blocked from placing calls; only Campaign 3 generated calls even though both shared agents. With predictive enabled, concurrent calls could exceed max_canales.

**Root Cause**:
1. Four calls stuck in "Placing" status from previous runs → `active_calls=4` → `effective_max=0` for campaign 2
2. Pass 2 used raw `max_canales` instead of effective (minus active calls)

**Solution**:
- Startup cleanup: Mark all "Placing" calls as "Failure" at dialer startup
- Runtime cleanup: Auto-expire "Placing" calls >5 minutes
- Pass 2 cap: Use `effectiveMaxCanales` (from Pass 1) instead of raw `max_canales`

**Files**:
- `setup/dialer_process/dialer/CampaignProcess.class.php`

**Changes**:
- Added startup orphaned "Placing" call cleanup (lines 150-165)
- Added runtime `_cleanOrphanedPlacingCalls()` method (lines 2350-2371)
- Pass 2 uses `effectiveMaxCanales` for predictive cap (lines 1035-1047)
- Pass 2 uses `effectiveMaxCanales` for overcommit re-cap (lines 1102-1114)

---

## 45. Native Systemd Service (Orphaned Process Fix)
**Date**: 2026-03-08

**Problem**: After server restarts, campaigns stop processing because orphaned child processes remain running.

**Root Cause**: Auto-generated systemd unit (from SysV init script) uses `KillMode=process`, meaning only the main process gets killed — child processes (AMIEventProcess, CampaignProcess, SQLWorkerProcess, ECCPProcess) become orphaned with stale connections.

**Solution**: Created native systemd service file with `KillMode=control-group` to ensure ALL child processes are terminated on shutdown.

**Files**:
- `setup/dialer_process/issabeldialer.service` — NEW: Native systemd service unit
- `setup/dialer_process/issabeldialer` — REMOVED: Old SysV init script
- `build/5.0/install-issabel-callcenter.sh` — Updated to install systemd service
- `build/5.0/remove-issabel-callcenter.sh` — Updated to remove systemd service
- `build/4.0/install-issabel-callcenter.sh` — Updated for consistency

**Key systemd features**:
- `KillMode=control-group` — Kills all processes in cgroup (fixes orphaned process issue)
- `After=mariadb.service asterisk.service` — Prevents startup race condition
- `Requires=mariadb.service` — Won't start without MariaDB
- `Restart=on-failure` — Auto-recovers from crashes

---

## 44. Transfer Call Tracking Fix
**Date**: 2026-03-08

**Files**:
- `setup/dialer_process/dialer/Llamada.class.php`
  - Added `transfer_pending` property (boolean flag to protect calls during blind transfer)
  - Added `llamadaTransferidaDesdeAgente()` method — lightweight agent release that keeps the call alive in `_listaLlamadas` for re-linking to the target agent (unlike `llamadaFinalizaSeguimiento()` which removes the call entirely)
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
  - `_finalizarTransferencia()`: Uses `llamadaTransferidaDesdeAgente()` instead of `llamadaFinalizaSeguimiento()` to preserve the call for the target agent's Link event
  - `_reservarAgenteParaTransferencia()`: Sets `transfer_pending` flag on the call during the synchronous RPC (guaranteed before any Hangup events)
  - `msg_Hangup()`: Three-way handling when `transfer_pending` is TRUE:
    1. Agent still linked → lightweight release via `llamadaTransferidaDesdeAgente()`
    2. Agent already released + customer/trunk channel hanging up → full finalization (transfer failed)
    3. Agent already released + intermediate channel (Local) → ignore (call waiting for re-link)
  - `msg_Link()`: Added transfer detection block — when a call has `timestamp_link` set but `agente` is NULL (released by transfer), reassigns the call to the target agent. Includes:
    - Agent lookup with `estado_consola` (logged-in) check and `extension` index fallback for Agent-type logins
    - Outgoing call support: accepts calls found by `actualchannel` when uniqueid doesn't match (outgoing calls use Local channels with different uniqueids than the trunk)
    - Clears `transfer_pending` after successful reassignment

**Bug**: After a blind transfer between agents, the transferred call did not appear in the receiving agent's console. The Hangup button remained disabled, and if the agent hung up from their phone device, the agent session was terminated instead of just ending the call.

**Root Causes**:
1. `llamadaFinalizaSeguimiento()` removed the call from `_listaLlamadas`, making it invisible to the target agent's `msg_Link()` event
2. Race condition: `msg_Hangup` could arrive before `_finalizarTransferencia`, fully finalizing the call before the transfer logic ran
3. For Agent-type logins (e.g., Agent/1001 on SIP/101), the agent lookup found the wrong agent (SIP/101 logged-out instead of Agent/1001 logged-in)
4. For outgoing calls, the Link event used the trunk's uniqueid (different from the call's tracked Local channel uniqueid), so the call wasn't found
5. For outgoing calls, multiple hangup events (agent channel + Local channel) required protection — a single `transfer_pending` clear after the first hangup left the second hangup unprotected

**Tested Scenarios**:
- Incoming calls: SIP callback → PJSIP callback, PJSIP → SIP, SIP → Agent-type, PJSIP → Agent-type
- Outgoing calls: SIP → PJSIP, PJSIP → SIP, Agent-type → callback, callback → Agent-type
- Normal call flow (no transfer) unaffected
- Transfer failure (customer hangs up before target answers) properly finalizes the call

**Limitation (TODO in code)**: Source agent attribution is lost — `id_agent` in `calls`/`call_entry` is overwritten to the target agent. A `call_agent_history` table would be needed to track all agents that handled a call for accurate reporting.

---

## 43. Centralized Web Module Logging Infrastructure
**Date**: 2026-03-07

**Files**:
- `modules/agent_console/libs/issabel2.lib.php` (added configurable log path, LOCK_EX flag)
- `setup/callcenter-modules.logrotate` (new file)
- `build/5.0/install-issabel-callcenter.sh` (added log directory creation and logrotate install)
- `build/5.0/remove-issabel-callcenter.sh` (added log directory removal)

**Change**: Moved web module debug logging from `/tmp/debug-callcenter.txt` to proper `/var/log/callcenter-module/` directory with log rotation support.

**Benefits**:
- Logs persist across reboots (was: `/tmp/` cleared on reboot)
- Automatic log rotation (daily, 7 days retained, compressed)
- Follows Linux logging conventions
- Centralized location for all Call Center web module debug logs

**Technical Details**:
- New global variable: `$GLOBALS['CALLCENTER_DEBUG_FILE']` (default: `/var/log/callcenter-module/debug.log`)
- Directory permissions: `750` (asterisk:asterisk)
- Log file permissions: `640` (asterisk:asterisk)
- Added `LOCK_EX` flag for concurrent write safety
- Logrotate config: `/etc/logrotate.d/callcenter-modules`

**Usage**:
- Enable debug: Set `$GLOBALS['CALLCENTER_DEBUG'] = true;` in `issabel2.lib.php`
- View logs: `tail -f /var/log/callcenter-module/debug.log`
- Module identification: Each log entry includes `[module_name]` prefix

---

## 42. Expand Transfer Dialog Size
**Date**: 2026-03-07

**Files**:
- `modules/agent_console/themes/default/js/javascript.js`

**Change**: Expanded transfer popup dimensions for better visibility:
- Width: 400px → 600px (+50%)
- Height: 200px → 320px (+60%)

Dialog remains centered via jQuery UI modal positioning.

---

## 41. Prevent Agent Type Login if Extension Used by Callback Extension Session
**Date**: 2026-03-07

**Files**:
- `modules/agent_console/libs/paloSantoConsola.class.php` (added `extensionUsadaPorCallback()` method)
- `modules/agent_console/index.php` (added check for Agent type login)

**Issue**: An Agent type agent could log in using an extension number that was already actively being used by a callback extension type login session (SIP/PJSIP/IAX2), causing conflicts. This is the reverse scenario of change #34.

**Example**:
- SIP/101 (callback type) logs in with extension SIP/101
- Agent/1001 can then also log in with extension 101
- Result: Two sessions using the same physical extension

**Fix**: Added validation check in `manejarLogin_doLogin()` that:
1. Detects when Agent type login is attempted (`!$bCallback`)
2. Queries the audit table to check if a callback type agent is actively logged in with that extension
3. Blocks login with error "Extension is already in use by another agent" / "La extensión ya está siendo usada por otro agente"

**Technical Details**:
- New method: `PaloSantoConsola::extensionUsadaPorCallback($sExtensionNum)`
- Query checks: `audit.datetime_end IS NULL` (active session) + `agent.type != 'Agent'` (callback types) + `login_extension LIKE %extension_number%`
- Check occurs AFTER password verification but BEFORE proceeding to login state check

---

## 40. Fix Transfer Reservation Leak on Extension Parsing Failure
**Date**: 2026-03-07

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php` (added reservation cleanup on early returns)

**Issue**: In `Request_agentauth_transfercallagent()`, after acquiring an atomic transfer reservation via `AMIEventProcess_reservarAgenteParaTransferencia()`, there were two early-return paths that did not release the reservation:
1. When target agent's extension could not be determined from their channel (`sTargetExtension` is NULL)
2. When agent number could not be parsed for Agent-type agents (`sAgentNumber` is NULL)

In these edge cases, the target agent remained blocked in `_agentesEnTransferPendiente` for 30 seconds until the timeout alarm fired, preventing any other transfers to that agent.

**Fix**: Added `msg_AMIEventProcess_liberarReservaTransferencia($sTargetAgent)` call before both early returns to immediately release the reservation.

**Example of the problem**:
```
Before fix:
  1. Reservation granted for target Agent/1002
  2. ExtensionState check passes
  3. Channel parsing fails (edge case)
  4. Error returned to client
  5. Target agent blocked for 30 seconds until timeout

After fix:
  1. Reservation granted for target Agent/1002
  2. ExtensionState check passes
  3. Channel parsing fails (edge case)
  4. Reservation released immediately
  5. Error returned to client
  6. Target agent available immediately
```

---

## 39. Centralized Debug Infrastructure for Web Modules
**Date**: 2026-03-07

**Files**:
- `modules/agent_console/libs/issabel2.lib.php` (added debug infrastructure)
- `modules/agent_console/index.php` (migrated to centralized debug)
- `modules/agent_break/index.php` (added issabel2.lib.php include)
- `modules/agent_journey/index.php` (added issabel2.lib.php include)
- `modules/calls_per_agent/index.php` (added issabel2.lib.php include)
- `modules/calls_per_hour/index.php` (added issabel2.lib.php include)
- `modules/dont_call_list/index.php` (added issabel2.lib.php include)
- `modules/graphic_calls/index.php` (added issabel2.lib.php include)
- `modules/hold_time/index.php` (added issabel2.lib.php include)
- `modules/login_logout/index.php` (added issabel2.lib.php include)
- `modules/rep_agent_information/index.php` (added issabel2.lib.php include)
- `modules/rep_trunks_used_per_hour/index.php` (added issabel2.lib.php include)
- `modules/ingoings_calls_success/index.php` (added issabel2.lib.php include)

**Issue**: Debug logging for call center web modules was inconsistent and scattered. Only `agent_console` had debug capability via a hardcoded constant `AGENT_CONSOLE_DEBUG_LOG`. The remaining 30 modules had zero debug logging. The old `_debug()` function was local to `agent_console/index.php`, used a PHP constant (cannot be changed at runtime), and only logged to a module-specific file with no browser console output.

**Example of the problem**:
```
Before fix:
  agent_console: define('AGENT_CONSOLE_DEBUG_LOG', FALSE);  // Constant, can't toggle at runtime
  all other modules: No debug capability at all

After fix:
  All 31 modules: $GLOBALS['CALLCENTER_DEBUG'] = false;     // Can be toggled at runtime
  File logging: /tmp/debug-callcenter.txt (centralized)
  Browser console: console.log() output when debug enabled
```

**Fix**: Added centralized debug infrastructure to `issabel2.lib.php` (shared library included by all call center modules):

1. **Global debug flag**: `$GLOBALS['CALLCENTER_DEBUG']` (default: false)
   - Edit `issabel2.lib.php` to enable permanently
   - Or set at runtime: `$GLOBALS['CALLCENTER_DEBUG'] = true;`

2. **Central debug function**: `_cc_debug($message, $module_name)`
   - Logs to `/tmp/debug-callcenter.txt` with module name prefix
   - Format: `IP timestamp [module_name] agent=XXXX message`
   - Collects messages for browser console output

3. **Browser console flush**: `_cc_debug_flush_html()`
   - Appends `<script>console.log()</script>` tags to HTML output
   - Call at HTML return points: `return $html . _cc_debug_flush_html();`

4. **JSON attachment**: `_cc_debug_attach_json(&$response)`
   - Attaches debug messages to JSON response arrays
   - Client-side JS can read `response._cc_debug`

**Modules covered** (31 total):
- Already included issabel2.lib.php (20): agent_console, agents, break_administrator, callcenter_config, calls_detail, campaign_in, campaign_monitoring, campaign_out, cb_extensions, client, eccp_users, external_url, form_designer, form_list, queues, rep_agents_monitoring, rep_incoming_calls_monitoring, rep_incoming_campaigns_panel, rep_outgoing_campaigns_panel, reports_break
- Added issabel2.lib.php include (11): agent_break, agent_journey, calls_per_agent, calls_per_hour, dont_call_list, graphic_calls, hold_time, login_logout, rep_agent_information, rep_trunks_used_per_hour, ingoings_calls_success

**Usage in any call center module**:
```php
_cc_debug('Starting campaign load', 'campaign_out');
_cc_debug('Filter: ' . $filter, 'calls_detail');

// At HTML return points
return $smarty->fetch("template.tpl") . _cc_debug_flush_html();
```

**Enable/disable debug**:
```bash
# Enable
sed -i "s/CALLCENTER_DEBUG'] = false/CALLCENTER_DEBUG'] = true/" /var/www/html/modules/agent_console/libs/issabel2.lib.php

# Disable (default)
sed -i "s/CALLCENTER_DEBUG'] = true/CALLCENTER_DEBUG'] = false/" /var/www/html/modules/agent_console/libs/issabel2.lib.php

# View logs
tail -f /tmp/debug-callcenter.txt
grep '\[agent_console\]' /tmp/debug-callcenter.txt
grep '\[campaign_out\]' /tmp/debug-callcenter.txt
```

**Note**: This is separate from the dialer daemon debug system (`dialer.debug` in database, logs to `/opt/issabel/dialer/dialerd.log`). The web module debug only covers PHP web modules running under Apache/PHP-FPM.

---

## 38. Integrate Predictive Dialer into Fair-Rotation Path
**Date**: 2026-03-06

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: The fair-rotation path (`_processCampaignWithAllocation`) ignored the `dialer_predictivo` configuration setting. It placed exactly 1 call per allocated free agent, never anticipating busy agents about to finish their calls. The Erlang-based predictive logic only existed in the dead legacy method (`_actualizarLlamadasCampania`), so enabling "Predictive Dialer Behavior" in callcenter_config had no effect on call placement.

**Example of the problem**:
```
Queue has 3 free agents + 2 busy agents about to finish calls
Campaign allocated 3 agents via fair rotation

Without predictive (before fix):
  Calls placed = 3 (only free agents)
  2 agents finish calls → idle until next cycle

With predictive (after fix):
  Calls placed = 3 + 2 = 5 (free + predicted)
  2 agents finish calls → calls already waiting for them
```

**Fix**: Added predictive dialer boost in `_processCampaignWithAllocation` (Pass 2), applied after agent allocation and before the max_canales cap:

1. Gets fresh queue prediction data via `infoPrediccionCola()`
2. Runs `predecirNumeroLlamadas()` with Erlang probability if campaign has enough samples (`num_completadas >= 10`)
3. Calculates boost: `AGENTES_POR_DESOCUPAR - CLIENTES_ESPERA - already_claimed`
4. Adds boost to `iNumLlamadasColocar` before max_canales cap

**Shared-queue double-counting prevention**: New property `$_predictiveSlotsUsed` tracks how many predictive slots each queue has given out per cycle. When Campaign A claims 2 predictive slots from a shared queue, Campaign B sees 2 fewer available.

**Order of operations**:
```
1. iNumLlamadasColocar = numAllocatedAgents        (fair rotation)
2. + predictive boost                               (NEW)
3. Cap by max_canales                               (trunk limit)
4. Subtract pending OriginateResponse               (avoid over-placing)
5. Overcommit (ASR-based)                           (compensate failures)
6. Re-cap by max_canales                            (trunk hard limit)
```

**Activation conditions**:
- `dialer.predictivo = 1` (enabled in callcenter_config)
- Campaign has at least 1 allocated agent this cycle
- Erlang prediction requires `num_completadas >= 10` completed calls; otherwise falls back to basic agent counting

**Debug logs** (when dialer_debug enabled):
```bash
# Watch predictive boost events
grep -E "predictive boost|impulso predictivo" /opt/issabel/dialer/dialerd.log | tail -30

# Compare allocated agents vs total calls placed
grep -E "allocated agents|FINAL iNumLlamadasColocar" /opt/issabel/dialer/dialerd.log | tail -30

# Verify shared-queue double-counting prevention
grep -E "already_claimed|ya_reclamados" /opt/issabel/dialer/dialerd.log | tail -20
```

---

## 37. Cap Overcommit by max_canales (Trunk Capacity Limit)
**Date**: 2026-03-06

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When "Enable Overcommit of Outgoing Calls" is enabled, the overcommit logic inflates the number of calls to place based on the ASR (Answer Seizure Ratio), but does not re-check the campaign's `max_canales` setting afterward. Since `max_canales` represents the physical trunk capacity, overcommit can push calls beyond what the trunk can handle, causing trunk saturation, high call failure rates, and unnecessary data/traffic consumption.

**Example of the problem**:
```
7 agents logged in
Trunk capacity: max_canales = 8
ASR = 50%

Before fix:
  Calls to place = 7
  Overcommit: 7 / 0.5 = 14 calls placed
  Trunk can only handle 8 → 6 calls fail due to trunk saturation
  Failed calls lower ASR further → vicious cycle

After fix:
  Calls to place = 7
  Overcommit: 7 / 0.5 = 14
  Re-cap: min(14, 8) = 8 calls placed
  Trunk handles all 8 → ~4 succeed, within trunk limits
```

**Fix**: Added a max_canales re-cap after the overcommit inflation in both code paths:
- **Fair-rotation path** (`_processCampaignWithAllocation`): re-caps after overcommit at ~line 1007
- **Legacy path** (`_revisarLlamadasCampania`): re-caps after overcommit at ~line 1435

The re-cap uses the raw `max_canales` value (not `effectiveMaxCanales`) since it represents the absolute trunk hardware limit. Overcommit still provides benefit up to that ceiling.

---

## 36. Conditional RINGING-as-Free Based on Predictive Dialer Config
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/QueueShadow.class.php`
- `setup/dialer_process/dialer/Predictor.class.php`
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When a call is transferred to a callback agent (SIP/PJSIP), the phone rings for several seconds until the agent answers. During this time, `AST_DEVICE_RINGING` was counted as "free" in the campaign prediction logic (`infoPrediccionCola`), so campaigns could originate calls for an agent who was actually handling a transferred call — wasting calls and causing abandoned calls.

**Fix**: Made the RINGING-as-free behavior conditional on the `dialer_predictivo` config flag:
- **Predictive ON** (`dialer.predictivo = 1`): counts both `AST_DEVICE_NOT_INUSE` and `AST_DEVICE_RINGING` as free (current behavior preserved)
- **Predictive OFF** (`dialer.predictivo = 0`): counts only `AST_DEVICE_NOT_INUSE` as free (safer for transfers)

**Technical Details**:
- Added `$predictive` parameter (default `true`) to `infoPrediccionCola()` in both `QueueShadow` and `Predictor`
- `CampaignProcess` passes `$this->_configDB->dialer_predictivo` at all 4 call sites
- `AMIEventProcess.rpc_infoPrediccionCola()` unpacks and forwards the flag via the RPC mechanism
- Added TODO comments noting that RINGING-as-free should be deeply analyzed even for predictive mode
- Log output includes `predictive=YES/NO` for debugging

**Verification**:
```bash
grep -E "AGENT_FREE|predictive" /opt/issabel/dialer/dialerd.log | tail -30
```

---

## 35. Check Extension Registration Before Callback Login
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `modules/agent_console/libs/ECCP.class.php`
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/agent_console/index.php`
- `modules/agent_console/lang/en.lang`
- `modules/agent_console/lang/es.lang`
- `setup/dialer_process/dialer/eccp-examples/getextensionstatus.php` (new)

**Issue**: A callback extension type agent (SIP/PJSIP/IAX2) could log in even if their extension was NOT registered in Asterisk. This allowed login attempts from non-existent or offline extensions.

**Fix**: Added ECCP request `getextensionstatus` to check extension registration:
1. New ECCP server request: `Request_eccpauth_getextensionstatus` in ECCPConn.class.php
2. New ECCP client method: `ECCP::getextensionstatus($extension)` in ECCP.class.php
3. New agent console method: `PaloSantoConsola::extensionEstaRegistrada($sAgentFormat)`
4. Validation in `manejarLogin_doLogin()` blocks login with error "Extension is not registered" / "La extensión no está registrada"
5. ECCP example file created: `eccp-examples/getextensionstatus.php`

**Technical Details**:
- Extension registration check uses the dialer's existing AMI connection via ECCP
- For SIP: checks `sip show peer <extension>` for Status OK/Registered
- For PJSIP: checks `pjsip show endpoint <extension>` for State/Status Reachable
- For IAX2: checks `iax2 show peer <extension>` for Status OK/Registered
- Extension must be registered (not just configured) to allow callback login
- Validation occurs BEFORE checking if extension is used by Agent type session

**ECCP Request**:
```xml
<request>
    <getextensionstatus>
        <extension>SIP/101</extension>
    </getextensionstatus>
</request>
```

**ECCP Response**:
```xml
<response>
    <getextensionstatus_response>
        <extension>SIP/101</extension>
        <registered>yes</registered>
    </getextensionstatus_response>
</response>
```

---

## 34. Prevent Callback Extension Login if Extension Used by Agent Type Session
**Date**: 2026-03-06

**Files**:
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/agent_console/index.php`
- `modules/agent_console/lang/en.lang`
- `modules/agent_console/lang/es.lang`

**Issue**: A callback extension type agent (SIP/PJSIP/IAX2) could log in using an extension number that was already actively being used by an Agent type login session, causing conflicts.

**Example**:
- Agent/1001 logs in with extension SIP/101
- SIP/101 (callback type) can then also log in with extension SIP/101
- Result: Two sessions using the same physical extension

**Fix**: Added validation check in `manejarLogin_doLogin()` that:
1. Detects when callback login is attempted
2. Extracts the extension number from the callback format (e.g., SIP/101 → 101)
3. Queries the audit table to check if an Agent type agent is actively logged in with that extension
4. Blocks login with error "Extension is already in use by another agent" / "La extensión ya está siendo usada por otro agente"

**Technical Details**:
- New method: `PaloSantoConsola::extensionUsadaPorAgente($sExtensionNum)`
- Query checks: `audit.datetime_end IS NULL` (active session) + `agent.type = 'Agent'` + `login_extension LIKE %extension_number%`
- Agent type login is NOT affected - only blocks callback types when extension conflicts

---

## 35.1. Agent-to-Agent Transfer Feature
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `modules/agent_console/libs/ECCP.class.php`
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/agent_console/index.php`
- `modules/agent_console/themes/default/js/javascript.js`
- `modules/agent_console/themes/default/agent_console.tpl`
- `modules/agent_console/lang/en.lang`
- `modules/agent_console/lang/es.lang`
- `setup/dialer_process/dialer/ECCP_Protocol.md`
- `setup/dialer_process/dialer/eccp_examples/transfercallagent.eccp` (new)

**Feature**: Added "Transfer to Agent" functionality to the agent console, allowing agents to transfer their active calls to another logged-in agent. This is the third transfer type, alongside existing "Blind transfer" (to extension) and "Attended transfer" (to extension).

**New Capabilities**:
- Agents can transfer calls to other logged-in agents with availability verification
- Target agent must be online (logged in), not on a call, and not on pause
- Transfer is executed as a blind transfer (no consultation phase)
- Agent dropdown shows "Agent/9000 - Agent Name" format for easy selection
- Current agent is excluded from the dropdown to prevent self-transfer

**UI Changes**:
- Transfer dialog now has 3 radio buttons: "Blind transfer", "Attended transfer", "Transfer to agent"
- When "Transfer to agent" is selected, an agent dropdown appears (extension input is hidden)
- When other transfer types are selected, the extension input appears (agent dropdown is hidden)

**Backend Implementation**:
- New ECCP command: `transfercallagent` (requires agent authentication)
- Target agent status validation: checks online, oncall, and paused states
- For Agent type: uses `[agents]` context with `AgentRequest()` application
- For callback types (SIP/PJSIP/IAX2): uses `from-internal` context
- Transfer is registered in database with target agent number

**Error Handling**:
- "Target agent is busy" - target agent is on a call
- "Target agent is not logged in" - target agent is offline
- "Target agent is on pause" - target agent is on break
- "Cannot transfer while call is on hold" - source agent has call on hold
- "Invalid or missing target agent" - no agent selected

**ECCP Protocol**:
```xml
<request id="timestamp.random">
    <transfercallagent>
        <agent_number>Agent/9000</agent_number>
        <agent_hash>XXX</agent_hash>
        <target_agent_number>Agent/9001</target_agent_number>
    </transfercallagent>
</request>
```

**Documentation**: See `TRANSFER_TO_AGENTS.md` for complete implementation details and test steps.

**Bug Fix**: Agent type agents require agent NUMBER (e.g., 1002) for `AgentRequest()`, not extension (e.g., 102). The code now correctly extracts the agent number from the agent string (Agent/1002 -> 1002) when transferring to Agent type agents.

---

## 35.2. Fix: Agent Type Transfer Using Correct Agent Number
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`

**Bug 1**: Transferring calls to Agent type agents (app_agent_pool) failed with "Agent 'XXX' does not exist" error.

**Root Cause**: For Agent type agents like Agent/1002 using extension 102:
- The agent NUMBER is 1002 (used by AgentRequest)
- The agent EXTENSION is 102 (the device/phone number)
- `AgentRequest(102)` fails because agent ID '102' doesn't exist
- `AgentRequest(1002)` correctly routes to Agent/1002

**Bug 2**: Transfer was stored in database using extension number instead of agent number for Agent type agents.

**Fix**: Modified `Request_agentauth_transfercallagent()` to:
1. Extract agent number from agent string using regex: `Agent/(\d+)` -> 1002
2. Use agent number for `AgentRequest()` when transferring to Agent type agents
3. Use agent number in database transfer field for Agent type agents
4. Continue using extension for callback agent types (SIP/PJSIP/IAX2)

**Log Change**:
- Before: `AgentRequest("SIP/...", "102")` - fails
- After: `AgentRequest("SIP/...", "1002")` - succeeds

**Database Change**:
- Before: `transfer` field stores "102" (extension)
- After: `transfer` field stores "1002" (agent number) for Agent type agents

---

## 33. New ECCP Example Files
**Date**: 2026-03-06

**Files**:
- `setup/dialer_process/dialer/eccp-examples/callprogress.php`
- `setup/dialer_process/dialer/eccp-examples/getcampaigninfo.php`
- `setup/dialer_process/dialer/eccp-examples/filterbyagent.php`
- `setup/dialer_process/dialer/eccp-examples/saveformdata.php`

**Feature**: Added 4 new ECCP example files to complete documentation coverage for all actively used ECCP methods. The ECCP examples directory now includes examples for all 28 methods that are actively used in the codebase (100% coverage, up from 86%).

**New Examples**:
- `callprogress.php` - Enable/disable call progress event notifications (no auth required)
- `getcampaigninfo.php` - Retrieve campaign configuration including forms and scripts (no auth required)
- `filterbyagent.php` - Filter ECCP events to only receive events for a specific agent (requires agent authentication)
- `saveformdata.php` - Save form data collected during calls (requires agent authentication)

**Usage Examples**:
```bash
# Enable call progress tracking
su - asterisk -c "/opt/issabel/dialer/eccp-examples/callprogress.php 1"

# Get campaign information
su - asterisk -c "/opt/issabel/dialer/eccp-examples/getcampaigninfo.php outgoing 1"

# Filter events by agent
su - asterisk -c "/opt/issabel/dialer/eccp-examples/filterbyagent.php Agent/9000 password"

# Save form data
su - asterisk -c "/opt/issabel/dialer/eccp-examples/saveformdata.php Agent/9000 password outgoing 123 1 10:value1 11:value2"
```

**Documentation**: See `ECCP_EXAMPLES.md` for complete ECCP method coverage analysis and usage details.

---

## 32. Full UTF-8 (utf8mb4) Database Support
**Date**: 2026-03-05

**Files**:
- `setup/call_center.sql`
- `setup/installer.php`

**Issue**: The `call_center` database used mixed charsets — most tables were `utf8` (3-byte, no emoji/supplementary Unicode support) and 5 tables (`campaign_entry`, `campaign_form_entry`, `form_data_recolected_entry`, `dont_call`, `valor_config`) defaulted to `latin1` due to missing explicit charset in CREATE TABLE statements. User-facing text fields like form data, campaign names, and scripts could not store full Unicode characters (emojis, CJK supplementary).

**Fix**:
- **call_center.sql**: Changed all `DEFAULT CHARSET=utf8` to `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci`. Added explicit charset to the 5 tables that were missing it. Changed `SET NAMES utf8` to `SET NAMES utf8mb4`. Added migration procedure `temp_charset_utf8mb4_2026_03_05` that converts any remaining non-utf8mb4 tables via cursor.
- **installer.php**: Added `convertirCharsetUtf8mb4()` function that queries `INFORMATION_SCHEMA.TABLES` for non-utf8mb4 tables and runs `ALTER TABLE ... CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci` on each.

**Key columns affected**: `form_data_recolected.value`, `form_data_recolected_entry.value`, `campaign.name`, `campaign.script`, `campaign_entry.name`, `campaign_entry.script`, `agent.name`, `form_field.etiqueta`, `form.nombre`, `contact.name`, among others.

**Code compatibility**: No code changes needed — the system-wide `paloSantoDB.class.php` and `agent_console` local copy already use `charset=utf8mb4` in PDO DSN connections. No indexed text columns exceed key length limits.

---

## 31. Fix Campaign Staying Active After Data Exhaustion
**Date**: 2026-03-05

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: Outgoing campaigns could remain in "Active" status even after all callable data was exhausted. This happened when the last calls completed while no agents were available (busy, logged out, or not allocated), because the "mark as finished" check only ran when agents were available to place calls.

**Root cause**: The finish check (`estatus = "T"`) in `_processCampaignWithAllocation()` required `$iNumLlamadasColocar > 0` (agents available). Two early-return paths exited before reaching this check:
1. No agents allocated this cycle
2. No free agents and no scheduled calls

**Fix**: Added `_checkCampaignDataExhausted()` method that runs at both early-return points. It independently checks whether the campaign has any remaining callable records and no active calls in progress, and marks the campaign as finished if data is exhausted — regardless of agent availability.

---

## 30. Fix Outgoing/Incoming Campaigns Panel Reports
**Date**: 2026-03-05

**Files**:
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: The Outgoing and Incoming Campaigns Panel reports were filtering calls incorrectly. ECCP returns time-only strings (`HH:MM:SS`) for today's calls (date prefix stripped in `ECCPConn._agregarCallInfo`), but these were being compared against full datetime strings (`YYYY-MM-DD HH:MM:SS`). This caused calls to be incorrectly excluded from the panel results.

**Fix**: Added date normalization before comparison — when `callStartTime` is a time-only string (8 chars or less, no date prefix), today's date is prepended for proper datetime comparison. Applied to both outgoing and incoming campaign panel methods.

**Also included**: Added extra debug logging to the campaign fair rotation logic (rotation start, agent map, allocation results, per-campaign processing).

---

## 29. Max Concurrent Calls Awareness in Fair Rotation
**Date**: 2026-02-28

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When using fair rotation with shared agents, if a campaign won more agents than its `max_canales` (max concurrent calls) allowed, the extra agents were wasted - they weren't given to other campaigns that could use them.

**Example of the problem**:
```
10 shared agents available
Campaign 1: max_canales = 5
Campaign 2: max_canales = 5

Cycle 1 (Campaign 1 wins rotation for all 10 agents):
  - Campaign 1 allocated: 10 agents
  - Campaign 1 places: min(10, 5) = 5 calls (capped by max_canales)
  - 5 agents WASTED (not given to Campaign 2)
```

**Fix**: Enhanced the fair rotation allocation to be max_canales-aware:
- Track allocation count per campaign during agent distribution
- When a campaign wins an agent but has reached its max_canales limit, skip to the next campaign in rotation order
- Continue until finding a campaign with capacity or all campaigns are at limit

**New behavior**:
```
10 shared agents available
Campaign 1: max_canales = 5
Campaign 2: max_canales = 5

Cycle 1:
  - Agents 1-5: Campaign 1 wins, has capacity → allocated to Campaign 1
  - Agent 6: Campaign 1 wins, but at limit (5/5) → skip to Campaign 2 → allocated
  - Agent 7: Campaign 1 wins, but at limit → skip to Campaign 2 → allocated
  - Agents 8-10: Same pattern → allocated to Campaign 2

  Result: Campaign 1 gets 5, Campaign 2 gets 5 (NO agents wasted!)
```

**Technical Details**:
- New property: `$_campaignMaxCanales` stores max_canales per campaign
- New method: `_getRotationWinnerWithCapacity()` finds next campaign with available capacity
- Pass 1 now collects max_canales for each campaign
- Rotation still advances normally to maintain fairness across cycles

**Debug logs** (when dialer_debug enabled):
```bash
# Watch max_canales-aware allocation
tail -f /opt/issabel/dialer/dialerd.log | grep -E "max_canales|skipped.*at max_canales"

# Check skipped allocations
grep "skipped.*at max_canales" /opt/issabel/dialer/dialerd.log | tail -20
```

---

## 28. Dialer Service in Dashboard ProcessesStatus Applet
**Date**: 2026-02-27

**Files**:
- `build/5.0/install-issabel-callcenter.sh`
- `build/5.0/remove-issabel-callcenter.sh`
- `setup/icon_headphones.png`

**Feature**: Added the Issabel Call Center Service (Dialer) to the Issabel Dashboard's ProcessesStatus widget, allowing administrators to monitor and control the dialer service from the main dashboard.

**Changes**:
- Installation script now patches `/var/www/html/modules/dashboard/applets/ProcessesStatus/index.php` to add:
  - Dialer icon mapping (`icon_headphones.png`)
  - Service control mapping for start/stop/restart
  - Status detection using `/opt/issabel/dialer/dialerd.pid`
- Removal script removes all patches and the icon file
- Patching is idempotent (safe to run multiple times)

**Dashboard Display**:
- Service name: "Issabel Call Center Service"
- Icon: headphones icon
- Controls: Start, Stop, Restart, Enable, Disable

---

## 27. Shift-Based Counters in Agent Console
**Date**: 2026-02-27

**Files**:
- `modules/agent_console/index.php`
- `modules/agent_console/themes/default/agent_console.tpl`
- `modules/agent_console/themes/default/css/issabel-callcenter.css`
- `modules/agent_console/themes/default/js/javascript.js`

**Feature**: Added real-time shift-based counters in the agent console that display cumulative time tracking for the current shift:

- **Green**: Total Login Time - Shows how long the agent has been logged in during the current shift
- **Red**: Total Break Time - Shows cumulative break/pause time during the current shift
- **Orange**: Total Hold Time - Shows cumulative hold time across all calls during the current shift

**Implementation Details**:
- Counters are calculated from the agent's shift start time
- Data is fetched via SSE (Server-Sent Events) for real-time updates
- Visual indicators use color coding for quick status recognition
- Timers update in real-time using JavaScript intervals

**Technical Changes**:
- Added `getShiftCounters()` method in index.php to calculate shift-based metrics
- Added SSE endpoint for streaming counter updates
- Added CSS styles for counter display with color-coded backgrounds
- Added JavaScript timer logic for real-time counter updates

---

## 26. Total Hold Time Column in Agents Monitoring
**Date**: 2026-02-27

**Files**:
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/lang/*.lang`

**Feature**: Added new column "Total hold time" in the Agents Monitoring report to display accumulated hold time per agent during the current shift.

---

## 25. Outgoing Campaigns Module Enhancements
**Date**: 2026-02-27

**Files**:
- `modules/campaign_out/index.php`
- `modules/campaign_out/libs/paloSantoCampaignCC.class.php`

**Feature**: Added two new columns in the outgoing campaigns module for better campaign visibility and management.

---

## 24. Fair Agent Distribution Over Campaigns
**Date**: 2026-02-26

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When multiple campaigns share the same agents (same queue), the first campaign to process always claims all shared agents, leaving nothing for other campaigns. This creates unfair distribution where Campaign B and C never get to use the agents.

**Example of the problem**:
```
Campaign A: agents [1001, 1002, 1003]
Campaign B: agents [1001, 1002, 1003]
Campaign C: agents [1001, 1002, 1003]  ← All share same agents!

Every cycle:
- Campaign A processes first → claims all agents → 3 calls
- Campaign B processes second → all claimed → 0 calls
- Campaign C processes third → all claimed → 0 calls

B and C NEVER get to use the agents.
```

**Fix**: Implemented two-pass processing with N-way rotation:

1. **Pass 1**: Collect which campaigns want which agents (intentions)
2. **Allocate**: For shared agents, use rotation index to determine whose turn
3. **Pass 2**: Process campaigns with their allocated agents
4. **Advance**: Increment rotation index for next cycle

**New behavior**:
```
Cycle 1: Campaign A gets agents → 3 calls
Cycle 2: Campaign B gets agents → 3 calls  (ROTATED!)
Cycle 3: Campaign C gets agents → 3 calls  (ROTATED!)
Cycle 4: Campaign A gets agents → 3 calls  (back to A)

Pattern: A → B → C → A → B → C → ...
```

**Technical Details**:
- New properties added: `$_agentRotation`, `$_campaignIntentions`, `$_allocatedAgents`
- New methods: `_resolveAgentRotation()`, `_getRotationWinner()`, `_processCampaignWithAllocation()`
- Rotation state persists across cycles but resets if campaign set changes
- Each agent rotates independently based on how many campaigns share it
- Unique agents (only wanted by one campaign) are assigned directly without rotation

**Debug logs** (when dialer_debug enabled):
```bash
tail -f /opt/issabel/dialer/dialerd.log | grep -E "rotation|allocated|winner|Pass 1"
grep -E "assigned to campaign.*rotation" /opt/issabel/dialer/dialerd.log | tail -30
```

---

## 23. Agent Conflict Detection
**Date**: 2026-02-26

**File**: `setup/dialer_process/dialer/CampaignProcess.class.php`

**Issue**: When multiple campaigns use the same queue (and thus share the same agents), the dialer could over-place calls. Each campaign independently saw all free agents and tried to place calls for all of them, resulting in more calls than agents available.

**Example of the problem**:
```
Queue Q has 3 free agents: [1001, 1002, 1003]
Campaign A uses Q → sees 3 free agents → places 3 calls
Campaign B uses Q → sees 3 free agents → places 3 calls
Campaign C uses Q → sees 3 free agents → places 3 calls

Result: 9 calls placed but only 3 agents available!
```

**Fix**: Added agent conflict detection that tracks which agents have been claimed by campaigns during each review cycle:
- New property `$_agentesReclamados` tracks claimed agents per cycle
- When a campaign processes, it checks which agents are already claimed
- Only unclaimed agents are counted as available
- Claimed agents are subtracted from the prediction count

**Technical Details**:
- Agent interfaces are normalized (e.g., `Local/1001@agents` → `Agent/1001`) for consistent tracking
- The claimed agents map is reset at the start of each campaign review cycle
- Debug logging shows which agents are claimed and by which campaign

**Note**: This feature was the foundation that led to the Fair Rotation feature above. With conflict detection alone, the first campaign still gets all agents. Fair Rotation ensures equitable distribution.

---

## 22. Fix Outgoing Campaigns Panel Date Filtering
**Date**: 2026-02-25

**File**: `modules/agent_console/libs/paloSantoConsola.class.php`

**Issue**: The Outgoing Campaigns Panel (`rep_outgoing_campaigns_panel`) displayed incorrect call counts (e.g., "Total calls: 0" or significantly lower numbers) compared to the Calls Detail report. The panel was filtering calls using `datetime_entry_queue`, which is only populated when a call actually enters the queue (i.e., the remote party answered and was bridged to an agent). Calls with outcomes like `NoAnswer` or `Failure` have `datetime_entry_queue = NULL` and were excluded from all counts.

**Fix**: Changed the date filter column from `datetime_entry_queue` to `fecha_llamada` in both SQL queries within `getOutgoingCallStatsByDatetimeRange()`. This matches how the Calls Detail report (`calls_detail`) filters outgoing calls and ensures all call records are included in the panel statistics regardless of whether they reached the queue.

**Technical Details**:
- The `fecha_llamada` column is set when the call record is created/scheduled, so it is always populated
- The `datetime_entry_queue` column is only set when a call enters the queue after the remote party answers
- Two queries were updated: the main status count query and the queued calls count query
- The status mapping logic (Success, Abandoned, NoAnswer, Failure, etc.) remains unchanged

---

## 21. New Configuration: Dump Related Asterisk Events
**Date**: 2026-02-25

**Files**:
- `modules/callcenter_config/index.php`
- `modules/callcenter_config/libs/paloSantoConfiguration.class.php`
- `modules/callcenter_config/lang/en.lang`
- `modules/callcenter_config/themes/default/form.tpl`
- `setup/dialer_process/dialer/ConfigDB.class.php`
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/SQLWorkerProcess.class.php`

**Issue**: When dialer debug is enabled, VarSet AMI events flood the log (623+ entries), making it difficult to find relevant debug information.

**Fix**: Added new configuration option "Dump related Asterisk events" to control VarSet event logging:
- When **disabled** (default): VarSet events are processed for MIXMONITOR_FILENAME tracking but not logged
- When **enabled**: All VarSet events are logged to `/opt/issabel/dialer/dialerd.log`

**Technical Details**:
- Config key: `dialer.relatedevents` stored in `valor_config` table
- Default value: `0` (disabled)
- Follows same pattern as existing `dialer.allevents` option
- VarSet processing for MIXMONITOR_FILENAME continues regardless of this setting

**Usage**: Call Center > Configuration > check/uncheck "Dump related Asterisk events"

---

## 20. On-Hold Status Display in Agents Monitoring
**Date**: 2026-02-20

**Files**:
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/lang/en.lang`

**Issue**: When an agent put a customer on hold, the Agents Monitoring panel continued to show the call icon without any indication that the call was on hold.

**Fix**: Added `onhold` field to the agent info array. When `onhold` is true, append "HOLD" label after the call/break icon for both `oncall` and `paused` states.

---

## 19. Shift-Based Filtering for Agents Monitoring Stats
**Date**: 2026-02-20

**Files**:
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/lang/en.lang`

**Issue**: All statistics in Agents Monitoring (break time, login time, talk time, call count) used a hardcoded full-day range (00:00:00–23:59:59 today). There was no way to filter stats to a specific work shift.

**Fix**: Added shift filter UI with From/To hour dropdowns. Supports overnight shifts spanning midnight. Preferences saved to localStorage.

---

## 18. Total Break Time Column in Agents Monitoring
**Date**: 2026-02-20

**Files**:
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/lang/*.lang`
- `modules/agent_console/libs/paloSantoConsola.class.php`

**Issue**: The Agents Monitoring report had no column to display total break time per agent.

**Fix**: Added `consultarTiempoBreakAgentes()` that queries the `audit` table for break sessions. Added "Total break time" column to the monitoring grid with real-time timer updates.

---

## 17. PHP 5.4 Compatibility Fix
**Date**: 2026-02-20

**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/Llamada.class.php`

**Issue**: Two uses of the null coalescing operator `??` (introduced in PHP 7.0) made the code incompatible with PHP 5.4.

**Fix**: Replaced with `isset()` ternary expressions.

---

## 16. Multiple Fixes and Attended Transfer Disabled for Agent Type
**Date**: 2026-02-17

**Files**:
- `modules/agent_console/index.php`
- `modules/agent_console/themes/default/agent_console.tpl`

**Issue**: The Transfer dialog showed both "Blind transfer" and "Attended transfer" radio buttons for all agent types. For Agent type (app_agent_pool), attended transfer has known edge cases.

**Fix**: Added `IS_AGENT_TYPE` boolean to conditionally hide attended transfer option for Agent type agents.

---

## 15. End Hold Delay Fix After Attended Transfer
**Date**: 2026-02-17

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/Llamada.class.php`
- `/etc/asterisk/extensions_custom.conf`

**Issue**: After an Agent type attended transfer consultation failed, pressing Hold then End Hold caused ~5 second delay.

**Fix**: Keep the agent in `Wait()` instead of `AgentLogin` after `Bridge()` ends during hold. Use `Redirect` + `Bridge()` to retrieve the parked call directly.

---

## 14. Attended Transfer Status Handling Improvements
**Date**: 2026-02-15

**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `setup/dialer_process/dialer/ECCPProxyConn.class.php`
- `modules/agent_console/themes/default/js/javascript.js`

**Feature**: Added consultation state tracking and button state management during attended transfer. Hold and Transfer buttons are disabled during consultation and re-enabled when consultation ends.

---

## 13. New Custom Context for Callback Extension Attended Transfer
**Date**: 2026-02-15

**Files**:
- `/etc/asterisk/extensions_custom.conf`
- `setup/installer.php`

**Feature**: Added `cbext-atxfer` context that dials device directly to avoid busy tone delay when callback agent's attended transfer target declines.

---

## 12. Agent Hold Feature Bug Fixes
**Date**: 2026-02-15

**Files**:
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/AMIClientConn.class.php`
- `setup/dialer_process/dialer/Llamada.class.php`
- `setup/dialer_process/dialer/ECCPConn.class.php`

**Issue 1**: Agent stuck in hold state when customer hangs up
**Issue 2**: Customer hears parking slot number on subsequent holds
**Issue 3**: Anonymous CallerID when retrieving call from hold

**Fix**: Updated AMI field names for Asterisk 13+ compatibility, suppressed parking slot announcement, added CallerID to originate call.

---

## 11. Attended Transfer Fix for Agent Type (app_agent_pool)
**Date**: 2026-02-06

**Files**:
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `setup/dialer_process/dialer/AMIEventProcess.class.php`
- `setup/dialer_process/dialer/Llamada.class.php`
- `setup/dialer_process/dialer/AMIClientConn.class.php`
- `/etc/asterisk/extensions_custom.conf`
- `setup/installer.php`

**Issue**: Attended transfer did not work for Agent type agents (app_agent_pool):
1. Transfer initiation failed because AMI Atxfer was called on `Agent/XXXX` (not a real channel)
2. Transfer completion failed because hanging up terminated the AgentLogin session

**Fix**: For Agent type, use `login_channel` for AMI commands. Added `atxfer-complete` context that re-enters AgentLogin after transfer completion.

---

## 10. Fix End Hold for Incoming Campaign
**Date**: 2026-02-06

**File**: `setup/dialer_process/dialer/ECCPConn.class.php`

**Fix**: Corrected logic for incoming campaign hold recovery that was incorrectly treating the operation as an internal transfer.

---

## 9. Fix Agent Break Status Update for Asterisk 18
**Date**: 2026-02-06

**File**: `setup/dialer_process/dialer/AMIEventProcess.class.php`

**Fix**: Updated break status handling to work correctly with Asterisk 18 AMI event format changes.

---

## 8. Real-Time Agent Ringing Status in Agents Monitoring
**Date**: Earlier

**Files**:
- `setup/dialer_process/dialer/ECCPProxyConn.class.php`
- `setup/dialer_process/dialer/Agente.class.php`
- `setup/dialer_process/dialer/SQLWorkerProcess.class.php`
- `modules/agent_console/libs/paloSantoConsola.class.php`
- `modules/rep_agents_monitoring/index.php`
- `modules/rep_agents_monitoring/themes/default/js/javascript.js`
- `modules/rep_agents_monitoring/images/agent-ringing.gif`
- `modules/rep_agents_monitoring/lang/*.lang`

**Issue**: Agents Monitoring module only showed "Ready" when agent's phone was ringing.

**Fix**: Added new ECCP event `AgentStateChange` emitted when agent status changes to/from ringing. Frontend updates in real-time via SSE.

---

## 7. Agent Ringing Status Display Fix
**Date**: Earlier

**Files**:
- `setup/dialer_process/dialer/Agente.class.php`
- `setup/dialer_process/dialer/ECCPConn.class.php`
- `modules/campaign_monitoring/lang/en.lang`
- `modules/campaign_monitoring/lang/es.lang`
- `modules/campaign_monitoring/themes/default/js/javascript.js`

**Issue**: In campaign monitoring, callback extension agents showed status "Free" while their phone was ringing.

**Fix**: Added `queue_status` field to agent info. Modified ECCP to send `'ringing'` status when `queue_status==6`. Removed incorrect frontend status inference logic.

---

## 6. Agent Console Duplicate Name Display Fix
**Date**: Earlier

**Files**:
- `modules/agent_console/index.php`
- `modules/agent_console/lang/en.lang`

**Issue**: In agent console Information section for outgoing calls, the second CSV column appeared twice.

**Fix**: Changed label from "Names" to "Name" (singular). Skip index 1 in dynamic attribute loop for outgoing calls.

---

## 5. Campaign Statistics Sync Fix
**Date**: Earlier

**File**: `modules/campaign_out/libs/paloSantoCampaignCC.class.php`

**Issue**: `campaign_out` showed stale `num_completadas` after dialer restart mid-call

**Fix**: Query `calls` table directly instead of using cached `campaign.num_completadas`

---

## 4. Agent Console Stuck After Hangup Fix
**Date**: Earlier

**File**: `setup/dialer_process/dialer/AMIEventProcess.class.php`

**Issue**: For local extension calls, pressing Hangup terminated client but agent stayed "Connected to call"

**Fix**: Added fallback search by `actualchannel` in msg_Hangup handler.

---

## 3. Agent Login Cancellation Fix
**Date**: Earlier

**File**: `setup/dialer_process/dialer/Agente.class.php`

**Issue**: Cancelling agent login left agent in inconsistent state

**Fix**: Properly handle login channel hangup during login process.

---

## 2. Call Status Initialization Bug Fix
**Date**: Earlier

**File**: `setup/dialer_process/dialer/Llamada.class.php`

**Issue**: Phone number not appearing in "Placing calls" section during customer ringing in campaign monitoring

**Fix**: Changed `if (!is_null($this->status))` to `if (is_null($this->status))`

---

## 1. Agent Queue Status Bug Fix
**Date**: Earlier

**File**: `setup/dialer_process/dialer/Agente.class.php`

**Issue**: `estadoEnCola()` function had backwards ternary operator logic

**Fix**: Reversed ternary operator to return actual status when queue exists.

---

## Technical Reference

### Call Status Flow (Outgoing Campaigns)
1. Call is created → Status is NULL
2. Originate starts → Status set to 'Placing' (if NULL)
3. Customer phone rings → Status remains 'Placing'
4. Customer answers → Call enters queue → Status set to 'OnQueue'
5. Agent assigned and answers → Status set to 'Success'

### Device Status Constants (app_agent_pool)
```php
AST_DEVICE_NOTINQUEUE = -1  // Not in any queue
AST_DEVICE_UNKNOWN    = 0   // Unknown state
AST_DEVICE_NOT_INUSE  = 1   // Free/Available
AST_DEVICE_INUSE      = 2   // In a call
AST_DEVICE_BUSY       = 3   // Busy
AST_DEVICE_INVALID    = 4   // Invalid
AST_DEVICE_UNAVAILABLE= 5   // Unavailable
AST_DEVICE_RINGING    = 6   // Phone ringing
AST_DEVICE_RINGINUSE  = 7   // Ringing while in use
AST_DEVICE_ONHOLD     = 8   // On hold
```

