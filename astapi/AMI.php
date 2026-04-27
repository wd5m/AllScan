<?php
define('AMI_DEBUG_LOG', 'log.txt');

class AMI {
public $aslver = '2.0/unknown';

function connect($ip, $port) {
	if(!validIpAddr($ip) || !$port)
		return false;
	return fsockopen($ip, $port, $errno, $errstr, 5);
}

function login($fp, $user, $password) {
	$actionID = $user . $password;
	fwrite($fp,"ACTION: LOGIN\r\nUSERNAME: $user\r\nSECRET: $password\r\nEVENTS: 0\r\nActionID: $actionID\r\n\r\n");
	$res = $this->getResponse($fp, $actionID);
	// logToFile('RES: ' . varDumpClean($res, true), AMI_DEBUG_LOG);
	$ok = (strpos($res[2], "Authentication accepted") !== false);
	if(!$ok)
		return false;
	// Determine App-rpt version. ASL3 and Asterisk 20 have some differences in AMI commands
	// eg. in ASL2 restart command is "restart now" but in ASL3 it's "core restart now".
	$s = $this->command($fp, 'rpt show version');
	if(preg_match('/app_rpt version: ([0-9\.]{1,9})/', $s, $m) == 1)
		$this->aslver = $m[1];
	return $ok;
}

function command($fp, $cmdString, $debug=false) {
	// Generate ActionID to associate with response
	$actionID = 'cpAction_' . mt_rand();
	$ok = true;
	$msg = [];
	if((fwrite($fp, "ACTION: COMMAND\r\nCOMMAND: $cmdString\r\nActionID: $actionID\r\n\r\n")) > 0) {
		if($debug)
			logToFile('CMD: ' . $cmdString . ' - ' . $actionID, AMI_DEBUG_LOG);
		$res = $this->getResponse($fp, $actionID, $debug);
		if(!is_array($res))
			return $res;
		// Check for Asterisk AMI Success/Error response
		foreach($res as $r) {
			if($r === 'Response: Error')
				$ok = false;
			elseif(preg_match('/Output: (.*)/', $r, $m) == 1)
				$msg[] = $m[1];
		}
		if(_count($msg))
			return implode(NL, $msg);
		if($ok)
			return 'OK';
		return 'ERROR';
	}
	return "Get node $cmdString failed";
}

/* 	Example ASL2 AMI response: 
		Response: Follows
		Privilege: Command
		ActionID: cpAction_...
		--END COMMAND--
	Example ASL3 AMI response:
		Response: Success
		Command output follows
		Output:
		ActionID: cpAction_...
	=> "Response:" line indicates success of associated ActionID.
*/

function getResponse($fp, $actionID, $debug=false) {
	$ignore = ['Privilege: Command', 'Command output follows'];
	$t0 = time();
	$response = [];
	if($debug)
		$sn = getScriptName();
	while(time() - $t0 < 20) {
		$str = fgets($fp);
		if($str === false)
			return $response;
		$str = trim($str);
		if($str === '')
			continue;
		if($debug)
			logToFile("$sn 1: $str", AMI_DEBUG_LOG);
		if(strpos($str, 'Response: ') === 0) {
			$response[] = $str;
		} elseif($str === "ActionID: $actionID") {
			$response[] = $str;
			while(time() - $t0 < 20) {
				$str = fgets($fp);
				if($str === "\r\n" || $str[0] === "\n" || $str === false)
					return $response;
				$str = trim($str);
				if($str === '' || in_array($str, $ignore))
					continue;
				$response[] = $str;
				if($debug)
					logToFile("$sn 2: $str", AMI_DEBUG_LOG);
			}
		}
	}
	if(count($response))
		return $response;
	logToFile("$sn: Timeout", AMI_DEBUG_LOG);
	return 'Timeout';
}

	// ── MuteAudio functions  ────────────────────────────────────────────────────
	// These functions are used in server.php and mute.php for muteaudio feature.
	function checkMuteaudioStatus($fp, string $state, string $thisnode, $node): bool {
		// returns true (1) if a database key is found,
		// returns false (0) if no database key is found.
		static $errCnt=0;
		$actionRand = mt_rand();
		$actionID = 'checMuteaudioStatus' . $actionRand;
		$Request = "Action: Command\r\n";
		$Request .= "Command: database showkey $state/$thisnode/$node\r\n";
		$Request .= "ActionID: $actionID\r\n";
		$Request .= "\r\n";
		if (@fwrite($fp, $Request) > 0) {
			$response = $this->getResponse($fp, $actionID);
			foreach($response as $line) {
				if (preg_match("/ 0 results found./", $line) == 1) {
					return 0;
				}
			}
		}else{
			sendData(['status'=>'checkMutestatus function failed!']);
			// On ASL3 if Asterisk restarts above error repeats indefinitely. Let client JS reinit connection.
			if(++$errCnt > 9)
				exit();
			return 0;
		}
		return 1;
	}

