<?php
require_once('../include/apiInit.php');
require_once('AMI.php');

if(!modifyOk())
	exit("Insufficient user permission to execute commands\n");

// Filter and validate user input
$fields = ['remotenode', 'button', 'localnode'];
foreach($fields as $f)
	$$f = isset($_POST[$f]) ? trim(strip_tags($_POST[$f])) : '';

if(!preg_match("/^\d+$/", $localnode) || !$localnode)
	exit("Invalid local node number\n");

if(!preg_match("/^\d+$/", $remotenode) || (!$remotenode))
	exit("Invalid remote node number\n");

chdir('..');

$msg = [];
if(!getAmiCfg($msg))
	exit('AMI credentials not found');

if($localnode != $amicfg->node)
	exit("Node $localnode not in AMI Cfgs");

// Open socket to Asterisk Manager
$ami = new AMI();
$fp = $ami->connect($amicfg->host, $amicfg->port);
if($fp === false)
	exit("Could not connect\n");

if($ami->login($fp, $amicfg->user, $amicfg->pass) === false)
	exit("Could not login\n");

switch($button) {
	case 'mute':
		$direction='in';
		$state=1;
		$dbCmd='database put mute/' . $localnode . ' ' . $remotenode . ' ""';
		break;
	case 'unmute':
		$direction='in';
		$state=0;
		$dbCmd='database del mute/' . $localnode . ' ' . $remotenode;
		break;
	case 'monitor':
		$direction='out';
		$state=1;
		$dbCmd='database put monitor/' . $localnode . ' ' . $remotenode . ' ""';
		break;
	case 'unmonitor':
		$direction='out';
		$state=0;
		$dbCmd='database del monitor/' . $localnode . ' ' . $remotenode;
		break;
}
$resp = mutemonitor($fp,$localnode,$remotenode,$direction,$state);
if ($resp) $resp = $ami->command($fp, $dbCmd);
echo "<br>" . $resp;
fclose($fp);
exit();

function rpthost($node) {
	// Perform DNS lookup for TXT record (contains node ip:port info)
	if ($node >= 3000000 || $node < 2000) return "";
	$domain = "$node.nodes.allstarlink.org";
	$records = dns_get_record($domain, DNS_TXT);
	if ($records && isset($records[0]['entries'])) {
		$txtData = $records[0]['entries'][2];
		$parts = explode('=', $txtData);
		if (count($parts) >= 2) {
			$port = $parts[1];
		} else {
			echo 'rpthost DNS port lookup failed!';
		}
		$txtData = $records[0]['entries'][1];
		$parts = explode('=', $txtData);
		if (count($parts) >= 2) {
			$ip = $parts[1];
			return "$ip:$port";
		} else {
			echo 'rpthost DNS IP lookup failed!';
			return "";
		}
	} else {
		echo 'rpthost DNS lookup failed!';
		return "";
	}
}

function muteaudio($fp,$channel,$direction,$state) {
	// Send MuteAudio action for channel
	global $ami;
	static $errCnt=0;
	$actionRand = mt_rand();
	$actionID = 'muteaudio_' . $actionRand;
	$muteRequest = "Action: MuteAudio\r\n";
	$muteRequest .= "Channel: $channel\r\n";
	$muteRequest .= "Direction: $direction\r\n";
	$muteRequest .= "State: $state\r\n";
	$muteRequest .= "ActionID: $actionID\r\n";
	$muteRequest .= "\r\n";
	if (fwrite($fp, $muteRequest) !== false) {
		$response = $ami->getResponse($fp, $actionID);
		return $response;
	}else{
		echo 'muteaudio function failed!';
		// On ASL3 if Asterisk restarts above error repeats indefinitely. Let client JS reinit connection.
		if(++$errCnt > 9)
			exit();
	}
	return;
}

function mutemonitor($fp,$thisNode,$targetNode,$direction,$state) {
	// Find the asterisk channel for a given node and set mute audio
	global $ami;
	static $errCnt=0;
	// Find the Asterisk channel for a node and mute as requested
	if ($targetNode >= 3000000) {
		$targetNode = substr("$targetNode",2);
		$targetNode = substr('0000' . "$targetNode",-6);
		$targetNode = '3' . "$targetNode";
	}
	$ip = rpthost($targetNode);
	$actionRand = mt_rand(); // AMI actionID
        $actionID = 'core' . $actionRand;
        if(fwrite($fp, "ACTION: Command\r\nCOMMAND: core show channels concise\r\nActionID: $actionID\r\n\r\n") !== false) {
                $channels = $ami->getResponse($fp, $actionID);
        } else {
                echo 'core show channels concise failed!';
                // On ASL3 if Asterisk restarts above error repeats indefinitely. Let client JS reinit connection.
                if(++$errCnt > 9)
                        exit();
        }
	foreach ($channels as $line) {
		$fields = explode('!', $line);
		if (count($fields) < 14) continue; // Skip malformed lines
		if ($fields[2] === $thisNode) {
			if ("$fields[7]" === "$targetNode") {
				$response = muteaudio($fp,trim(substr($fields[0],8)),$direction,$state);
				foreach($response as $l) {
					if (preg_match("/Response: Success/", $l) == 1) {
						echo "MUTEAUDIO $thisNode $targetNode $direction $state was successful!";
						return(TRUE);
					}
				}
				echo 'mutemonitor function failed!';
				// On ASL3 if Asterisk restarts above error repeats indefinitely. Let client JS reinit connection.
				if(++$errCnt > 9)
					exit();
				return(FALSE);
			}
		}
		// Outgoing ASL connections do not set the node number in the channel listing,
		// so use IP address to find it.
		if ($ip && strpos($fields[0],$ip) > 0) {
			$response = muteaudio($fp,trim(substr($fields[0],8)),$direction,$state);
			foreach($response as $l) {
				if (preg_match("/Response: Success/", $l)) {
					echo "MUTEAUDIO $thisNode $targetNode $direction $state was successful!";
					return(TRUE);
				}
			}
			echo 'mutemonitor function failed!<br>';
			// On ASL3 if Asterisk restarts above error repeats indefinitely. Let client JS reinit connection.
			if(++$errCnt > 9)
				exit();
			return(FALSE);
		}
	}
}

?>
