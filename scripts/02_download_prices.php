#!/usr/bin/env php
<?php
/**
 * Sprint 2 — Yahoo Finance Downloader
 * Lädt Tagespreise für alle S&P 500-Aktien via Yahoo Finance JSON API
 *
 * Aufruf:
 *   php scripts/02_download_prices.php              # alle Aktien
 *   php scripts/02_download_prices.php AAPL MSFT    # nur bestimmte Ticker
 *   php scripts/02_download_prices.php --update     # nur fehlende Tage nachtragen
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

// ---- Konfiguration ----
$DATA_START   = '2019-06-01';   // Warmup für 26W-SMA vor Backtest-Start 2020-01
$DATA_END     = date('Y-m-d');
$DELAY_MS     = 400;            // ms zwischen Requests (Rate-Limit schonen)
$BATCH_SIZE   = 50;             // Fortschritts-Log alle N Ticker
$MAX_RETRIES  = 3;

// ---- Argumente parsen ----
$args       = array_slice($argv, 1);
$updateOnly = in_array('--update', $args);
$tickerArgs = array_filter($args, fn($a) => $a !== '--update');

echo "=== Yahoo Finance Downloader ===\n";
echo "Zeitraum: $DATA_START bis $DATA_END\n";
echo "Modus: " . ($updateOnly ? 'Update (nur fehlende Tage)' : 'Vollständig') . "\n\n";

$db = getDB();

// Ticker-Liste bestimmen
if (!empty($tickerArgs)) {
    $tickers = array_values($tickerArgs);
} else {
    $tickers = $db->query('SELECT ticker FROM stocks ORDER BY ticker')->fetchAll(PDO::FETCH_COLUMN);
}

echo "Ticker zu verarbeiten: " . count($tickers) . "\n\n";

$stmtInsert = $db->prepare(
    'INSERT INTO prices (ticker, price_date, open, high, low, close, adj_close, volume)
     VALUES (:ticker, :price_date, :open, :high, :low, :close, :adj_close, :volume)
     ON DUPLICATE KEY UPDATE
       open=VALUES(open), high=VALUES(high), low=VALUES(low),
       close=VALUES(close), adj_close=VALUES(adj_close), volume=VALUES(volume)'
);

$stmtLog = $db->prepare(
    'INSERT INTO download_log (ticker, last_download, from_date, to_date, rows_inserted, status, error_msg)
     VALUES (:ticker, NOW(), :from_date, :to_date, :rows, :status, :err)
     ON DUPLICATE KEY UPDATE
       last_download=NOW(), from_date=:from_date2, to_date=:to_date2,
       rows_inserted=:rows2, status=:status2, error_msg=:err2'
);

$stmtLastDate = $db->prepare(
    'SELECT MAX(price_date) as last_date FROM prices WHERE ticker = ?'
);

$total = count($tickers);
$done  = 0; $errors = 0; $skipped = 0;

foreach ($tickers as $ticker) {
    $done++;

    // Bei Update-Modus: Startdatum bestimmen
    $fromDate = $DATA_START;
    if ($updateOnly) {
        $stmtLastDate->execute([$ticker]);
        $lastDate = $stmtLastDate->fetchColumn();
        if ($lastDate && $lastDate >= $DATA_END) {
            $skipped++;
            continue;
        }
        if ($lastDate) {
            $fromDate = date('Y-m-d', strtotime($lastDate . ' +1 day'));
        }
    }

    // Fortschritts-Log
    if ($done % $BATCH_SIZE === 0 || $done === 1) {
        $pct = round($done / $total * 100, 1);
        echo "[$done/$total | $pct%] Verarbeite $ticker (ab $fromDate)...\n";
    }

    // Download mit Retry
    $rows = 0;
    $success = false;
    $lastError = '';

    for ($attempt = 1; $attempt <= $MAX_RETRIES; $attempt++) {
        $data = downloadYahooFinance($ticker, $fromDate, $DATA_END);
        if ($data !== null) {
            $rows = insertPrices($db, $stmtInsert, $ticker, $data);
            $success = true;
            break;
        }
        $lastError = "Download fehlgeschlagen (Versuch $attempt/$MAX_RETRIES)";
        if ($attempt < $MAX_RETRIES) {
            usleep($DELAY_MS * 2000); // längere Pause bei Fehler
        }
    }

    // Log schreiben
    $logParams = [
        ':ticker'    => $ticker,
        ':from_date' => $fromDate,
        ':to_date'   => $DATA_END,
        ':rows'      => $rows,
        ':status'    => $success ? 'ok' : 'error',
        ':err'       => $success ? null : $lastError,
        ':from_date2'=> $fromDate,
        ':to_date2'  => $DATA_END,
        ':rows2'     => $rows,
        ':status2'   => $success ? 'ok' : 'error',
        ':err2'      => $success ? null : $lastError,
    ];
    try { $stmtLog->execute($logParams); } catch (Exception $e) {}

    if (!$success) {
        $errors++;
        echo "  [FEHLER] $ticker: $lastError\n";
    }

    // Rate-Limiting
    usleep($DELAY_MS * 1000);
}

echo "\n=== Download abgeschlossen ===\n";
echo "Erfolgreich: " . ($done - $errors - $skipped) . "\n";
echo "Fehler:      $errors\n";
echo "Übersprungen: $skipped\n";

// Preisübersicht
$stats = $db->query(
    'SELECT COUNT(DISTINCT ticker) as tickers, COUNT(*) as rows,
            MIN(price_date) as first_date, MAX(price_date) as last_date
     FROM prices'
)->fetch();
echo "\nDatenbank-Status:\n";
echo "  Ticker:     {$stats['tickers']}\n";
echo "  Datenpunkte: {$stats['rows']}\n";
echo "  Von: {$stats['first_date']} bis {$stats['last_date']}\n\n";
echo "Nächster Schritt: php scripts/03_calculate_rsl.php\n";

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function downloadYahooFinance(string $ticker, string $from, string $to): ?array {
    $period1 = strtotime($from);
    $period2 = strtotime($to) + 86400; // +1 Tag inkl.

    // Yahoo Finance Chart API v8 (kein Auth nötig für historische Daten)
    $url = sprintf(
        'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1d&period1=%d&period2=%d&includeAdjustedClose=true',
        urlencode($ticker), $period1, $period2
    );

    $ctx = stream_context_create([
        'http' => [
            'timeout'     => 20,
            'user_agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'header'      => "Accept: application/json\r\nAccept-Language: en-US,en\r\n",
        ],
        'ssl' => ['verify_peer' => false],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return null;

    $json = json_decode($response, true);
    if (!isset($json['chart']['result'][0])) return null;

    $result    = $json['chart']['result'][0];
    $timestamps = $result['timestamp'] ?? [];
    $quotes    = $result['indicators']['quote'][0] ?? [];
    $adjClose  = $result['indicators']['adjclose'][0]['adjclose'] ?? [];

    if (empty($timestamps)) return null;

    $rows = [];
    foreach ($timestamps as $i => $ts) {
        $date = date('Y-m-d', $ts);
        $close = $quotes['close'][$i] ?? null;
        if ($close === null) continue;

        $rows[] = [
            'date'      => $date,
            'open'      => $quotes['open'][$i] ?? $close,
            'high'      => $quotes['high'][$i] ?? $close,
            'low'       => $quotes['low'][$i] ?? $close,
            'close'     => $close,
            'adj_close' => $adjClose[$i] ?? $close,
            'volume'    => $quotes['volume'][$i] ?? 0,
        ];
    }
    return $rows;
}

function insertPrices(PDO $db, PDOStatement $stmt, string $ticker, array $rows): int {
    $count = 0;
    $db->beginTransaction();
    try {
        foreach ($rows as $r) {
            $stmt->execute([
                ':ticker'     => $ticker,
                ':price_date' => $r['date'],
                ':open'       => $r['open'],
                ':high'       => $r['high'],
                ':low'        => $r['low'],
                ':close'      => $r['close'],
                ':adj_close'  => $r['adj_close'],
                ':volume'     => $r['volume'],
            ]);
            $count++;
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        return 0;
    }
    return $count;
}
