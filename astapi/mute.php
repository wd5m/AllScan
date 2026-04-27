<?php
require_once('../include/apiInit.php');
require_once('AMI.php');

if(!modifyOk())
	exit("Insufficient user permission to execute commands\n");

// Filter and validate user input
$fields = ['remotenode', 'button', 'localnode', 'conndir'];
foreach($fields as $f)
	$$f = isset($_POST[$f]) ? trim(strip_tags($_POST[$f])) : '';

if(!preg_match("/^\d+$/", $localnode) || !$localnode)
	exit("Invalid local node number\n");

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

// Set state and direction vars from button action
switch($button) {
	case 'mute':
		$direction='in';
		$state=1;
		break;
	case 'unmute':
		$direction='in';
		$state=0;
		break;
	case 'monitor':
		$direction='out';
		$state=1;
		break;
	case 'unmonitor':
		$direction='out';
		$state=0;
		break;
}
// Find the Allstar node Asterisk channel and set the muteaudio state and direction.
mutemonitor($fp,$localnode,$remotenode,$direction,$state,$conndir);
fclose($fp);
exit();

function muteaudio($fp,$channel,$direction,$state,$localnode,$remotenode): array {
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
		foreach($response as $l) {
			if (preg_match("/Response: Success/", $l)) {
				if ($state) {
					$dbCmd = 'database put ' . ($direction === "in" ? 'mute/' : 'monitor/') . $localnode . ' ' . $remotenode . ' "' . $channel . '"';
					$resp = $ami->command($fp, $dbCmd);
				} else {
					$dbCmd = 'database del ' . ($direction === "in" ? 'mute/' : 'monitor/') . $localnode . ' ' . $remotenode;
					$resp = $ami->command($fp, $dbCmd);
				}
			}
		}
		return $response;
	}else{
		echo 'muteaudio function failed!';
		// On ASL3 if Asterisk restarts above error repeats indefinitely. Let client JS reinit connection.
		if(++$errCnt > 9)
			exit();
	}
	return $response;
}

function mutemonitor($fp,$thisNode,$targetNode,$direction,$state,$conndir): bool {
	// Find the asterisk channel for a given node and set mute audio
	global $ami;
	static $errCnt=0;

	$channels = [];
	$channels = $ami->getCoreShowChannels($fp);
	if (empty($channels)) {
		echo "No active channels found.";
	} else {
		foreach ($channels as $ch) {
			if (strcasecmp($ch['Application'], 'rpt') !== 0) continue;
			$channelName	= $ch['Channel'];
			$callerIdNum	= $ch['CallerIDNum'];
			$connectedLine	= $ch['ConnectedLineNum'];
			$extension	= $ch['Exten'];
			$channelType	= $ami->getChannelVar($fp, $channelName, 'CHANNEL(channeltype)');
			if ($conndir === 'OUT') {
				// outgoing connections
				if ($connectedLine === $thisNode && $extension === $targetNode) {
					if ($channelType === 'echolink' || $channelType === 'tlb') {
						// Allstar is swapping the audio in / out direction for tlb and EchoLink connections.
						$res = $ami->setMuteAudio($fp, $channelName, ($direction === "in" ? 'out' : 'in'), $state);
					} else {
						$res = $ami->setMuteAudio($fp, $channelName, $direction, $state);
					}
					if ($res) {
						if ($state) {
							$dbCmd = 'database put ' . ($direction === "in" ? 'mute/' : 'monitor/') . $thisNode . ' ' . $targetNode . ' "' . $channelName . '"';
							$resp = $ami->command($fp, $dbCmd);
						} else {
							$dbCmd = 'database del ' . ($direction === "in" ? 'mute/' : 'monitor/') . $thisNode . ' ' . $targetNode;
							$resp = $ami->command($fp, $dbCmd);
						}
						echo "MUTEAUDIO $thisNode $targetNode $channelName $direction $state succeeded!";
						return(TRUE);
					}
				}
			} else {
				// incoming connections
				if ($extension === $thisNode && $callerIdNum === $targetNode) {
					if ($ami->setMuteAudio($fp, $channelName, $direction, $state) === TRUE) {
						if ($state) {
							$dbCmd = 'database put ' . ($direction === "in" ? 'mute/' : 'monitor/') . $thisNode . ' ' . $targetNode . ' "' . $channelName . '"';
							$resp = $ami->command($fp, $dbCmd);
						} else {
							$dbCmd = 'database del ' . ($direction === "in" ? 'mute/' : 'monitor/') . $thisNode . ' ' . $targetNode;
							$resp = $ami->command($fp, $dbCmd);
						}
						echo "MUTEAUDIO $thisNode $targetNode $channelName $direction $state succeeded!";
						return(TRUE);
					}
				}
			}
		}
	}
	echo "Failed to match $targetNode to Asterisk channel!";
	return(FALSE);

}

