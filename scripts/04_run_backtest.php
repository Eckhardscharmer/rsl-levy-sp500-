#!/usr/bin/env php
<?php
/**
 * Sprint 4 — Backtest Engine
 * Strategie: Halte Position bis Rang > 125; ersetze durch besten RSL aus neuem Sektor
 * Sektor-Diversifikation, Equal Weight
 *
 * Aufruf:
 *   php scripts/04_run_backtest.php
 *   php scripts/04_run_backtest.php --capital=50000
 */

chdir(dirname(__DIR__));
require_once 'config/database.php';

// ---- Parameter ----
$args    = array_slice($argv, 1);
$capital = 100000.0;
foreach ($args as $a) {
    if (preg_match('/--capital=(\d+)/', $a, $m)) $capital = (float)$m[1];
}

define('TOP_N',            5);
define('HOLD_RANK',        125);    // Verkauf wenn Rang > 125
define('BACKTEST_START',   '2010-01-04');

echo "=== Backtest Engine ===\n";
echo "Startkapital:       " . number_format($capital, 2) . " USD\n";
echo "Haltekriterium:     Rang <= " . HOLD_RANK . " (Verkauf wenn darunter)\n";
echo "Start:              " . BACKTEST_START . "\n\n";

$db = getDB();

// Backtest-Konfiguration anlegen
$db->prepare(
    'DELETE FROM backtest_configs WHERE name = "RSL_Top5_Weekly"'
)->execute();

$db->prepare(
    'INSERT INTO backtest_configs
       (name, start_date, end_date, initial_capital, num_positions,
        sma_weeks, transaction_cost, sector_diversify)
     VALUES (?, ?, ?, ?, 5, 26, ?, 1)'
)->execute(['RSL_Top5_Weekly', BACKTEST_START, date('Y-m-d'), $capital, 0]);
$configId = $db->lastInsertId();

// Alle berechneten Sonntage laden
$sundays = $db->query(
    "SELECT DISTINCT ranking_date FROM rsl_rankings
     WHERE ranking_date >= '" . BACKTEST_START . "'
     ORDER BY ranking_date"
)->fetchAll(PDO::FETCH_COLUMN);

if (empty($sundays)) {
    die("[FEHLER] Keine RSL-Rankings vorhanden. Erst script 03 ausführen.\n");
}

echo "Simuliere " . count($sundays) . " Wochen...\n\n";

// is_selected zurücksetzen — Backtest setzt es neu anhand tatsächlicher Holdings
$db->exec(
    "UPDATE rsl_rankings SET is_selected = 0 WHERE ranking_date >= '" . BACKTEST_START . "'"
);

// ---- Portfolio-Zustand ----
$cash        = $capital;
$holdings    = [];   // ticker => ['shares', 'buy_price', 'sector', 'rsl_buy']
$totalTrades = 0;

