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
$DATA_START   = '2009-06-01';   // Warmup für 26W-SMA vor Backtest-Start 2010-01
$DATA_END     = date('Y-m-d');
$DELAY_MS     = 400;            // ms zwischen Requests (Rate-Limit schonen)
$BATCH_SIZE   = 50;             // Fortschritts-Log alle N Ticker
$MAX_RETRIES  = 3;

// ---- Argumente parsen ----
$args        = array_slice($argv, 1);
$updateOnly  = in_array('--update',   $args);
$backfill    = in_array('--backfill', $args);
$tickerArgs  = array_filter($args, fn($a) => !in_array($a, ['--update', '--backfill']));

$modeLabel = $backfill ? 'Backfill (nur fehlende Historie)' : ($updateOnly ? 'Update (nur fehlende Tage)' : 'Vollständig');
echo "=== Yahoo Finance Downloader ===\n";
echo "Zeitraum: $DATA_START bis $DATA_END\n";
echo "Modus: $modeLabel\n\n";

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

$stmtLastDate     = $db->prepare('SELECT MAX(price_date) FROM prices WHERE ticker = ?');
$stmtEarliestDate = $db->prepare('SELECT MIN(price_date) FROM prices WHERE ticker = ?');

$total = count($tickers);
$done  = 0; $errors = 0; $skipped = 0;

foreach ($tickers as $ticker) {
    $done++;

    // Datumsbereich bestimmen je nach Modus
    $fromDate = $DATA_START;
    $toDate   = $DATA_END;

    if ($backfill) {
        // Nur die fehlende Vergangenheit: DATA_START bis (ältestes vorhandenes Datum - 1 Tag)
        $stmtEarliestDate->execute([$ticker]);
        $earliestDate = $stmtEarliestDate->fetchColumn();
        if (!$earliestDate || $earliestDate <= $DATA_START) {
            $skipped++;
            continue; // bereits vollständig vorhanden
        }
        $toDate = date('Y-m-d', strtotime($earliestDate . ' -1 day'));
    } elseif ($updateOnly) {
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
        echo "[$done/$total | $pct%] Verarbeite $ticker ($fromDate → $toDate)...\n";
    }

    // Download mit Retry
    $rows = 0;
    $success = false;
    $lastError = '';

    for ($attempt = 1; $attempt <= $MAX_RETRIES; $attempt++) {
        $data = downloadYahooFinance($ticker, $fromDate, $toDate);
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
    'SELECT COUNT(DISTINCT ticker) as tickers, COUNT(*) as total_rows,
            MIN(price_date) as first_date, MAX(price_date) as last_date
     FROM prices'
)->fetch();
echo "\nDatenbank-Status:\n";
echo "  Ticker:     {$stats['tickers']}\n";
echo "  Datenpunkte: {$stats['total_rows']}\n";
echo "  Von: {$stats['first_date']} bis {$stats['last_date']}\n\n";
echo "Nächster Schritt: php scripts/03_calculate_rsl.php\n";

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

/**
 * Yahoo Finance Crumb + Cookie für authentifizierte Requests holen
 * Wird einmalig gecacht für die gesamte Script-Laufzeit
 */
function getYahooCrumb(): array {
    static $crumb  = null;
    static $cookie = null;

    if ($crumb !== null) return ['crumb' => $crumb, 'cookie' => $cookie];

    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    // Schritt 1: Session-Cookie via curl holen (file_get_contents wird von Yahoo blockiert)
    $cookieFile = sys_get_temp_dir() . '/yahoo_cookie_' . getmypid() . '.txt';
    $cmd1 = 'curl -s --http2 --max-time 15 -L -c ' . escapeshellarg($cookieFile)
          . ' -H ' . escapeshellarg("User-Agent: $ua")
          . ' -H ' . escapeshellarg('Accept: text/html,application/xhtml+xml')
          . ' -H ' . escapeshellarg('Accept-Language: en-US,en;q=0.9')
          . ' https://finance.yahoo.com/ 2>/dev/null';
    shell_exec($cmd1);

    // Schritt 2: Crumb via curl holen
    $cmd2 = 'curl -s --http2 --max-time 10 -L -b ' . escapeshellarg($cookieFile)
          . ' -H ' . escapeshellarg("User-Agent: $ua")
          . ' -H ' . escapeshellarg('Accept: */*')
          . ' https://query1.finance.yahoo.com/v1/test/getcrumb 2>/dev/null';
    $crumbResponse = shell_exec($cmd2);

    // Cookie-String aus Datei lesen
    $cookieStr = '';
    if (file_exists($cookieFile)) {
        foreach (file($cookieFile) as $line) {
            if (str_starts_with(trim($line), '#') || trim($line) === '') continue;
            $parts = explode("\t", trim($line));
            if (count($parts) >= 7) {
                $cookieStr .= ($cookieStr ? '; ' : '') . $parts[5] . '=' . $parts[6];
            }
        }
        @unlink($cookieFile);
    }
    $cookie = $cookieStr;

    // Crumb ist ein einfacher String (keine JSON)
    if ($crumbResponse && strlen($crumbResponse) < 50 && !str_starts_with(trim($crumbResponse), '{')) {
        $crumb = trim($crumbResponse);
    } else {
        $crumb  = '';
        $cookie = '';
    }

    return ['crumb' => $crumb, 'cookie' => $cookie];
}

function downloadYahooFinance(string $ticker, string $from, string $to): ?array {
    $period1 = strtotime($from);
    $period2 = strtotime($to) + 86400; // +1 Tag inkl.

    $auth  = getYahooCrumb();
    $crumb = $auth['crumb'];
    $cookie= $auth['cookie'];

    // Yahoo Finance nutzt Bindestrich statt Punkt (BRK.B → BRK-B)
    $yahooTicker = str_replace('.', '-', $ticker);

    // Yahoo Finance blockiert PHP file_get_contents (TLS-Fingerprint) → curl via Shell (HTTP/2)
    $crumbParam = $crumb ? '&crumb=' . urlencode($crumb) : '';
    $url = sprintf(
        'https://query2.finance.yahoo.com/v8/finance/chart/%s?interval=1d&period1=%d&period2=%d&includeAdjustedClose=true%s',
        urlencode($yahooTicker), $period1, $period2, $crumbParam
    );

    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    $cmd = 'curl -s --http2 --max-time 20 -L '
         . '-H ' . escapeshellarg("User-Agent: $ua") . ' '
         . '-H ' . escapeshellarg('Accept: application/json') . ' '
         . '-H ' . escapeshellarg('Accept-Language: en-US,en;q=0.9') . ' '
         . '-H ' . escapeshellarg('Referer: https://finance.yahoo.com/') . ' '
         . ($cookie ? '-H ' . escapeshellarg("Cookie: $cookie") . ' ' : '')
         . escapeshellarg($url) . ' 2>/dev/null';

    $response = shell_exec($cmd);
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
