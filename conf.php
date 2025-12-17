<?php

/*
	FritzBox configuration file
	written by brodikAE
	v.0.0.2
*/

$FRITZ = [
    'ip' => "192.168.178.1",
    'password' => "mypassword",
    'db_file' => "traffic.db",
    'days' => 60,
    'debug' => false
];

$MAC_NAMES = [
    '00:11:22:33:44:55' => 'my device 1',
    '66:77:88:99:AA:BB' => 'my device 2'
    // other mac address
];

date_default_timezone_set('Europe/Rome');

?>
