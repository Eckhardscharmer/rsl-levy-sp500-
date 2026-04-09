#!/usr/bin/env php
<?php
/**
 * Sprint 2b — Historische S&P 500-Zusammensetzung (Option A)
 *
 * Quellen:
 *   1. fja05680/sp500: sp500_changes_since_2019.csv  → Additions/Removals ab 2019
 *   2. fja05680/sp500: Historical Components CSV      → Snapshot 2019-01-01 als Baseline
 *
 * Ergebnis: sp500_membership korrekt befüllt → kein Look-ahead-Bias mehr
 *
 * Aufruf: /Applications/XAMPP/xamppfiles/bin/php scripts/05_fix_membership.php
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

echo "=== Historische S&P 500-Zusammensetzung laden ===\n\n";

$db = getDB();

// ---- 1. Historical Components → Snapshot per 2019-01-02 ----
echo "Lade historisches Components-File (Snapshot 2019-01-02)...\n";

$histUrl = 'https://raw.githubusercontent.com/fja05680/sp500/master/'
         . 'S%26P%20500%20Historical%20Components%20%26%20Changes(01-17-2026).csv';

$ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'RSL-System/1.0']]);
$histContent = @file_get_contents($histUrl, false, $ctx);

if (!$histContent) {
    die("[FEHLER] Konnte Historical Components nicht laden.\n");
}

// CSV zeilenweise einlesen
$lines       = explode("\n", $histContent);
$header      = array_shift($lines); // "date,tickers"
$snapshots   = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (!$line) continue;
    // Format: YYYY-MM-DD,"TICKER1,TICKER2,..."
    if (!preg_match('/^(\d{4}-\d{2}-\d{2}),"?(.+?)"?$/', $line, $m)) continue;
    $snapshots[$m[1]] = array_map('trim', explode(',', $m[2]));
}

// Nearest snapshot zu 2019-01-01 finden
ksort($snapshots);
$baselineDate    = null;
$baselineTickers = [];

foreach ($snapshots as $date => $tickers) {
    if ($date >= '2019-01-01') {
        $baselineDate    = $date;
        $baselineTickers = $tickers;
        break;
    }
}

if (!$baselineDate) {
    // Fallback: letzten Snapshot nehmen
    $baselineDate    = array_key_last($snapshots);
    $baselineTickers = $snapshots[$baselineDate];
}

echo "  Baseline: $baselineDate → " . count($baselineTickers) . " Ticker\n\n";

// ---- 2. Changes seit 2019 laden ----
echo "Lade sp500_changes_since_2019.csv...\n";

$changesUrl = 'https://raw.githubusercontent.com/fja05680/sp500/master/sp500_changes_since_2019.csv';
$changesContent = @file_get_contents($changesUrl, false, $ctx);

if (!$changesContent) {
    die("[FEHLER] Konnte Changes-CSV nicht laden.\n");
}

$changes = [];
$changeLines = explode("\n", $changesContent);
array_shift($changeLines); // Header

foreach ($changeLines as $line) {
    $line = trim($line);
    if (!$line) continue;
    // Format: date,"ADD1,ADD2","REM1,REM2"
    $parts = str_getcsv($line);
    if (count($parts) < 3) continue;

    $date    = trim($parts[0]);
    $added   = array_filter(array_map('trim', explode(',', $parts[1])));
    $removed = array_filter(array_map('trim', explode(',', $parts[2])));

    $changes[] = ['date' => $date, 'added' => $added, 'removed' => $removed];
}

// Nach Datum sortieren
usort($changes, fn($a, $b) => strcmp($a['date'], $b['date']));

echo "  Änderungen: " . count($changes) . " Events\n\n";

// ---- 3. Mitgliedschaft rekonstruieren ----
echo "Rekonstruiere Mitgliedschaft...\n";

// Timeline: ticker → [{date_added, date_removed}, ...]
$membership = [];

// Baseline: alle Ticker ab baselineDate Mitglied
foreach ($baselineTickers as $ticker) {
    $ticker = trim($ticker);
    if (!$ticker) continue;
    $membership[$ticker][] = ['added' => $baselineDate, 'removed' => null];
}

// Changes anwenden
foreach ($changes as $ev) {
    $date = $ev['date'];
    if ($date < $baselineDate) continue;

    // Entfernungen: aktive Mitgliedschaft beenden
    foreach ($ev['removed'] as $ticker) {
        $ticker = trim($ticker);
        if (!$ticker) continue;
        if (isset($membership[$ticker])) {
            // Letzte offene Mitgliedschaft schließen
            $last = count($membership[$ticker]) - 1;
            if ($membership[$ticker][$last]['removed'] === null) {
                $membership[$ticker][$last]['removed'] = $date;
            }
        } else {
            // War vielleicht vor unserer Baseline dabei - anlegen mit "vorher" als Start
            $membership[$ticker][] = ['added' => $baselineDate, 'removed' => $date];
        }
    }

    // Hinzufügungen: neue Mitgliedschaft starten
    foreach ($ev['added'] as $ticker) {
        $ticker = trim($ticker);
        if (!$ticker) continue;
        if (!isset($membership[$ticker])) {
            $membership[$ticker] = [];
        }
        $membership[$ticker][] = ['added' => $date, 'removed' => null];
    }
}

echo "  Einzigartige Ticker (historisch): " . count($membership) . "\n\n";

// ---- 4. Stocks-Tabelle: fehlende Ticker ergänzen ----
echo "Ergänze fehlende Ticker in stocks-Tabelle...\n";

$existingTickers = $db->query('SELECT ticker FROM stocks')->fetchAll(PDO::FETCH_COLUMN);
$existingSet     = array_flip($existingTickers);
$newTickers      = array_diff(array_keys($membership), $existingTickers);

$stmtAddStock = $db->prepare(
    'INSERT IGNORE INTO stocks (ticker, name, sector, industry)
     VALUES (?, ?, "Unknown", "Unknown")'
);

$db->beginTransaction();
foreach ($newTickers as $ticker) {
    $stmtAddStock->execute([$ticker, $ticker]);
}
$db->commit();

echo "  Neu hinzugefügt: " . count($newTickers) . " Ticker\n\n";

// ---- 5. sp500_membership Tabelle neu befüllen ----
echo "Befülle sp500_membership (lösche alte Einträge)...\n";

$db->exec('TRUNCATE TABLE sp500_membership');

$stmtInsert = $db->prepare(
    'INSERT INTO sp500_membership (ticker, date_added, date_removed, reason_added)
     VALUES (?, ?, ?, ?)'
);

$totalRows  = 0;
$db->beginTransaction();

foreach ($membership as $ticker => $periods) {
    foreach ($periods as $p) {
        $stmtInsert->execute([
            $ticker,
            $p['added'],
            $p['removed'],
            'fja05680/sp500 historical data',
        ]);
        $totalRows++;
    }
}
$db->commit();

echo "  Eingetragen: $totalRows Mitgliedschafts-Perioden\n\n";

// ---- 6. Statistik ----
$stats = $db->query(
    'SELECT
       COUNT(DISTINCT ticker) as unique_tickers,
       SUM(CASE WHEN date_removed IS NULL THEN 1 ELSE 0 END) as currently_active,
       SUM(CASE WHEN date_removed IS NOT NULL THEN 1 ELSE 0 END) as historically_removed
     FROM sp500_membership'
)->fetch();

echo "Statistik sp500_membership:\n";
echo "  Einzigartige Ticker:    {$stats['unique_tickers']}\n";
echo "  Aktuell aktiv:          {$stats['currently_active']}\n";
echo "  Historisch entfernt:    {$stats['historically_removed']}\n\n";

// ---- 7. Wichtige Ticker prüfen (Sanity Check) ----
echo "Sanity Check (bekannte Eintrittsdaten):\n";
$checks = [
    'TSLA' => '2020-12-21',  // Tesla: Dez 2020
    'MRNA' => '2020-07-21',  // Moderna: Jul 2020
    'NVDA' => '2001-11-30',  // NVDA: schon lange dabei
    'META' => '2013-12-23',  // Meta/FB: Dez 2013
];

foreach ($checks as $ticker => $expectedApprox) {
    $row = $db->prepare(
        'SELECT date_added, date_removed FROM sp500_membership
         WHERE ticker = ? ORDER BY date_added LIMIT 1'
    );
    $row->execute([$ticker]);
    $r = $row->fetch();
    if ($r) {
        $ok = $r['date_added'] >= $expectedApprox ? '✓' : '≈';
        echo "  $ok $ticker: eingetreten " . $r['date_added']
           . " (erwartet ≈$expectedApprox)\n";
    } else {
        echo "  ? $ticker: nicht gefunden\n";
    }
}

// ---- 8. Fehlende Kurshistorie? ----
echo "\nPrüfe fehlende Kurshistorie...\n";

$missingPrices = $db->query(
    'SELECT s.ticker
     FROM stocks s
     JOIN sp500_membership m ON m.ticker = s.ticker
     LEFT JOIN download_log dl ON dl.ticker = s.ticker
     WHERE dl.ticker IS NULL OR dl.status = "error"
     GROUP BY s.ticker
     ORDER BY s.ticker'
)->fetchAll(PDO::FETCH_COLUMN);

if (!empty($missingPrices)) {
    $count = count($missingPrices);
    echo "  $count Ticker ohne Kursdaten. Starte Download...\n";
    echo "  Befehl: php scripts/02_download_prices.php " . implode(' ', array_slice($missingPrices, 0, 10)) . " ...\n\n";

    // Top-50 kritischste Ticker direkt laden (historisch im Index & kein Kurs)
    $toDownload = array_slice($missingPrices, 0, 50);
    $cmd = '/Applications/XAMPP/xamppfiles/bin/php scripts/02_download_prices.php '
         . implode(' ', array_map('escapeshellarg', $toDownload));
    echo "Lade " . count($toDownload) . " fehlende Ticker...\n";
    passthru($cmd);
} else {
    echo "  Alle Ticker haben Kursdaten.\n";
}

echo "\n=== Mitgliedschaft bereinigt ===\n";
echo "Nächste Schritte:\n";
echo "  php scripts/03_calculate_rsl.php         (RSL neu berechnen)\n";
echo "  php scripts/04_run_backtest.php           (Backtest neu ausführen)\n";
