# Issabel Call Center - Changes

Newest version first. Each point starts with its type.

History, frozen and not added to: `CHANGES_PRE.md` (numbered pre-release
entries) and `CHANGELOG_OLD.md` (release notes up to 5.0.0-10).

---

## 5.1.1

- **New feature**: `VERSION` file at the repo root is the single source of truth for the release number.
- **New feature**: The installer reads `VERSION` and deploys it, so a later install reports the installed version.
- **New feature**: TLS encryption for the ECCP protocol; port 20005 is now TLS-only.
- **New feature**: PJSIP trunks accepted by outgoing campaigns.
- **New feature**: Dedicated `callcenter_hold` parking lot.
- **Bug fix**: Outgoing campaign call no longer ends in the dialer the instant it connects when queue recording is off.
- **Bug fix**: Calls Detail recordings list expands again; the module no longer loads its own JS twice.
- **Bug fix**: Calls Detail lists a transferred call's recordings oldest first, so the visible one is the start of the call.
- **Bug fix**: Attended transfer no longer strands the caller on hold or leaves the console with dead buttons.
- **Bug fix**: Beep on end of hold now plays consistently.
- **Bug fix**: Scheduled-call agent reservation crashed on PHP 7.4.
- **Bug fix**: Scheduled call with "same agent" now reaches Agent-type agents instead of giving the customer a busy tone.
- **Bug fix**: Device-type defects on PJSIP and IAX2.
- **Bug fix**: Callback agents added to a queue while logged in stayed unusable until re-login.
- **Improve**: ECCP XML hardening - escaping helper, serialization fail-safe, explicit database charset.
- **Improve**: Installer requires Asterisk 18 and aborts before writing anything.
- **Improve**: Installer help documents the ECCP certificate variables.
- **Removed**: All RPM spec files and the legacy `build/2.5` and `build/4.0` trees; `build/5.0/install-issabel-callcenter.sh` is the only supported install path.
- **Removed**: Dead static queue member warning and the dead legacy call-placement path in `CampaignProcess`.
