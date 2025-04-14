<?php

/*
+-----------------------------------------------------------------------+
|									|
|	> fritzbox usage monitor					|
|	> Written By brodikAE						|
|	> Date started: 13.04.2025					|
|	> version: 0.0.1 (13.04.2025)					|
|									|
+-----------------------------------------------------------------------+
*/

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 'On');
ini_set('display_startup_errors', 'On');
ini_set('max_execution_time', 300);

require_once "conf.php";
require_once "fritzbox.class.php";

$FritzBox = new FritzBox($FRITZ['ip'], $FRITZ['password'], $FRITZ['db_file'], $FRITZ['days'], $FRITZ['debug']);

home();

function getStats($db, $mac, $period) {
	$stats = ['labels' => [], 'received' => [], 'sent' => [], 'table' => []];
	$query = "";

	switch ($period) {
		case '10minutes':
			$query = "SELECT strftime('%Y-%m-%d %H:%M:00', 
                            datetime((cast(strftime('%s', timestamp) AS integer) / 600 * 600), 'unixepoch')) AS period, 
                            SUM(received_bytes) AS received, SUM(sent_bytes) AS sent 
                      FROM all_stats 
                      WHERE mac = :mac AND timestamp >= datetime('now', '-24 hours') 
                      GROUP BY strftime('%Y-%m-%d %H:%M', 
                            datetime((cast(strftime('%s', timestamp) AS integer) / 600 * 600), 'unixepoch')) 
                      ORDER BY period ASC LIMIT 144";
            break;
		case 'hourly':
			$query = "SELECT strftime('%Y-%m-%d %H:00', timestamp) AS period, 
							 SUM(received_bytes) AS received, SUM(sent_bytes) AS sent 
					  FROM all_stats 
					  WHERE mac = :mac 
					  GROUP BY strftime('%Y-%m-%d %H', timestamp) 
					  ORDER BY period ASC LIMIT 24";
        break;
		case 'daily':
			$query = "SELECT strftime('%Y-%m-%d', timestamp) AS period, 
							 SUM(received_bytes) AS received, SUM(sent_bytes) AS sent 
					  FROM all_stats 
					  WHERE mac = :mac 
					  GROUP BY strftime('%Y-%m-%d', timestamp) 
					  ORDER BY period ASC LIMIT 30";
        break;
		case 'weekly':
			$query = "SELECT strftime('%Y-%W', timestamp) AS period, 
							 SUM(received_bytes) AS received, SUM(sent_bytes) AS sent 
					  FROM all_stats 
					  WHERE mac = :mac 
					  GROUP BY strftime('%Y-%W', timestamp) 
					  ORDER BY period ASC LIMIT 12";
			break;
		case 'monthly':
			$query = "SELECT period, SUM(received) AS received, SUM(sent) AS sent 
					  FROM (
						  SELECT strftime('%Y-%m', timestamp) AS period, 
								 SUM(received_bytes) AS received, 
								 SUM(sent_bytes) AS sent 
						  FROM all_stats 
						  WHERE mac = :mac 
						  GROUP BY strftime('%Y-%m', timestamp)
						  UNION ALL
						  SELECT year_month AS period, 
								 received_bytes AS received, 
								 sent_bytes AS sent 
						  FROM monthly_stats 
						  WHERE mac = :mac
					  ) 
					  GROUP BY period 
					  ORDER BY period ASC LIMIT 12";
        break;
		case 'yearly':
            $query = "SELECT period, SUM(received) AS received, SUM(sent) AS sent 
					  FROM (
						  SELECT strftime('%Y', timestamp) AS period, 
								 SUM(received_bytes) AS received, 
								 SUM(sent_bytes) AS sent 
						  FROM all_stats 
						  WHERE mac = :mac 
						  GROUP BY strftime('%Y', timestamp)
						  UNION ALL
						  SELECT strftime('%Y', year_month) AS period, 
								 received_bytes AS received, 
								 sent_bytes AS sent 
						  FROM monthly_stats 
						  WHERE mac = :mac
					  ) 
					  GROUP BY period 
					  ORDER BY period ASC LIMIT 5";
        break;
		default:
			return $stats;
	}

	$stmt = $db->prepare($query);
	$stmt->bindValue(':mac', $mac, SQLITE3_TEXT);
	$result = $stmt->execute();

	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$stats['labels'][] = $row['period'];
		$stats['received'][] = $row['received'] / 1024;
		$stats['sent'][] = $row['sent'] / 1024;
		$stats['table'][] = [
			'period' => $row['period'],
			'received' => formatBytes($row['received']),
			'sent' => formatBytes($row['sent'])
		];
	}

	return $stats;
}

