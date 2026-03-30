#!/usr/bin/env php
<?php
/**
 * Sprint 4 — Backtest Engine
 * Simuliert wöchentliches RSL-Top-5-Portfolio (2020 bis heute)
 * Sektor-Diversifikation, 0,1% Transaktionskosten, Equal Weight
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

define('TRANSACTION_COST', 0.001);  // 0,1%
define('TOP_N',            5);
define('BACKTEST_START',   '2020-01-05');

echo "=== Backtest Engine ===\n";
echo "Startkapital:       " . number_format($capital, 2) . " USD\n";
echo "Transaktionskosten: " . (TRANSACTION_COST * 100) . "%\n";
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
)->execute(['RSL_Top5_Weekly', BACKTEST_START, date('Y-m-d'), $capital, TRANSACTION_COST]);
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

// ---- Portfolio-Zustand ----
$cash        = $capital;
$holdings    = [];   // ticker => ['shares' => x, 'buy_price' => y, 'sector' => z]
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

$prevTop5  = [];
$weekNum   = 0;

foreach ($sundays as $sunday) {
    $weekNum++;
    $tradesThisWeek = 0;

    // Top-5 für diese Woche
    $stmt = $db->prepare(
        'SELECT ticker, sector, current_price, rsl
         FROM rsl_rankings
         WHERE ranking_date = ? AND is_selected = 1
         ORDER BY rsl DESC'
    );
    $stmt->execute([$sunday]);
    $newTop5 = $stmt->fetchAll();

    if (empty($newTop5)) continue;

    $newTop5Tickers = array_column($newTop5, 'ticker');
    $newTop5ByTicker = array_column($newTop5, null, 'ticker');

    // 1. Verkäufe: Positionen die aus Top-5 herausgefallen sind
    foreach (array_keys($holdings) as $ticker) {
        if (!in_array($ticker, $newTop5Tickers)) {
            $price = $newTop5ByTicker[$ticker]['current_price']
                  ?? getPrice($db, $ticker, $sunday)
                  ?? $holdings[$ticker]['buy_price'];

            $shares     = $holdings[$ticker]['shares'];
            $gross      = $shares * $price;
            $cost       = $gross * TRANSACTION_COST;
            $net        = $gross - $cost;
            $cash      += $net;

            $stmtTrade->execute([
                $configId, $sunday, $ticker, 'SELL', $price, $shares,
                $gross, $cost, $net, $holdings[$ticker]['sector'],
                $holdings[$ticker]['rsl_buy'] ?? null
            ]);
            unset($holdings[$ticker]);
            $tradesThisWeek++;
        }
    }

    // 2. Käufe: Neue Top-5-Aktien die noch nicht im Portfolio sind
    $toBuy = array_diff($newTop5Tickers, array_keys($holdings));
    if (!empty($toBuy)) {
        // Gleichmäßig auf alle Positionen aufteilen (inkl. bestehende)
        $totalPositions = TOP_N;
        $targetValue    = ($cash + portfolioValue($db, $holdings, $sunday)) / $totalPositions;

        foreach ($newTop5 as $stock) {
            if (!in_array($stock['ticker'], $toBuy)) continue;

            $price = $stock['current_price'];
            if ($price <= 0) continue;

            $investAmount = min($targetValue, $cash * 0.98); // nie alles auf einmal
            if ($investAmount < 10) continue;

            $gross  = $investAmount;
            $cost   = $gross * TRANSACTION_COST;
            $net    = $gross + $cost;

            if ($net > $cash) {
                $gross  = $cash / (1 + TRANSACTION_COST);
                $cost   = $gross * TRANSACTION_COST;
                $net    = $cash;
            }

            $shares = $gross / $price;
            $cash  -= $net;

            $holdings[$stock['ticker']] = [
                'shares'    => $shares,
                'buy_price' => $price,
                'sector'    => $stock['sector'],
                'rsl_buy'   => $stock['rsl'],
            ];

            $stmtTrade->execute([
                $configId, $sunday, $stock['ticker'], 'BUY', $price, $shares,
                $gross, $cost, $net, $stock['sector'], $stock['rsl']
            ]);
            $tradesThisWeek++;
        }
    }

    // Portfolio-Gesamtwert berechnen
    $invested  = 0;
    foreach ($holdings as $ticker => $h) {
        $currentPrice = $newTop5ByTicker[$ticker]['current_price']
                     ?? getPrice($db, $ticker, $sunday)
                     ?? $h['buy_price'];
        $invested += $h['shares'] * $currentPrice;
    }
    $portfolioTotal = $cash + $invested;
    $totalTrades   += $tradesThisWeek;

    // SPY Benchmark
    $spyClose   = getPrice($db, 'SPY', $sunday);
    $spyIndexed = ($spyStart && $spyClose) ? ($spyClose / $spyStart * $capital) : null;

    $stmtPortVal->execute([
        $configId, $sunday, $portfolioTotal, $cash, $invested,
        $tradesThisWeek, $spyClose, $spyIndexed
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
    $days = (strtotime($last['value_date']) - strtotime($first['value_date'])) / 86400;
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
    $rfWeekly = 0.04 / 52;
    $excessReturns = array_map(fn($r) => $r - $rfWeekly, $weeklyReturns);
    $mean = array_sum($excessReturns) / max(count($excessReturns), 1);
    $variance = array_sum(array_map(fn($r) => pow($r - $mean, 2), $excessReturns)) / max(count($excessReturns) - 1, 1);
    $sharpe = $variance > 0 ? ($mean / sqrt($variance)) * sqrt(52) : 0;

    // Benchmark-Return
    $benchmarkEnd   = (float)$last['sp500_indexed'];
    $benchmarkReturn = $benchmarkEnd > 0 ? (($benchmarkEnd - $capital) / $capital) * 100 : 0;

    // Trade-Statistiken
    $trades = $db->prepare(
        'SELECT action, trade_date,
                (net_amount - gross_amount) as pnl
         FROM backtest_trades WHERE config_id = ?'
    );
    $trades->execute([$configId]);
    $tradeRows  = $trades->fetchAll();
    $totalTrades = count($tradeRows);

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
    $r = $db->prepare(
        'SELECT * FROM backtest_results WHERE config_id = ?'
    );
    $r->execute([$configId]);
    $res = $r->fetch();
    if (!$res) return;

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "BACKTEST-ERGEBNISSE\n";
    echo str_repeat('=', 50) . "\n";
    echo sprintf("Gesamt-Rendite:     %+.2f%%\n", $res['total_return_pct']);
    echo sprintf("CAGR:               %+.2f%%\n", $res['cagr_pct']);
    echo sprintf("Max. Drawdown:      -%.2f%%\n", $res['max_drawdown_pct']);
    echo sprintf("Sharpe Ratio:       %.3f\n",     $res['sharpe_ratio']);
    echo sprintf("Benchmark (S&P500): %+.2f%%\n",  $res['benchmark_return_pct']);
    echo sprintf("Outperformance:     %+.2f%%\n",  $res['outperformance_pct']);
    echo sprintf("Anzahl Trades:      %d\n",        $res['num_total_trades']);
    echo str_repeat('=', 50) . "\n";
}
