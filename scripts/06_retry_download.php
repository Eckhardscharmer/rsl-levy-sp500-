#!/usr/bin/env php
<?php
/**
 * Retry-Download für rate-limitete Ticker
 * Nutzt query2.finance.yahoo.com + längere Pausen (3s)
 *
 * Aufruf: php scripts/06_retry_download.php
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

$db = getDB();

// Alle Ticker ohne Daten oder mit Fehler laden
$missing = $db->query(
    'SELECT s.ticker
     FROM stocks s
     JOIN sp500_membership m ON m.ticker = s.ticker
     LEFT JOIN download_log dl ON dl.ticker = s.ticker
     WHERE dl.ticker IS NULL OR dl.status = "error"
     GROUP BY s.ticker
     ORDER BY s.ticker'
)->fetchAll(PDO::FETCH_COLUMN);

echo "=== Retry Download (" . count($missing) . " Ticker, 3s Delay) ===\n\n";

$stmtInsert = $db->prepare(
    'INSERT INTO prices (ticker, price_date, open, high, low, close, adj_close, volume)
     VALUES (:ticker, :price_date, :open, :high, :low, :close, :adj_close, :volume)
     ON DUPLICATE KEY UPDATE
       close=VALUES(close), adj_close=VALUES(adj_close), volume=VALUES(volume)'
);
$stmtLog = $db->prepare(
    'INSERT INTO download_log (ticker, last_download, from_date, to_date, rows_inserted, status, error_msg)
     VALUES (?, NOW(), ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       last_download=NOW(), rows_inserted=?, status=?, error_msg=?'
);

$period1 = strtotime('2019-06-01');
$period2 = strtotime(date('Y-m-d')) + 86400;
$toDate = date('Y-m-d');
$ok = 0; $fail = 0;

foreach ($missing as $idx => $ticker) {
    usleep(3000000); // 3 Sekunden zwischen Requests

    // Yahoo Finance nutzt Bindestrich statt Punkt (BRK.B → BRK-B)
    $yahooTicker = str_replace('.', '-', $ticker);

    $url = sprintf(
        'https://query2.finance.yahoo.com/v8/finance/chart/%s?interval=1d&period1=%d&period2=%d&includeAdjustedClose=true',
        urlencode($yahooTicker), $period1, $period2
    );

    $cmd = 'curl -s --http2 --max-time 20 -L '
         . '-H ' . escapeshellarg('User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36') . ' '
         . '-H ' . escapeshellarg('Accept: application/json') . ' '
         . '-H ' . escapeshellarg('Accept-Language: en-US,en;q=0.9') . ' '
         . '-H ' . escapeshellarg('Referer: https://finance.yahoo.com/') . ' '
         . escapeshellarg($url) . ' 2>/dev/null';

    $response = shell_exec($cmd);
    if (!$response) {
        echo "[" . ($idx+1) . "/" . count($missing) . "] $ticker: SKIP (keine Antwort)\n";
        $stmtLog->execute([$ticker, '2019-06-01', $toDate, 0, 'error', 'no response', 0, 'error', 'no response']);
        $fail++;
        continue;
    }

    $json = json_decode($response, true);
    if (!isset($json['chart']['result'][0])) {
        $errMsg = $json['chart']['error']['description'] ?? 'unknown';
        echo "[" . ($idx+1) . "/" . count($missing) . "] $ticker: NOTFOUND ($errMsg)\n";
        $stmtLog->execute([$ticker, '2019-06-01', $toDate, 0, 'error', $errMsg, 0, 'error', $errMsg]);
        $fail++;
        continue;
    }

    $result    = $json['chart']['result'][0];
    $timestamps = $result['timestamp'] ?? [];
    $quotes    = $result['indicators']['quote'][0] ?? [];
    $adjClose  = $result['indicators']['adjclose'][0]['adjclose'] ?? [];

    $db->beginTransaction();
    $n = 0;
    foreach ($timestamps as $i => $ts) {
        $close = $quotes['close'][$i] ?? null;
        if ($close === null) continue;
        $stmtInsert->execute([
            ':ticker'     => $ticker,
            ':price_date' => date('Y-m-d', $ts),
            ':open'       => $quotes['open'][$i] ?? $close,
            ':high'       => $quotes['high'][$i] ?? $close,
            ':low'        => $quotes['low'][$i] ?? $close,
            ':close'      => $close,
            ':adj_close'  => $adjClose[$i] ?? $close,
            ':volume'     => $quotes['volume'][$i] ?? 0,
        ]);
        $n++;
    }
    $db->commit();

    $stmtLog->execute([$ticker, '2019-06-01', $toDate, $n, 'ok', null, $n, 'ok', null]);
    echo "[" . ($idx+1) . "/" . count($missing) . "] $ticker: OK ($n Zeilen)\n";
    $ok++;
}

echo "\n=== Fertig: $ok OK, $fail Fehler ===\n";

$stats = $db->query(
    'SELECT COUNT(DISTINCT ticker) as t, COUNT(*) as r FROM prices'
)->fetch();
echo "DB: {$stats['t']} Ticker, {$stats['r']} Datenpunkte\n";
