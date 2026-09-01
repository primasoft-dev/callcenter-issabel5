#!/usr/bin/php
<?php
/**
 * ECCP Example: Attended Transfer Consultation Start Event
 *
 * This example demonstrates the "consultationstart" server-side event, which is
 * generated when the agent starts an attended transfer and the colleague's
 * extension begins to ring.
 *
 * The example triggers the event itself with the atxfercall() request and then
 * waits for the event to arrive, in the same trigger-then-listen style used by
 * agentlogin.php.
 *
 * USAGE:
 *   ./consultationstart.php [agent-channel] [agent-password] [extension]
 *
 * ARGUMENTS:
 *   agent-channel   - Agent channel (e.g., Agent/9000, SIP/1001)
 *   agent-password  - Agent password
 *   extension       - Extension of the colleague to consult (e.g., 9001)
 *
 * EVENT PAYLOAD:
 *   <event><consultationstart><agent_number>Agent/9000</agent_number>
 *   </consultationstart></event>
 *
 * TEST SCENARIOS:
 *
 *   1. Verify dialer is running:
 *      systemctl status issabeldialer
 *
 *   2. Start the consultation and wait for the event:
 *      su - asterisk -c "/opt/issabel/dialer/eccp-examples/consultationstart.php Agent/9000 password 9001"
 *
 *   3. A colleague who is busy with Call Waiting off, or on DND, is refused
 *      before any channel is moved. In that case no consultationstart arrives
 *      at all and the example exits on the consultationend instead.
 *
 *   4. Monitor dialer logs during testing:
 *      tail -f /opt/issabel/dialer/dialerd.log | grep -iE "consulta|atxfer"
 *
 * PREREQUISITES:
 *   - Agent must be logged in (see agentlogin.php)
 *   - Agent must be on an active call to transfer
 */
require_once ("/var/www/html/modules/agent_console/libs/ECCP.class.php");

if (count($argv) < 4) die("Use: {$argv[0]} agentchannel agentpassword extension\n");
$agentname = $argv[1];
$agentpass = $argv[2];
$extension = $argv[3];

$x = new ECCP();
try {
	print "Connect...\n";
	$cr = $x->connect("localhost", "agentconsole", "agentconsole");
	if (isset($cr->failure)) die('Failed to connect to ECCP - '.$cr->failure->message."\n");
	$x->setAgentNumber($agentname);
	$x->setAgentPass($agentpass);
	print_r($x->getAgentStatus());
	print "Iniciando consulta de transferencia atendida... | EN: Starting attended transfer consultation...\n";
	$r = $x->atxfercall($extension);
	print_r($r);
	$bListo = FALSE;
	if (!isset($r->failure)) while (!$bListo) {
		$x->wait_response(1);
		while ($e = $x->getEvent()) {
			print_r($e);
			foreach ($e->children() as $ee) $evt = $ee;
			if ($evt->getName() == 'consultationstart') {
				print "Consultation started for agent ".$evt->agent_number."\n";
				$bListo = TRUE;
				break;
			}
			/* El colega ocupado con Call Waiting apagado, o en DND, es
			 * rechazado al instante: la consulta termina sin haber empezado. */
			/* EN: A colleague busy with Call Waiting off, or on DND, is refused
			 * instantly: the consultation ends without ever having started. */
			if ($evt->getName() == 'consultationend') {
				print "Consultation ended before it started, reason: ".
					(isset($evt->reason) ? $evt->reason : '(none)')."\n";
				$bListo = TRUE;
				break;
			}
		}
	}
	print "Disconnect...\n";
	$x->disconnect();
} catch (Exception $e) {
	print_r($e);
	print_r($x->getParseError());
}
?>
