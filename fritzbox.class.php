<?php

/*
	FritzBox webAPI php
	written by brodikAE
	v.0.0.2
*/

class FritzBox{

	/*
	Start up the class
	*/

	function __construct($ip, $password, $db, $days, $debug) {
		$this->ip = $ip;
		$this->password = $password;
		$this->db_file = $db;
		$this->purge_days = $days;
		$this->debug = $debug;
		$this->sid = false;
		$this->db = false;
	}

	/*
	Get FritzBox Sid for login
	*/

	function getFritzBoxSid() {
		$url = "http://" . $this->ip . "/login_sid.lua";
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		if ($response === false) {
			if ($this->debug) echo "Errore connessione a " . $url . ": " . curl_error($ch) . "<br>";
			curl_close($ch);
			return false;
		}
		curl_close($ch);
		$xml = simplexml_load_string($response);
		if (!$xml || !isset($xml->Challenge)) {
			if ($this->debug) echo "Risposta XML non valida da " . $url . ": " . htmlspecialchars($response) . "<br>";
			return false;
		}
		$challenge = (string)$xml->Challenge;
		$username = (string)$xml->Users->User;
		if (!$username) {
			if ($this->debug) echo "Username non trovato nella risposta: " . htmlspecialchars($response) . "<br>";
			return false;
		}
		$challengeResponse = $challenge . '-' . md5(mb_convert_encoding($challenge . '-' . $this->password, 'UTF-16LE'));
		$loginUrl = "http://" . $this->ip . "/login_sid.lua?username=" . urlencode($username) . "&response=" . $challengeResponse;
		$ch = curl_init($loginUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$loginResponse = curl_exec($ch);
		if ($loginResponse === false) {
			if ($this->debug) echo "Errore connessione a " . $loginUrl . ": " . curl_error($ch) . "<br>";
			curl_close($ch);
			return false;
		}
		curl_close($ch);
		$loginXml = simplexml_load_string($loginResponse);
		if (!$loginXml || !isset($loginXml->SID)) {
			if ($this->debug) echo "Risposta XML non valida da " . $loginUrl . ": " . htmlspecialchars($loginResponse) . "<br>";
			return false;
		}
		$sid = (string)$loginXml->SID;
		$this->sid = ($sid !== "0000000000000000") ? $sid : false;
		return $this->sid;
	}

	/*
	Get FritzBox network usage
	*/

	function getNetworkUsage() {
		$url = "http://" . $this->ip . "/api/v0/monitor/online/0";
		if ($this->debug) echo "Tentativo di connessione a: $url<br>";
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:136.0) Gecko/20100101 Firefox/136.0");
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"AUTHORIZATION: AVM-SID " . $this->sid . "",
			"Accept: */*",
			"Content-Type: application/json"
		));
		$response = curl_exec($ch);
		if ($response === false) {
			if ($this->debug) echo "Errore connessione a " . $url . ": " . curl_error($ch) . "<br>";
			curl_close($ch);
			return false;
		}
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($this->debug) echo "Codice HTTP: " . $httpCode . "<br>";
		curl_close($ch);
		if ($this->debug) echo "Risposta grezza dall'API: " . htmlspecialchars($response) . "<br>";
		$data = json_decode($response, true);
		if ($data === null) {
			if ($this->debug) echo "Risposta JSON non valida da " . $url . ": " . htmlspecialchars($response) . "<br>";
			return false;
		}
		if (isset($data['errors'])) {
			if ($this->debug) echo "Errore API: " . print_r($data['errors'], true) . "<br>";
			return false;
		}
		return $data;
	}

	/*
	Initialize sqlite DB
	*/

	function initDatabase() {
		$db = new SQLite3($this->db_file);
		$db->busyTimeout(5000);
		$db->exec("CREATE TABLE IF NOT EXISTS all_stats (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			mac TEXT NOT NULL,
			timestamp TEXT NOT NULL,
			received_bytes INTEGER NOT NULL,
			sent_bytes INTEGER NOT NULL,
			UNIQUE(mac, timestamp)
		)");
		$db->exec("CREATE TABLE IF NOT EXISTS monthly_stats (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			mac TEXT NOT NULL,
			year_month TEXT NOT NULL,
			received_bytes INTEGER NOT NULL,
			sent_bytes INTEGER NOT NULL,
			UNIQUE(mac, year_month)
		)");
		$this->db = $db;
		return $db;
	}

	/*
	Save data into sqlite DB
	*/

	function saveDataToDatabase($data) {
		if (!isset($data['devices']) || !isset($data['lastSampleTime']) || !isset($data['sampleInterval'])) {
			if ($this->debug) echo "Struttura dati non valida o mancante\n";
			return;
		}

		$devices = $data['devices'];
		$lastSampleTime = $data['lastSampleTime'];
		$intervalSec = $data['sampleInterval'] / 1000;

		foreach ($devices as $device) {
			$uid = $device['UID'] ?? '';
			if (empty($uid) || strpos($uid, 'mac') !== 0) continue;

			$mac = substr($uid, 3);
			$upstream = $device['upstream'] ?? [];
			$downstream = $device['downstream'] ?? [];

			$numSamples = min(count($upstream), count($downstream));

			for ($i = 0; $i < $numSamples; $i++) {
				$offset = ($numSamples - 1 - $i) * $intervalSec;
				$time = date('Y-m-d H:i:s', $lastSampleTime - $offset);

				$sent = $upstream[$i] ?? 0;
				$received = $downstream[$i] ?? 0;

				$stmt = $this->db->prepare("INSERT OR IGNORE INTO all_stats (mac, timestamp, received_bytes, sent_bytes) VALUES (:mac, :timestamp, :received, :sent)");
				$stmt->bindValue(':mac', $mac, SQLITE3_TEXT);
				$stmt->bindValue(':timestamp', $time, SQLITE3_TEXT);
				$stmt->bindValue(':received', $received, SQLITE3_INTEGER);
				$stmt->bindValue(':sent', $sent, SQLITE3_INTEGER);
				$stmt->execute();
			}
		}
	}

	/*
	Purge data older than defined days, keeping monthly stats
	*/

	function purgeOldData() {
		$days = $this->purge_days;
		$cutoffDate = date('Y-m-d H:i:s', strtotime("-$days days"));
		$query = "SELECT mac, strftime('%Y-%m', timestamp) AS year_month, 
						 SUM(received_bytes) AS received_bytes, 
						 SUM(sent_bytes) AS sent_bytes
				  FROM all_stats 
				  WHERE timestamp < :cutoff 
				  GROUP BY mac, strftime('%Y-%m', timestamp)";
		$stmt = $this->db->prepare($query);
		$stmt->bindValue(':cutoff', $cutoffDate, SQLITE3_TEXT);
		$result = $stmt->execute();

		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			$insertStmt = $this->db->prepare("INSERT OR REPLACE INTO monthly_stats (mac, year_month, received_bytes, sent_bytes) 
											 VALUES (:mac, :year_month, :received, :sent)");
			$insertStmt->bindValue(':mac', $row['mac'], SQLITE3_TEXT);
			$insertStmt->bindValue(':year_month', $row['year_month'], SQLITE3_TEXT);
			$insertStmt->bindValue(':received', $row['received_bytes'], SQLITE3_INTEGER);
			$insertStmt->bindValue(':sent', $row['sent_bytes'], SQLITE3_INTEGER);
			$insertStmt->execute();
		}

		$deleteStmt = $this->db->prepare("DELETE FROM all_stats WHERE timestamp < :cutoff");
		$deleteStmt->bindValue(':cutoff', $cutoffDate, SQLITE3_TEXT);
		$deleteStmt->execute();

		if ($this->debug) {
			echo "Purge completato: dati più vecchi di $days giorni rimossi, statistiche mensili salvate\n";
		}
	}

}

?>