$stmtTrade = $db->prepare(
    'INSERT INTO backtest_trades
       (config_id, trade_date, ticker, action, price, shares, gross_amount, transaction_cost, net_amount, sector, rsl_at_trade)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmtPortVal = $db->prepare(
    'INSERT INTO backtest_portfolio_values
       (config_id, value_date, portfolio_value, cash, invested, num_trades, sp500_close, sp500_indexed)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       portfolio_value=VALUES(portfolio_value), cash=VALUES(cash),
       invested=VALUES(invested), num_trades=VALUES(num_trades),
       sp500_close=VALUES(sp500_close), sp500_indexed=VALUES(sp500_indexed)'
);

// SPY Startkurs für Benchmark
$spyStart = getPrice($db, 'SPY', BACKTEST_START);
if (!$spyStart) $spyStart = getPrice($db, '^GSPC', BACKTEST_START);

// M&A-Flags laden (nur für aktuelle Woche relevant, nicht für historische Daten)
$maFlagged = [];
$maStmt = $db->query('SELECT ticker FROM m_and_a_flags WHERE is_active = 1');
foreach ($maStmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $maFlagged[$t] = true;
}
$lastSundayInLoop = end($sundays);
if (!empty($maFlagged)) {
    echo "M&A-Filter aktiv für aktuelle Woche: " . implode(', ', array_keys($maFlagged)) . "\n";
}

$weekNum = 0;

foreach ($sundays as $sunday) {
    $weekNum++;
    $tradesThisWeek = 0;
    $saleProceeds   = [];  // Verkaufserlöse dieser Woche (je Slot)

    // Alle Rankings dieser Woche, nach Rang sortiert
    $stmt = $db->prepare(
        'SELECT ticker, sector, current_price, rsl, rank_overall
         FROM rsl_rankings
         WHERE ranking_date = ?
         ORDER BY rank_overall ASC'
    );
    $stmt->execute([$sunday]);
    $allRankings = $stmt->fetchAll();

    if (empty($allRankings)) continue;

    $rankingByTicker = array_column($allRankings, null, 'ticker');

    // 1. VERKAUF: Positionen die unter Rang 125 gefallen oder nicht mehr im Index
    foreach (array_keys($holdings) as $ticker) {
        $rank = isset($rankingByTicker[$ticker])
            ? (int)$rankingByTicker[$ticker]['rank_overall']
            : PHP_INT_MAX;

        if ($rank > HOLD_RANK) {
            $price = (float)(
                $rankingByTicker[$ticker]['current_price']
                ?? getPrice($db, $ticker, $sunday)
                ?? $holdings[$ticker]['buy_price']
            );

            $shares = $holdings[$ticker]['shares'];
            $gross  = $shares * $price;
            $cost   = 0;
            $net    = $gross;
            $cash  += $net;

            // Erlös merken — wird 1:1 in den Nachfolger reinvestiert
            $saleProceeds[] = $net;

            $stmtTrade->execute([
                $configId, $sunday, $ticker, 'SELL', $price, $shares,
                $gross, $cost, $net,
                $holdings[$ticker]['sector'],
                $holdings[$ticker]['rsl_buy'] ?? null,
            ]);
            unset($holdings[$ticker]);
            $tradesThisWeek++;
        }
    }

    // 2. KAUF: freie Slots mit bestem RSL aus neuem Sektor füllen
    // Slot-Kapital: Verkaufserlös des abgelösten Slots (kein Nachschuss, kein Umverteilen)
    // Erstkauf (leerer Slot ohne Vorgänger): gleichmäßig aus verfügbarem Cash
    $vacancies    = TOP_N - count($holdings);
    $heldSectors  = array_column(array_values($holdings), 'sector');
    $cashPerSlot  = $vacancies > 0 ? $cash / $vacancies : 0;  // nur für Erstkäufe

    if ($vacancies > 0) {
        foreach ($allRankings as $stock) {
            if ($vacancies <= 0) break;
            if (isset($holdings[$stock['ticker']])) continue;

            // M&A-Filter: nur für die aktuellste (aktuelle) Woche anwenden
            if ($sunday === $lastSundayInLoop && isset($maFlagged[$stock['ticker']])) continue;

            $sector = $stock['sector'] ?? 'Unknown';
            if (in_array($sector, $heldSectors)) continue;

            $price = (float)$stock['current_price'];
            if ($price <= 0) continue;

            // Slot-Budget: Erlös des verkauften Slots oder Cash-Anteil beim Erstkauf
            $slotBudget = !empty($saleProceeds) ? array_shift($saleProceeds) : $cashPerSlot;
            if ($slotBudget < 1) continue;

            $gross = $slotBudget;
            $cost  = 0;
            $net   = $slotBudget;

            $shares = $gross / $price;
            $cash  -= $net;

            $holdings[$stock['ticker']] = [
                'shares'    => $shares,
                'buy_price' => $price,
                'sector'    => $sector,
                'rsl_buy'   => (float)$stock['rsl'],
            ];

            $stmtTrade->execute([
                $configId, $sunday, $stock['ticker'], 'BUY', $price, $shares,
                $gross, $cost, $net, $sector, (float)$stock['rsl'],
            ]);

            $heldSectors[] = $sector;
            $vacancies--;
            $tradesThisWeek++;
        }
    }

    // 3. is_selected für diese Woche aktualisieren
    // Erst alle auf 0 zurücksetzen, dann exakt die gehaltenen Positionen auf 1
    $db->prepare("UPDATE rsl_rankings SET is_selected = 0 WHERE ranking_date = ?")->execute([$sunday]);
    if (!empty($holdings)) {
        $placeholders = implode(',', array_fill(0, count($holdings), '?'));
        $params = array_merge([$sunday], array_keys($holdings));
        $db->prepare(
            "UPDATE rsl_rankings SET is_selected = 1
             WHERE ranking_date = ? AND ticker IN ($placeholders)"
        )->execute($params);
    }

    // Portfolio-Gesamtwert
    $invested = 0;
    foreach ($holdings as $ticker => $h) {
        $currentPrice = (float)(
            $rankingByTicker[$ticker]['current_price']
            ?? getPrice($db, $ticker, $sunday)
            ?? $h['buy_price']
        );
        $invested += $h['shares'] * $currentPrice;
    }
    $portfolioTotal = $cash + $invested;
    $totalTrades   += $tradesThisWeek;

    // SPY Benchmark
    $spyClose   = getPrice($db, 'SPY', $sunday);
    $spyIndexed = ($spyStart && $spyClose) ? ($spyClose / $spyStart * $capital) : null;

    $stmtPortVal->execute([
        $configId, $sunday, $portfolioTotal, $cash, $invested,
        $tradesThisWeek, $spyClose, $spyIndexed,
    ]);

    // Fortschritt
    if ($weekNum % 20 === 0) {
        $return = (($portfolioTotal - $capital) / $capital) * 100;
        echo sprintf(
            "[Woche %3d | %s] Portfolio: %s USD (%+.1f%%) | Positionen: %d | Trades: %d\n",
            $weekNum, $sunday,
            number_format($portfolioTotal, 0, '.', ','),
            $return,
            count($holdings),
            $tradesThisWeek
        );
    }
}

// ---- Metriken berechnen ----
echo "\nBerechne Performance-Metriken...\n";
calculateAndSaveMetrics($db, $configId, $capital);

echo "\n=== Backtest abgeschlossen ===\n";
printResults($db, $configId);

// Aktuelles Portfolio anzeigen
echo "\nAktuelles Portfolio:\n";
echo str_pad('Ticker', 8) . str_pad('Sektor', 35) . str_pad('Kurs', 10) . str_pad('RSL', 8) . "Rang\n";
echo str_repeat('-', 70) . "\n";
$latestSunday = end($sundays);
$portfolio = $db->prepare(
    'SELECT r.ticker, r.sector, r.current_price, r.rsl, r.rank_overall
     FROM rsl_rankings r
     WHERE r.ranking_date = ? AND r.is_selected = 1
     ORDER BY r.rsl DESC'
);
$portfolio->execute([$latestSunday]);
foreach ($portfolio->fetchAll() as $p) {
    echo str_pad($p['ticker'], 8)
       . str_pad(substr($p['sector'] ?? '', 0, 34), 35)
       . str_pad(number_format($p['current_price'], 2), 10)
       . str_pad(number_format($p['rsl'], 3), 8)
       . '#' . $p['rank_overall'] . "\n";
}

echo "\nNächster Schritt: php scripts/05_start_server.php (oder Browser öffnen)\n";

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function getPrice(PDO $db, string $ticker, string $date): ?float {
    static $cache = [];
    $key = "$ticker|$date";
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $db->prepare(
        'SELECT adj_close FROM prices
         WHERE ticker = ? AND price_date <= ?
         ORDER BY price_date DESC LIMIT 1'
    );
    $stmt->execute([$ticker, $date]);
    $val = $stmt->fetchColumn();
    $cache[$key] = $val ? (float)$val : null;
    return $cache[$key];
}

function portfolioValue(PDO $db, array $holdings, string $date): float {
    $total = 0;
    foreach ($holdings as $ticker => $h) {
        $price = getPrice($db, $ticker, $date) ?? $h['buy_price'];
        $total += $h['shares'] * $price;
    }
    return $total;
}

function calculateAndSaveMetrics(PDO $db, int $configId, float $capital): void {
    $values = $db->prepare(
        'SELECT value_date, portfolio_value, sp500_indexed
         FROM backtest_portfolio_values
         WHERE config_id = ?
         ORDER BY value_date'
    );
    $values->execute([$configId]);
    $rows = $values->fetchAll();

    if (empty($rows)) return;

    $first  = reset($rows);
    $last   = end($rows);
    $endVal = (float)$last['portfolio_value'];

    // CAGR
    $days  = (strtotime($last['value_date']) - strtotime($first['value_date'])) / 86400;
    $years = max($days / 365.25, 0.01);
    $cagr  = (pow($endVal / $capital, 1 / $years) - 1) * 100;

    // Max Drawdown
    $peak = $capital; $maxDD = 0;
    $weeklyReturns = [];
    $prevVal = $capital;
    foreach ($rows as $r) {
        $v = (float)$r['portfolio_value'];
        if ($v > $peak) $peak = $v;
        $dd = ($peak - $v) / $peak * 100;
        if ($dd > $maxDD) $maxDD = $dd;
        $weeklyReturns[] = ($v - $prevVal) / max($prevVal, 1);
        $prevVal = $v;
    }

    // Sharpe Ratio (wöchentliche Returns, risikoloser Zins 4% p.a.)
    $rfWeekly      = 0.04 / 52;
    $excessReturns = array_map(fn($r) => $r - $rfWeekly, $weeklyReturns);
    $mean          = array_sum($excessReturns) / max(count($excessReturns), 1);
    $variance      = array_sum(array_map(fn($r) => pow($r - $mean, 2), $excessReturns))
                     / max(count($excessReturns) - 1, 1);
    $sharpe        = $variance > 0 ? ($mean / sqrt($variance)) * sqrt(52) : 0;

    // Benchmark-Return
    $benchmarkEnd    = (float)$last['sp500_indexed'];
    $benchmarkReturn = $benchmarkEnd > 0 ? (($benchmarkEnd - $capital) / $capital) * 100 : 0;

    // Trade-Statistiken
    $tradeCount = $db->prepare('SELECT COUNT(*) FROM backtest_trades WHERE config_id = ?');
    $tradeCount->execute([$configId]);
    $totalTrades = (int)$tradeCount->fetchColumn();

    $db->prepare(
        'INSERT INTO backtest_results
           (config_id, total_return_pct, cagr_pct, max_drawdown_pct,
            sharpe_ratio, num_total_trades, benchmark_return_pct, outperformance_pct)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           total_return_pct=VALUES(total_return_pct), cagr_pct=VALUES(cagr_pct),
           max_drawdown_pct=VALUES(max_drawdown_pct), sharpe_ratio=VALUES(sharpe_ratio),
           num_total_trades=VALUES(num_total_trades),
           benchmark_return_pct=VALUES(benchmark_return_pct),
           outperformance_pct=VALUES(outperformance_pct)'
    )->execute([
        $configId,
        round(($endVal - $capital) / $capital * 100, 2),
        round($cagr, 2),
        round($maxDD, 2),
        round($sharpe, 3),
        $totalTrades,
        round($benchmarkReturn, 2),
        round((($endVal - $capital) / $capital * 100) - $benchmarkReturn, 2),
    ]);
}

function printResults(PDO $db, int $configId): void {
    $r = $db->prepare('SELECT * FROM backtest_results WHERE config_id = ?');
    $r->execute([$configId]);
    $res = $r->fetch();
    if (!$res) return;

    $cfg = $db->prepare('SELECT start_date, end_date FROM backtest_configs WHERE id = ?');
    $cfg->execute([$configId]);
    $c = $cfg->fetch();

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "BACKTEST-ERGEBNISSE\n";
    echo str_repeat('=', 50) . "\n";
    echo sprintf("Gesamt-Rendite:     %+.2f%%\n", $res['total_return_pct']);
    echo sprintf("CAGR:               %+.2f%%\n", $res['cagr_pct']);
    echo sprintf("Max. Drawdown:      -%.2f%%\n", $res['max_drawdown_pct']);
    echo sprintf("Sharpe Ratio:       %.3f\n",    $res['sharpe_ratio']);
    echo sprintf("Benchmark (S&P500): %+.2f%%\n", $res['benchmark_return_pct']);
    echo sprintf("Outperformance:     %+.2f%%\n", $res['outperformance_pct']);
    echo sprintf("Anzahl Trades:      %d\n",       $res['num_total_trades']);
    echo str_repeat('=', 50) . "\n";
}