	function checkModuleSupport($fp, string $module='res_mutestream'): bool {
		// returns true (1) if the module (res_mutestream) module is available.
		// returns false (0) if the module is not available.
		static $errCnt=0;
		$actionRand = mt_rand();
		$actionID = 'modulecheck' . $actionRand;
		$Request = "Action: ModuleCheck\r\n";
		$Request .= "Module: $module\r\n";
		$Request .= "ActionID: $actionID\r\n";
		$Request .= "\r\n";
		if (@fwrite($fp, $Request) > 0) {
			$response = $this->getResponse($fp, $actionID);
			foreach($response as $line) {
				if (preg_match("/Response: Success/", $line) == 1) {
					return 1;
				}
			}
		}else{
			sendData(['status'=>'moduleCheck function failed!']);
			// On ASL3 if Asterisk restarts above error repeats indefinitely. Let client JS reinit connection.
			if(++$errCnt > 9)
				exit();
			return 0;
		}
		return 0;
	}
	// ── High-Level Actions ────────────────────────────────────────────────────
	/**
	* Issue a CoreShowChannels action and collect all channel entries.
	*
	* CoreShowChannels responds with:
	*   • Zero or more  Event: CoreShowChannel  blocks (one per channel)
	*   • A final       Event: CoreShowChannelsComplete  block
	*
	* @return list<array<string, string>>
	*/
	function getCoreShowChannels($fp): array {
		$this->sendAction($fp, [
			'Action'   => 'CoreShowChannels',
			'ActionID' => 'channels_' . uniqid(),
		]);
		$channels = [];
		while (true) {
			$block = $this->readResponse($fp);
			if (empty($block)) {
			// Socket timed out or closed unexpectedly
				break;
			}
			$event = $block['Event'] ?? '';
			if ($event === 'CoreShowChannel') {
				$channels[] = $block;
			} elseif ($event === 'CoreShowChannelsComplete') {
				break;   // All channel blocks have been received
			}
			// Ignore unrelated events (e.g. queued events from Asterisk)
		}
		return $channels;
	}

	/**
	* Issue a GetVar action to retrieve a single channel variable.
	*
	* Works for both plain variables (e.g. MYVAR) and CDR fields
	* expressed as CDR(src), CDR(dst), etc.
	*
	* Returns the variable's value, or an empty string if not set.
	*/
	function getChannelVar($fp, string $channel, string $variable): string {
		$actionId = 'getvar_' . uniqid();
		$this->sendAction($fp, [
			'Action'   => 'GetVar',
			'Channel'  => $channel,
			'Variable' => $variable,
			'ActionID' => $actionId,
		]);
		// Response is a single block:
		//   Response: Success
		//   ActionID: ...
		//   Variable: CDR(src)
		//   Value: 5551234567
		$block = $this->readResponse($fp);
		if (($block['Response'] ?? '') === 'Success') {
			return $block['Value'] ?? '';
		}
		return '';
	}

	/**
	* Mute or unmute a channel using the MuteAudio AMI action.
	* Direction: 'in', 'out', or 'all'
	* State: 'on' or 'off'
	*/
	function setMuteAudio($fp, string $channel, string $direction, string $state): bool {
		$actionId = 'setmute_' . uniqid();
		$this->sendAction($fp, [
			'Action'    => 'MuteAudio',
			'Channel'   => $channel,
			'Direction' => $direction,
			'State'     => $state,
			'ActionID'  => $actionId,
		]);
		// Response is a single block:
		//   Response: Success
		//   ActionID: ...
		$block = $this->readResponse($fp);
		if (($block['Response'] ?? '') === 'Success') {
			return $block['Value'] ?? 1;
		}
		return 0;
	}
	
	// ── Core I/O ──────────────────────────────────────────────────────────────
	
	/**
	* Write a key→value action block to the socket.
	*
	* @param array<string, string> $fields
	*/
	function sendAction($fp, array $fields): void {
		$packet = '';
		foreach ($fields as $key => $value) {
			$packet .= "{$key}: {$value}\r\n";
		}
		$packet .= "\r\n";   // blank line terminates the action
		@fwrite($fp, $packet);
	}
	
	/**
	* Read lines from the socket until a blank line is encountered.
	* Returns an associative array of the key: value pairs received.
	*
	* @return array<string, string>
	*/
	function readResponse($fp): array {
		$data = [];
		while (($line = fgets($fp)) !== false) {
			$line = rtrim($line, "\r\n");
			if ($line === '') {
				break;   // blank line = end of this response block
			}
			// Split on the FIRST colon only so values may contain colons
			$pos = strpos($line, ':');
			if ($pos !== false) {
				$key         = trim(substr($line, 0, $pos));
				$value       = trim(substr($line, $pos + 1));
				$data[$key]  = $value;
			}
		}
		return $data;
	}

}
