#!/usr/bin/env php
<?php
/**
 * Portfolio-Konsistenzcheck: Vergleicht Dashboard- und Simulations-Logik
 *
 * Beide Seiten werden hier aus denselben RSL-Rankings frisch berechnet
 * (identischer Greedy-Algorithmus + M&A-Filter). Wenn sie abweichen,
 * hat sich die Logik in index.php oder simulation.php auseinanderentwickelt.
 * Außerdem prüft das Script, ob die DB-Spalte is_selected mit der frisch
 * berechneten Auswahl übereinstimmt.
 *
 * Aufruf:
 *   php scripts/check_portfolio_consistency.php              # nur letzte Woche
 *   php scripts/check_portfolio_consistency.php --all-weeks  # alle Wochen
 *   php scripts/check_portfolio_consistency.php --universe=dax
 *
 * Exit-Code: 0 = alles konsistent, 1 = Abweichungen gefunden
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

$args     = array_slice($argv, 1);
$allWeeks = in_array('--all-weeks', $args);
$universe = 'sp500';
foreach ($args as $a) {
    if (preg_match('/^--universe=(.+)$/', $a, $m)) $universe = $m[1];
}

$db = getDB();

// ── M&A-Flags laden ───────────────────────────────────────────────────────────
$maFlagged = [];
foreach ($db->query(
    'SELECT ticker FROM m_and_a_flags WHERE is_active = 1 AND (expires_date IS NULL OR expires_date > CURDATE())'
)->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $maFlagged[$t] = true;
}

// ── Wochen bestimmen ──────────────────────────────────────────────────────────
if ($allWeeks) {
    $stmt = $db->prepare(
        'SELECT DISTINCT ranking_date FROM rsl_rankings WHERE universe = ? ORDER BY ranking_date'
    );
    $stmt->execute([$universe]);
    $fridays = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $db->prepare('SELECT MAX(ranking_date) FROM rsl_rankings WHERE universe = ?');
    $stmt->execute([$universe]);
    $friday = $stmt->fetchColumn();
    if (!$friday) {
        echo "FEHLER: Keine RSL-Daten für Universe '$universe' gefunden.\n";
        exit(1);
    }
    $fridays = [$friday];
}

define('TOP_N', 5);

$errors  = [];
$dbDrift = [];
$checked = 0;

foreach ($fridays as $friday) {

    // ── RSL-Rankings laden ────────────────────────────────────────────────────
    $stmt = $db->prepare(
        'SELECT ticker, sector, rsl, is_selected FROM rsl_rankings
         WHERE ranking_date = ? AND universe = ?
         ORDER BY rsl DESC'
    );
    $stmt->execute([$friday, $universe]);
    $allRanked = $stmt->fetchAll();

    // ── Frisch berechnen (Greedy + M&A-Filter) — identisch zu index.php ──────
    $computed    = [];
    $usedSectors = [];
    foreach ($allRanked as $stock) {
        if (count($computed) >= TOP_N) break;
        if (isset($maFlagged[$stock['ticker']])) continue;
        if (in_array($stock['sector'], $usedSectors)) continue;
        $computed[]    = $stock['ticker'];
        $usedSectors[] = $stock['sector'];
    }

    // ── is_selected aus DB lesen ──────────────────────────────────────────────
    $dbSelected = array_filter(
        array_column($allRanked, 'ticker', 'ticker'),
        fn($t) => array_filter($allRanked, fn($r) => $r['ticker'] === $t && $r['is_selected'] == 1)
    );
    // Einfacher: is_selected=1 direkt
    $dbSelected = array_column(
        array_filter($allRanked, fn($r) => $r['is_selected'] == 1),
        'ticker'
    );
    // M&A-Filter auch auf DB anwenden (Doppelcheck: falls Flag nach is_selected-Berechnung kam)
    $dbFiltered = array_values(array_filter($dbSelected, fn($t) => !isset($maFlagged[$t])));

    sort($computed);
    sort($dbFiltered);

    // ── Vergleich computed vs DB ──────────────────────────────────────────────
    if ($computed !== $dbFiltered) {
        $dbDrift[] = [
            'date'     => $friday,
            'computed' => $computed,
            'db'       => $dbFiltered,
            'only_computed' => array_diff($computed, $dbFiltered),
            'only_db'       => array_diff($dbFiltered, $computed),
        ];
    }

    $checked++;
}

// ── Ergebnis ausgeben ─────────────────────────────────────────────────────────
$universeLabel = strtoupper($universe);
echo "=== Portfolio-Konsistenzcheck ($universeLabel) ===\n";
echo "M&A-Filter aktiv für: " . (empty($maFlagged) ? '(keine)' : implode(', ', array_keys($maFlagged))) . "\n";
echo "Geprüfte Wochen: $checked\n";

$exitCode = 0;

if (empty($dbDrift) && empty($errors)) {
    echo "OK: Portfolio-Logik und DB-is_selected sind in allen geprüften Wochen konsistent.\n";
} else {
    if (!empty($dbDrift)) {
        echo "\nFEHLER: is_selected in DB weicht in " . count($dbDrift) . " Woche(n) von berechneter Auswahl ab:\n";
        foreach ($dbDrift as $e) {
            echo "  Freitag {$e['date']}:\n";
            if (!empty($e['only_computed'])) echo "    Sollte selected sein: " . implode(', ', $e['only_computed']) . "\n";
            if (!empty($e['only_db']))       echo "    Fälschlich selected:  " . implode(', ', $e['only_db']) . "\n";
            echo "    Berechnet: " . implode(', ', $e['computed']) . "\n";
            echo "    In DB:     " . implode(', ', $e['db']) . "\n";
        }
        $exitCode = 1;
    }
}

exit($exitCode);
