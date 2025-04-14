<?php

/*
+-----------------------------------------------------------------------+
|																		                                    |
|	> fritzbox scan usage  										                        		|
|	> Written By brodikAE										                          		|
|	> Date started: 13.04.2025									                      		|
|	> version: 0.0.1 (13.04.2025)							                      			|
|																                                    		|
+-----------------------------------------------------------------------+
*/

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
ini_set('max_execution_time', 300);

require_once "conf.php";
require_once "fritzbox.class.php";

$FritzBox = new FritzBox($FRITZ['ip'], $FRITZ['password'], $FRITZ['db_file'], $FRITZ['days'], $FRITZ['debug']);

scan();

function scan(){
	global $FritzBox;

	$success = false;
	$sid = $FritzBox->getFritzBoxSid();
	if (!$sid) {
		if ($FritzBox->debug) echo "Errore di autenticazione\n";
	} else {
		if ($FritzBox->debug) echo "SID ottenuto: $sid\n";
		$FritzBox->initDatabase();
		$data = $FritzBox->getNetworkUsage();
		if ($data) {
			$FritzBox->saveDataToDatabase($data);
			if ($FritzBox->debug) echo "Dati salvati con successo\n";
			$FritzBox->purgeOldData();
			$success = true;
		} else {
			if ($FritzBox->debug) echo "Errore nel recupero dei dati\n";
		}
		$FritzBox->db->close();
	}
	header('Content-Type: application/json');
	print json_encode(array("success" => $success));
	exit($success ? 0 : 1);
}

?>