function formatBytes($bytes) {
	if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
	if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
	if ($bytes >= 1024) return round($bytes / 1024, 2) . ' kB';
	return $bytes . ' B';
}

function getDeviceName($mac, $mac_names) {
    foreach ($mac_names as $key => $name) {
        if (normalizeMac($key) === $mac) {
            return $name;
        }
    }
    return $mac;
}

function normalizeMac($mac) {
    return strtolower(str_replace(':', '', $mac));
}

function home() {
    global $FritzBox, $MAC_NAMES;

    $db = $FritzBox->initDatabase();
    $macs = $db->query("SELECT DISTINCT mac FROM all_stats ORDER BY mac");
    $selected_mac = isset($_GET['mac']) ? $_GET['mac'] : '';
    $period = isset($_GET['period']) ? $_GET['period'] : 'daily';
    $debug = ($period === '10minutes' && $FritzBox->debug) ? true : false;
    $stats = $selected_mac ? getStats($db, $selected_mac, $period, $debug) : null;

    $html = '';
    $html .= '<!DOCTYPE html>
<html>
<head>
    <title>Statistiche Utilizzo Rete</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; }
        .container { width: 80%; margin: 20px auto; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        canvas { max-width: 100%; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Statistiche Utilizzo Rete</h1>
        <form method="GET">
            <label>Seleziona Dispositivo: </label>
            <select name="mac" onchange="this.form.submit()">
                <option value="">Seleziona...</option>';

    while ($row = $macs->fetchArray()) {
        $mac = $row['mac']; // MAC dal database (minuscolo, senza ":")
        $display_name = getDeviceName($mac, $MAC_NAMES);
        $escaped_mac = htmlspecialchars($mac);
        $escaped_name = htmlspecialchars($display_name);
        $selected = ($mac === $selected_mac) ? ' selected' : '';
        $html .= "<option value=\"$escaped_mac\"$selected>$escaped_name</option>";
    }

    $periodOptions = [
        '10minutes' => 'Ogni 10 minuti',
        'hourly' => 'Orario',
        'daily' => 'Giornaliero',
        'weekly' => 'Settimanale',
        'monthly' => 'Mensile',
        'yearly' => 'Annuale'
    ];

    $html .= '</select>
            <label>Periodo: </label>
            <select name="period" onchange="this.form.submit()">';

    foreach ($periodOptions as $key => $label) {
        $selected = ($period === $key) ? ' selected' : '';
        $html .= "<option value=\"$key\"$selected>$label</option>";
    }

    $html .= '</select>
        </form>';

    if ($selected_mac && $stats) {
        $device_name = getDeviceName($selected_mac, $MAC_NAMES);
        $escaped_device_name = htmlspecialchars($device_name);
        $labels = json_encode($stats['labels']);
        $received = json_encode($stats['received']);
        $sent = json_encode($stats['sent']);

        $html .= "<h2>Statistiche per $escaped_device_name ($period)</h2>
        <canvas id=\"trafficChart\" width=\"800\" height=\"400\"></canvas>
        <script>
            const ctx = document.getElementById('trafficChart').getContext('2d');
            const chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: $labels,
                    datasets: [
                        {
                            label: 'Ricevuto (kB)',
                            data: $received,
                            borderColor: 'rgba(75, 192, 192, 1)',
                            fill: false
                        },
                        {
                            label: 'Inviato (kB)',
                            data: $sent,
                            borderColor: 'rgba(255, 99, 132, 1)',
                            fill: false
                        }
                    ]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Traffico (kB)' },
                            ticks: { stepSize: 1000 }
                        },
                        x: {
                            title: { display: true, text: 'Periodo' },
                            ticks: { maxTicksLimit: 12 }
                        }
                    },
                    plugins: { legend: { display: true } }
                }
            });
        </script>
        <table>
            <tr><th>Periodo</th><th>Ricevuto</th><th>Inviato</th></tr>";

        foreach ($stats['table'] as $stat) {
            $p = htmlspecialchars($stat['period']);
            $r = htmlspecialchars($stat['received']);
            $s = htmlspecialchars($stat['sent']);
            $html .= "<tr><td>$p</td><td>$r</td><td>$s</td></tr>";
        }

        $html .= '</table>';
    }

    $html .= '</div>
</body>
</html>';

    $db->close();
    print $html;
}

?>
