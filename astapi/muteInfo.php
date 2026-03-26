<?php

function checkMuteaudioStatus($fp,$state,$thisnode,$node) {
	// returns true (1) if a database key is found,
	// returns false (0) if no database key is found.
	global $ami;
	static $errCnt=0;
	$actionRand = mt_rand();
	$actionID = 'checMuteaudioStatus' . $actionRand;
	$Request = "Action: Command\r\n";
	$Request .= "Command: database showkey $state/$thisnode/$node\r\n";
	$Request .= "ActionID: $actionID\r\n";
	$Request .= "\r\n";
	if (@fwrite($fp, $Request) > 0) {
		$response = $ami->getResponse($fp, $actionID);
		foreach($response as $line) {
			//if (preg_match("/Response: Success/", $line) == 1) {
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
function checkModuleSupport($fp,$module='res_mutestream') {
	// returns true (1) if the module (res_mutestream) module is available.
	// returns false (0) if the module is not available.
	global $ami;
	static $errCnt=0;
	$actionRand = mt_rand();
	$actionID = 'modulecheck' . $actionRand;
	$Request = "Action: ModuleCheck\r\n";
	$Request .= "Module: $module\r\n";
	$Request .= "ActionID: $actionID\r\n";
	$Request .= "\r\n";
	if (@fwrite($fp, $Request) > 0) {
		$response = $ami->getResponse($fp, $actionID);
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

?>
