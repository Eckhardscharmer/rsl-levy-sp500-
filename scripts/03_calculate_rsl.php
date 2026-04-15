#!/usr/bin/env php
<?php
/**
 * Sprint 3 — RSL Engine
 * Berechnet wöchentliche RSL-Rankings mit Sektor-Diversifikation (Top 5, je ein Sektor)
 *
 * Aufruf:
 *   php scripts/03_calculate_rsl.php                     # S&P 500, alle Sonntage
 *   php scripts/03_calculate_rsl.php --universe=dax      # DAX, alle Sonntage
 *   php scripts/03_calculate_rsl.php 2024-01-07          # ab bestimmtem Datum
 *   php scripts/03_calculate_rsl.php --latest            # nur letzten Sonntag
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

define('SMA_DAYS',   130);   // 26 Wochen × 5 Handelstage = 130
define('TOP_N',      5);     // Anzahl Positionen
define('MIN_DATA',   100);   // Mindest-Handelstage für validen SMA (~20 Wochen)

$args        = array_slice($argv, 1);
$latestOnly  = in_array('--latest', $args);
$universe    = 'sp500';
foreach ($args as $a) {
    if (preg_match('/^--universe=(.+)$/', $a, $m)) $universe = $m[1];
}
if (!in_array($universe, ['sp500', 'dax'])) $universe = 'sp500';
$dateArg     = array_values(array_filter($args, fn($a) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $a)))[0] ?? null;

$db = getDB();

// Sonntage berechnen
if ($latestOnly) {
    $sundays = [lastSunday()];
} else {
    $start = $dateArg ?? '2010-01-04'; // erster Sonntag 2010
    $sundays = getSundays($start, date('Y-m-d'));
}

echo "=== RSL Engine ===\n";
echo "Universe: " . strtoupper($universe) . "\n";
echo "Berechne " . count($sundays) . " Sonntage (SMA = " . SMA_DAYS . " Tage, Top " . TOP_N . " mit Sektor-Diversifikation)\n\n";

$stmtInsert = $db->prepare(
    'INSERT INTO rsl_rankings
       (ranking_date, ticker, sector, current_price, sma_26w, rsl,
        rank_overall, rank_in_sector, is_sp500_member, is_selected, universe)
     VALUES
       (:date, :ticker, :sector, :price, :sma, :rsl,
        :rank_overall, :rank_sector, 1, :selected, :universe)
     ON DUPLICATE KEY UPDATE
       sector=VALUES(sector), current_price=VALUES(current_price),
       sma_26w=VALUES(sma_26w), rsl=VALUES(rsl),
       rank_overall=VALUES(rank_overall), rank_in_sector=VALUES(rank_in_sector),
       is_selected=VALUES(is_selected), universe=VALUES(universe)'
);

// Aktive M&A-Flags laden (nur für aktuellen Lauf relevant)
$maFlagged = [];
$maStmt = $db->query(
    'SELECT ticker FROM m_and_a_flags WHERE is_active = 1'
);
foreach ($maStmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $maFlagged[$t] = true;
}
if (!empty($maFlagged)) {
    echo "M&A-Filter aktiv für: " . implode(', ', array_keys($maFlagged)) . "\n\n";
}

// S&P 500-Mitglieder pro Datum (gecacht)
$membershipCache = [];

foreach ($sundays as $idx => $sunday) {
    // Index-Mitglieder an diesem Datum
    $members = $universe === 'dax'
        ? getDAXMembers($db, $sunday, $membershipCache)
        : getSP500Members($db, $sunday, $membershipCache);
    if (empty($members)) {
        echo "[$sunday] Keine Mitglieder gefunden, überspringe.\n";
        continue;
    }

    // Kursdaten: letzter Handelstag <= Sonntag + SMA-Periode davor
    $latestDay = getLastTradingDay($db, $sunday, $universe);
    if (!$latestDay) {
        echo "[$sunday] Kein Handelstag gefunden.\n";
        continue;
    }

    // RSL für alle Mitglieder berechnen
    $rankings = [];
    foreach ($members as $ticker => $sector) {
        $rslData = calculateRSL($db, $ticker, $latestDay);
        if ($rslData === null) continue;

        $rankings[] = [
            'ticker'  => $ticker,
            'sector'  => $sector,
            'price'   => $rslData['price'],
            'sma'     => $rslData['sma'],
            'rsl'     => $rslData['rsl'],
        ];
    }

    if (empty($rankings)) {
        echo "[$sunday] Keine RSL-Daten berechnet.\n";
        continue;
    }

    // Nach RSL absteigend sortieren
    usort($rankings, fn($a, $b) => $b['rsl'] <=> $a['rsl']);

    // Rang zuweisen
    $sectorRanks = [];
    $overallRank = 1;
    foreach ($rankings as &$r) {
        $r['rank_overall'] = $overallRank++;
        $sectorRanks[$r['sector']] = ($sectorRanks[$r['sector']] ?? 0) + 1;
        $r['rank_sector'] = $sectorRanks[$r['sector']];
    }
    unset($r);

    // Top 5 mit Sektor-Diversifikation auswählen (M&A-geflaggte Aktien ausschließen)
    $selected    = selectTopN($rankings, TOP_N, $maFlagged);
    $selectedSet = array_flip(array_column($selected, 'ticker'));

    // In DB speichern
    $db->beginTransaction();
    foreach ($rankings as $r) {
        $stmtInsert->execute([
            ':date'         => $sunday,
            ':ticker'       => $r['ticker'],
            ':sector'       => $r['sector'],
            ':price'        => $r['price'],
            ':sma'          => $r['sma'],
            ':rsl'          => $r['rsl'],
            ':rank_overall' => $r['rank_overall'],
            ':rank_sector'  => $r['rank_sector'],
            ':selected'     => isset($selectedSet[$r['ticker']]) ? 1 : 0,
            ':universe'     => $universe,
        ]);
    }
    $db->commit();

    if (($idx + 1) % 10 === 0 || $latestOnly) {
        echo "[$sunday] " . count($rankings) . " Aktien | Top 5: "
             . implode(', ', array_column($selected, 'ticker'))
             . " | RSL: " . implode(', ', array_map(fn($s) => round($s['rsl'], 3), $selected))
             . "\n";
    }
}

echo "\n=== RSL-Berechnung abgeschlossen ===\n";

// Aktuelles Top-5 anzeigen
$latestSunday = lastSunday();
$top5 = $db->prepare(
    'SELECT r.ticker, s.name, r.sector, r.current_price, r.sma_26w, r.rsl, r.rank_overall
     FROM rsl_rankings r
     LEFT JOIN stocks s ON s.ticker = r.ticker
     WHERE r.ranking_date = ? AND r.is_selected = 1 AND r.universe = ?
     ORDER BY r.rsl DESC'
);
$top5->execute([$latestSunday, $universe]);
$top5rows = $top5->fetchAll();

echo "\nAktuelles Top-5 ($latestSunday):\n";
echo str_pad('Ticker', 8) . str_pad('Name', 35) . str_pad('Sektor', 30) . str_pad('Kurs', 10) . str_pad('SMA26W', 10) . "RSL\n";
echo str_repeat('-', 100) . "\n";
foreach ($top5rows as $r) {
    echo str_pad($r['ticker'], 8)
       . str_pad(substr($r['name'] ?? '', 0, 34), 35)
       . str_pad(substr($r['sector'] ?? '', 0, 29), 30)
       . str_pad(number_format($r['current_price'], 2), 10)
       . str_pad(number_format($r['sma_26w'], 2), 10)
       . number_format($r['rsl'], 4) . "\n";
}

echo "\nNächster Schritt: php scripts/04_run_backtest.php\n";

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function lastSunday(): string {
    $ts = strtotime('last sunday');
    // Falls heute Sonntag ist
    if (date('N') == 7) $ts = time();
    return date('Y-m-d', $ts);
}

function getSundays(string $from, string $to): array {
    $sundays = [];
    $ts = strtotime($from);
    // Auf nächsten Sonntag justieren
    $dayOfWeek = (int)date('N', $ts);
    if ($dayOfWeek !== 7) {
        $ts = strtotime('next sunday', $ts);
    }
    $end = strtotime($to);
    while ($ts <= $end) {
        $sundays[] = date('Y-m-d', $ts);
        $ts = strtotime('+7 days', $ts);
    }
    return $sundays;
}

function getSP500Members(PDO $db, string $date, array &$cache): array {
    // Gecacht nach exaktem Datum (Monats-Cache würde mid-month Zugänge verpassen)
    if (isset($cache[$date])) return $cache[$date];

    $stmt = $db->prepare(
        'SELECT s.ticker, COALESCE(s.sector, "Unknown") as sector
         FROM sp500_membership m
         JOIN stocks s ON s.ticker = m.ticker
         WHERE m.date_added <= :date
           AND (m.date_removed IS NULL OR m.date_removed > :date2)
         GROUP BY s.ticker'
    );
    $stmt->execute([':date' => $date, ':date2' => $date]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $result[$r['ticker']] = $r['sector'];
    }
    $cache[$date] = $result;
    return $result;
}

function getDAXMembers(PDO $db, string $date, array &$cache): array
{
    if (isset($cache['dax_' . $date])) return $cache['dax_' . $date];

    $stmt = $db->prepare(
        'SELECT s.ticker, COALESCE(s.sector, "Unknown") as sector
         FROM dax_membership m
         JOIN stocks s ON s.ticker = m.ticker
         WHERE m.date_added <= :date
           AND (m.date_removed IS NULL OR m.date_removed > :date2)
         GROUP BY s.ticker'
    );
    $stmt->execute([':date' => $date, ':date2' => $date]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $result[$r['ticker']] = $r['sector'];
    }
    $cache['dax_' . $date] = $result;
    return $result;
}

function getLastTradingDay(PDO $db, string $refDate, string $universe = 'sp500'): ?string {
    // Referenz-Ticker je nach Universe
    $refTicker = $universe === 'dax' ? 'SAP.DE' : 'SPY';
    $stmt = $db->prepare(
        'SELECT MAX(price_date) FROM prices WHERE price_date <= ? AND ticker = ?'
    );
    $stmt->execute([$refDate, $refTicker]);
    $result = $stmt->fetchColumn();
    if ($result) return $result;

    // Fallback: irgendeinen Ticker nehmen
    $stmt = $db->prepare(
        'SELECT MAX(price_date) FROM prices WHERE price_date <= ?'
    );
    $stmt->execute([$refDate]);
    return $stmt->fetchColumn() ?: null;
}

function calculateRSL(PDO $db, string $ticker, string $refDate): ?array {
    // Letzte 130+20 Handelstage holen (Puffer für fehlende Handelstage)
    $stmt = $db->prepare(
        'SELECT adj_close, price_date
         FROM prices
         WHERE ticker = ? AND price_date <= ?
         ORDER BY price_date DESC
         LIMIT ' . (SMA_DAYS + 20)
    );
    $stmt->execute([$ticker, $refDate]);
    $rows = $stmt->fetchAll();

    if (count($rows) < MIN_DATA) return null;

    $currentPrice = (float)$rows[0]['adj_close'];

    // SMA über die letzten 130 Handelstage (= 26 Wochen)
    $smaRows = array_slice($rows, 0, SMA_DAYS);
    if (count($smaRows) < MIN_DATA) return null;

    $sma = array_sum(array_column($smaRows, 'adj_close')) / count($smaRows);

    if ($sma <= 0) return null;

    return [
        'price' => $currentPrice,
        'sma'   => $sma,
        'rsl'   => $currentPrice / $sma,
    ];
}

/**
 * Wählt Top-N Aktien aus, max. eine pro Sektor (Greedy-Algorithmus)
 * M&A-geflaggte Aktien werden übersprungen.
 */
function selectTopN(array $rankedStocks, int $n, array $maFlagged = []): array {
    $selected        = [];
    $selectedSectors = [];

    foreach ($rankedStocks as $stock) {
        if (count($selected) >= $n) break;
        if (isset($maFlagged[$stock['ticker']])) continue;   // M&A-Filter
        $sector = $stock['sector'] ?? 'Unknown';
        if (in_array($sector, $selectedSectors)) continue;

        $selected[]        = $stock;
        $selectedSectors[] = $sector;
    }
    return $selected;
}
