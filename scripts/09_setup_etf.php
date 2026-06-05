#!/usr/bin/env php
<?php
/**
 * ETF-Universum Setup
 * Schritt 1: 8 Instrumente in stocks eintragen
 * Schritt 2: Preise via Yahoo Finance downloaden
 * Schritt 3: Monatliche RSL-Rankings berechnen (27W-SMA + SMA200 + Top-3-Filter)
 *
 * Aufruf:
 *   php scripts/09_setup_etf.php              # alles (Instrumente + Download + Ranking)
 *   php scripts/09_setup_etf.php --calc-only  # nur Ranking neu berechnen (keine Downloads)
 *   php scripts/09_setup_etf.php --update     # nur neue Preise + Ranking aktualisieren
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

define('ETF_DATA_START',     '1998-01-01');   // Warmup für SMA200
define('ETF_BACKTEST_START', '2000-01-01');   // Erstes gültiges Ranking-Datum
define('SMA_WEEKS',          27);              // 27 Wochen ≈ 189 Tage
define('SMA_200_DAYS',       200);

// ── 8 Asset-Klassen ────────────────────────────────────────────────────────
$ETF_INSTRUMENTS = [
    ['ticker' => '^GSPC',     'name' => 'USA Large Caps (S&P 500)',     'etf_ticker' => 'SXR8',  'etf_name' => 'iShares Core S&P 500 UCITS ETF',           'sector' => 'Aktien - USA'],
    ['ticker' => '^NDX',      'name' => 'USA Wachstum (Nasdaq-100)',    'etf_ticker' => 'EQQQ',  'etf_name' => 'Invesco EQQQ Nasdaq-100 UCITS ETF',         'sector' => 'Aktien - USA'],
    ['ticker' => '^STOXX',    'name' => 'Europa (STOXX Europe 600)',    'etf_ticker' => 'EXSA',  'etf_name' => 'iShares STOXX Europe 600 UCITS ETF',        'sector' => 'Aktien - Europa'],
    ['ticker' => '^N225',     'name' => 'Japan (Nikkei 225)',           'etf_ticker' => 'DBXJ',  'etf_name' => 'Xtrackers MSCI Japan UCITS ETF',            'sector' => 'Aktien - Asien'],
    ['ticker' => 'EEM',       'name' => 'Emerging Markets (MSCI EM)',   'etf_ticker' => 'XMME',  'etf_name' => 'Xtrackers MSCI Emerging Markets UCITS ETF', 'sector' => 'Aktien - EM'],
    ['ticker' => 'GC=F',      'name' => 'Gold',                        'etf_ticker' => 'EWG2',  'etf_name' => 'Xetra-Gold',                                'sector' => 'Rohstoffe'],
    ['ticker' => 'AGG',       'name' => 'Staatsanleihen (Global)',      'etf_ticker' => 'IGLO',  'etf_name' => 'iShares Core Global Government Bond ETF',   'sector' => 'Anleihen'],
    ['ticker' => 'SHY',       'name' => 'Cash / Geldmarkt',            'etf_ticker' => 'CASH',  'etf_name' => 'Geldmarkt-ETF (Cash-Proxy)',                 'sector' => 'Cash'],
];

$args      = array_slice($argv, 1);
$calcOnly  = in_array('--calc-only', $args);
$updateOnly= in_array('--update',    $args);

echo "=== ETF-Universum Setup ===\n";
echo "Modus: " . ($calcOnly ? 'Nur Ranking' : ($updateOnly ? 'Update (neue Preise + Ranking)' : 'Vollständig')) . "\n\n";

$db = getDB();

// ════════════════════════════════════════════════════════════════
// SCHRITT 1: Instrumente in stocks eintragen
// ════════════════════════════════════════════════════════════════
if (!$calcOnly) {
    echo "Schritt 1: Instrumente eintragen...\n";
    $stmtUpsert = $db->prepare(
        'INSERT INTO stocks (ticker, name, sector, industry, universe)
         VALUES (?, ?, ?, ?, "etf")
         ON DUPLICATE KEY UPDATE name=VALUES(name), sector=VALUES(sector), industry=VALUES(industry), universe="etf"'
    );
    foreach ($ETF_INSTRUMENTS as $inst) {
        $stmtUpsert->execute([
            $inst['ticker'],
            $inst['name'],
            $inst['sector'],
            $inst['etf_ticker'] . ' — ' . $inst['etf_name'],
        ]);
        echo "  ✓ {$inst['ticker']} ({$inst['name']})\n";
    }
    echo "\n";

    // ════════════════════════════════════════════════════════════════
    // SCHRITT 2: Preise downloaden
    // ════════════════════════════════════════════════════════════════
    echo "Schritt 2: Preise downloaden...\n";

    $stmtInsert = $db->prepare(
        'INSERT INTO prices (ticker, price_date, open, high, low, close, adj_close, volume)
         VALUES (:ticker, :price_date, :open, :high, :low, :close, :adj_close, :volume)
         ON DUPLICATE KEY UPDATE
           open=VALUES(open), high=VALUES(high), low=VALUES(low),
           close=VALUES(close), adj_close=VALUES(adj_close), volume=VALUES(volume)'
    );
    $stmtLastDate = $db->prepare('SELECT MAX(price_date) FROM prices WHERE ticker = ?');

    foreach ($ETF_INSTRUMENTS as $inst) {
        $ticker  = $inst['ticker'];
        $fromDate = ETF_DATA_START;

        if ($updateOnly) {
            $stmtLastDate->execute([$ticker]);
            $lastDate = $stmtLastDate->fetchColumn();
            if ($lastDate) {
                $fromDate = date('Y-m-d', strtotime($lastDate . ' +1 day'));
                if ($fromDate >= date('Y-m-d')) {
                    echo "  ↷ $ticker bereits aktuell ($lastDate)\n";
                    continue;
                }
            }
        }

        echo "  ↓ $ticker ($fromDate → heute)...";
        $data = downloadYahooFinance($ticker, $fromDate, date('Y-m-d'));
        if ($data === null) {
            echo " FEHLER\n";
            continue;
        }
        $rows = insertPrices($db, $stmtInsert, $ticker, $data);
        echo " $rows Zeilen\n";
        usleep(500000); // 0.5s Rate-Limit
    }
    echo "\n";
}

// ════════════════════════════════════════════════════════════════
// SCHRITT 3: Monatliche RSL-Rankings berechnen
// ════════════════════════════════════════════════════════════════
echo "Schritt 3: Monatliche RSL-Rankings berechnen...\n";

$tickers = array_column($ETF_INSTRUMENTS, 'ticker');

// Alle Preise laden (adj_close) für alle 8 Instrumente
echo "  Lade Preise aus DB...\n";
$placeholders = implode(',', array_fill(0, count($tickers), '?'));
$priceStmt = $db->prepare(
    "SELECT ticker, price_date, adj_close FROM prices
     WHERE ticker IN ($placeholders) AND price_date >= ?
     ORDER BY ticker, price_date ASC"
);
$priceStmt->execute(array_merge($tickers, [ETF_DATA_START]));

// Nach Ticker gruppieren: ticker → [date → price]
$pricesByTicker = [];
foreach ($priceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pricesByTicker[$row['ticker']][$row['price_date']] = (float)$row['adj_close'];
}

// Pro Ticker: sortierte Datum-Array für Index-Lookup
$datesByTicker = [];
foreach ($pricesByTicker as $ticker => $prices) {
    $datesByTicker[$ticker] = array_keys($prices);
}

// Alle Handelstage (Vereinigung aller Ticker-Daten) ab Backtest-Start bestimmen
$allTradingDays = [];
foreach ($pricesByTicker as $prices) {
    foreach (array_keys($prices) as $d) {
        if ($d >= ETF_BACKTEST_START) $allTradingDays[$d] = true;
    }
}
ksort($allTradingDays);
$allTradingDays = array_keys($allTradingDays);

// Letzten Handelstag pro Monat bestimmen
$monthEndDates = [];
$lastByMonth   = [];
foreach ($allTradingDays as $d) {
    $ym = substr($d, 0, 7); // YYYY-MM
    $lastByMonth[$ym] = $d;
}
$monthEndDates = array_values($lastByMonth);
echo "  " . count($monthEndDates) . " Monatsultimo-Daten gefunden\n";

// Helfer: letzten verfügbaren Preis für Ticker an/vor einem Datum holen
function getPrice(array $pricesByTicker, array $datesByTicker, string $ticker, string $targetDate): ?float {
    if (!isset($pricesByTicker[$ticker])) return null;
    $dates = $datesByTicker[$ticker];
    $prices = $pricesByTicker[$ticker];
    // Binärsuche: letztes Datum <= targetDate
    $lo = 0; $hi = count($dates) - 1; $found = -1;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($dates[$mid] <= $targetDate) { $found = $mid; $lo = $mid + 1; }
        else $hi = $mid - 1;
    }
    return $found >= 0 ? $prices[$dates[$found]] : null;
}

// RSL-Berechnung: SMA über N zurückliegende Kalendertage
function calcSMA(array $pricesByTicker, array $datesByTicker, string $ticker, string $endDate, int $days): ?float {
    if (!isset($pricesByTicker[$ticker])) return null;
    $startDate = date('Y-m-d', strtotime($endDate . " -$days days"));
    $dates  = $datesByTicker[$ticker];
    $prices = $pricesByTicker[$ticker];
    $vals = [];
    foreach ($dates as $d) {
        if ($d < $startDate) continue;
        if ($d > $endDate)   break;
        $vals[] = $prices[$d];
    }
    return count($vals) >= 10 ? array_sum($vals) / count($vals) : null;
}

// Ranking-Insert vorbereiten
$db->exec("DELETE FROM rsl_rankings WHERE universe='etf'");

$stmtRank = $db->prepare(
    'INSERT INTO rsl_rankings
       (ranking_date, ticker, sector, current_price, sma_26w, sma_200, rsl, rank_overall, rank_in_sector, is_sp500_member, is_selected, universe)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, ?, "etf")'
);

$smaWeeksDays = SMA_WEEKS * 7;   // 27 * 7 = 189 Tage
$inserted     = 0;
$tickerMap    = array_column($ETF_INSTRUMENTS, null, 'ticker');

$db->beginTransaction();

foreach ($monthEndDates as $date) {
    $entries = [];

    foreach ($ETF_INSTRUMENTS as $inst) {
        $ticker = $inst['ticker'];
        $price  = getPrice($pricesByTicker, $datesByTicker, $ticker, $date);
        if ($price === null || $price <= 0) continue;

        $sma27w  = calcSMA($pricesByTicker, $datesByTicker, $ticker, $date, $smaWeeksDays);
        $sma200  = calcSMA($pricesByTicker, $datesByTicker, $ticker, $date, SMA_200_DAYS);
        if ($sma27w === null || $sma27w <= 0) continue;

        $rsl = $price / $sma27w;

        $entries[] = [
            'ticker'  => $ticker,
            'sector'  => $inst['sector'],
            'price'   => round($price, 4),
            'sma27w'  => round($sma27w, 4),
            'sma200'  => $sma200 !== null ? round($sma200, 4) : null,
            'rsl'     => round($rsl, 6),
            'above_sma200' => ($sma200 !== null && $price > $sma200),
        ];
    }

    // Absteigend nach RSL sortieren
    usort($entries, fn($a, $b) => $b['rsl'] <=> $a['rsl']);

    // Top 3: nur Instrumente mit Kurs > SMA200
    $selected = 0;
    $selectedTickers = [];
    foreach ($entries as &$e) {
        if ($selected < 3 && $e['above_sma200']) {
            $e['is_selected'] = 1;
            $selectedTickers[] = $e['ticker'];
            $selected++;
        } else {
            $e['is_selected'] = 0;
        }
    }
    unset($e);

    foreach ($entries as $rank => $e) {
        $stmtRank->execute([
            $date,
            $e['ticker'],
            $e['sector'],
            $e['price'],
            $e['sma27w'],
            $e['sma200'],
            $e['rsl'],
            $rank + 1,
            $e['is_selected'],
        ]);
        $inserted++;
    }
}

$db->commit();
echo "  $inserted Ranking-Einträge geschrieben\n";

echo "\n=== ETF-Setup abgeschlossen ===\n";
echo "Nächster Schritt: Browser öffnen → ?universe=etf\n";


// ════════════════════════════════════════════════════════════════
// HILFSFUNKTIONEN (Yahoo Finance Download — identisch zu 02_download_prices.php)
// ════════════════════════════════════════════════════════════════

function getYahooCrumb(): array {
    static $crumb  = null;
    static $cookie = null;
    if ($crumb !== null) return ['crumb' => $crumb, 'cookie' => $cookie];

    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    $cookieFile = sys_get_temp_dir() . '/yahoo_cookie_etf_' . getmypid() . '.txt';

    $cmd1 = 'curl -s --http2 --max-time 15 -L -c ' . escapeshellarg($cookieFile)
          . ' -H ' . escapeshellarg("User-Agent: $ua")
          . ' -H ' . escapeshellarg('Accept: text/html,application/xhtml+xml')
          . ' https://finance.yahoo.com/ 2>/dev/null';
    shell_exec($cmd1);

    $cmd2 = 'curl -s --http2 --max-time 10 -L -b ' . escapeshellarg($cookieFile)
          . ' -H ' . escapeshellarg("User-Agent: $ua")
          . ' -H ' . escapeshellarg('Accept: */*')
          . ' https://query1.finance.yahoo.com/v1/test/getcrumb 2>/dev/null';
    $crumbResponse = shell_exec($cmd2);

    $cookieStr = '';
    if (file_exists($cookieFile)) {
        foreach (file($cookieFile) as $line) {
            if (str_starts_with(trim($line), '#') || trim($line) === '') continue;
            $parts = explode("\t", trim($line));
            if (count($parts) >= 7) $cookieStr .= ($cookieStr ? '; ' : '') . $parts[5] . '=' . $parts[6];
        }
        @unlink($cookieFile);
    }
    $cookie = $cookieStr;
    $crumb  = ($crumbResponse && strlen($crumbResponse) < 50 && !str_starts_with(trim($crumbResponse), '{'))
              ? trim($crumbResponse) : '';
    return ['crumb' => $crumb, 'cookie' => $cookie];
}

function downloadYahooFinance(string $ticker, string $from, string $to): ?array {
    $period1 = strtotime($from);
    $period2 = strtotime($to) + 86400;
    $auth    = getYahooCrumb();
    $crumb   = $auth['crumb'];
    $cookie  = $auth['cookie'];

    $yahooTicker = str_replace('.', '-', $ticker);
    $crumbParam  = $crumb ? '&crumb=' . urlencode($crumb) : '';
    $url = sprintf(
        'https://query2.finance.yahoo.com/v8/finance/chart/%s?interval=1d&period1=%d&period2=%d&includeAdjustedClose=true%s',
        urlencode($yahooTicker), $period1, $period2, $crumbParam
    );
    $ua  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    $cmd = 'curl -s --http2 --max-time 30 -L '
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
        $date  = date('Y-m-d', $ts);
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
    }
    return $count;
}
