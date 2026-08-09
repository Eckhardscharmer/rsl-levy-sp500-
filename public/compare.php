<?php
require_once __DIR__ . '/../src/RSLEngine.php';
$db = getDB();

// --- Parameter ---
$startDate    = $_GET['start_date'] ?? '2024-01-01';
$startCapital = max(10000.0, (float)str_replace('.', '', $_GET['capital'] ?? '50000'));
$startCapital = round($startCapital / 1000) * 1000;
if ($startDate < '2010-01-04') $startDate = '2010-01-04';

// EUR/USD-Kurs zu einem Datum
function getEurUsdAt(PDO $db, string $date): float {
    $s = $db->prepare('SELECT adj_close FROM prices WHERE ticker="EURUSD=X" AND price_date <= ? ORDER BY price_date DESC LIMIT 1');
    $s->execute([$date]);
    return (float)($s->fetchColumn() ?: 1.10);
}

$startEurUsd   = getEurUsdAt($db, $startDate);
$currentEurUsd = getEurUsdAt($db, date('Y-m-d'));

// M&A-Flags
$maFlagged = [];
foreach ($db->query('SELECT ticker FROM m_and_a_flags WHERE is_active=1 AND (expires_date IS NULL OR expires_date > CURDATE())')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $maFlagged[$t] = true;
}

// --- Benchmark-Rendite ---
function getBenchReturn(PDO $db, string $ticker, string $from, string $to): ?float {
    $s = $db->prepare('SELECT adj_close FROM prices WHERE ticker=? AND price_date <= ? ORDER BY price_date DESC LIMIT 1');
    $s->execute([$ticker, $from]);
    $start = (float)($s->fetchColumn() ?: 0);
    $s2 = $db->prepare('SELECT adj_close FROM prices WHERE ticker=? AND price_date <= ? ORDER BY price_date DESC LIMIT 1');
    $s2->execute([$ticker, $to]);
    $end = (float)($s2->fetchColumn() ?: 0);
    return ($start > 0 && $end > 0) ? ($end / $start - 1) * 100 : null;
}

// --- Simulation ---
function runSim(PDO $db, string $universe, string $startDate, float $startCapital,
                float $startEurUsd, float $currentEurUsd, array $maFlagged): array
{
    $isSp   = ($universe === 'sp500');
    $isDax  = ($universe === 'dax');
    $isHdax = ($universe === 'hdax');

    $stmt = $db->prepare(
        'SELECT r.ranking_date, r.ticker, r.rsl, r.current_price, r.rank_overall,
                COALESCE(r.sector,"Unknown") as sector
         FROM rsl_rankings r
         WHERE r.ranking_date >= ? AND r.universe = ?
         ORDER BY r.ranking_date ASC, r.rank_overall ASC'
    );
    $stmt->execute([$startDate, $universe]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // EUR/USD am ersten tatsächlichen Ranking-Tag verwenden (nicht Input-Datum, das kein Handelstag sein kann)
    // → konsistent mit backtest.php (eurAtPortStart) und simulation.php (startEurUsdSim)
    if ($isSp && !empty($rows)) {
        $firstRankingDate = $rows[0]['ranking_date'];
        if ($firstRankingDate !== $startDate) {
            $startEurUsd = getEurUsdAt($db, $firstRankingDate);
        }
    }

    // S&P 500 intern in USD simulieren
    $simCap = $isSp ? $startCapital * $startEurUsd : $startCapital;
    if (empty($rows)) return [];

    $byDate = [];
    foreach ($rows as $r) $byDate[$r['ranking_date']][] = $r;

    $holdRankBase = $isHdax ? 25 : ($isDax ? 10 : 125);
    $cash     = $simCap;
    $holdings = [];
    $weekly   = [];   // date => portfolio value (native currency)
    $maxPv    = $simCap;
    $maxDD    = 0.0;

    foreach ($byDate as $date => $weekRankings) {
        $rankByTicker = array_column($weekRankings, null, 'ticker');
        $saleProceeds = [];
        $holdRank = ($isDax && $date < '2021-09-20') ? 7 : $holdRankBase;

        foreach (array_keys($holdings) as $ticker) {
            $rank = isset($rankByTicker[$ticker]) ? (int)$rankByTicker[$ticker]['rank_overall'] : PHP_INT_MAX;
            if ($rank > $holdRank) {
                $price = (float)($rankByTicker[$ticker]['current_price'] ?? $holdings[$ticker]['buy_price']);
                $proceeds = $holdings[$ticker]['shares'] * $price;
                $cash += $proceeds;
                $saleProceeds[] = $proceeds;
                unset($holdings[$ticker]);
            }
        }

        $vacancies   = 5 - count($holdings);
        $heldSectors = array_column(array_values($holdings), 'sector');
        $cashPerSlot = $vacancies > 0 ? $cash / $vacancies : 0;

        foreach ($weekRankings as $stock) {
            if ($vacancies <= 0) break;
            if (isset($holdings[$stock['ticker']])) continue;
            if (isset($maFlagged[$stock['ticker']])) continue;
            $sector = $stock['sector'] ?? 'Unknown';
            if (in_array($sector, $heldSectors)) continue;
            $price = (float)$stock['current_price'];
            if ($price <= 0) continue;
            $budget = !empty($saleProceeds) ? array_shift($saleProceeds) : $cashPerSlot;
            if ($budget < 1) continue;
            $cash -= $budget;
            $holdings[$stock['ticker']] = ['shares' => $budget / $price, 'buy_price' => $price, 'sector' => $sector];
            $heldSectors[] = $sector;
            $vacancies--;
        }

        $invested = 0.0;
        foreach ($holdings as $ticker => $h) {
            $price = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            $invested += $h['shares'] * $price;
        }
        $pv = $cash + $invested;
        $weekly[$date] = $pv;
        if ($pv > $maxPv) $maxPv = $pv;
        $dd = $maxPv > 0 ? ($maxPv - $pv) / $maxPv * 100 : 0;
        if ($dd > $maxDD) $maxDD = $dd;
    }

    if (empty($weekly)) return [];

    $firstDate = array_key_first($weekly);
    $lastDate  = array_key_last($weekly);
    $firstPv   = $weekly[$firstDate];
    $lastPv    = $weekly[$lastDate];

    // EUR/USD am Enddatum für S&P 500
    $endEurUsd = $isSp ? getEurUsdAt($db, $lastDate) : 1.0;

    // Rendite in EUR
    if ($isSp) {
        $firstEur = $firstPv / $startEurUsd;
        $lastEur  = $lastPv  / $endEurUsd;
        $totalReturn   = ($lastEur / $firstEur - 1) * 100;
        $finalValueEur = $lastEur;
    } else {
        $totalReturn   = ($lastPv / $firstPv - 1) * 100;
        $finalValueEur = $lastPv;
    }

    // CAGR
    $years = max(0.01, (strtotime($lastDate) - strtotime($firstDate)) / (365.25 * 86400));
    $cagr  = $isSp
        ? (pow($lastPv / $endEurUsd * $startEurUsd / $firstPv, 1 / $years) - 1) * 100
        : (pow($lastPv / $firstPv, 1 / $years) - 1) * 100;

    // Benchmark
    $benchTicker = $isHdax ? '^HDAX' : ($isDax ? '^GDAXI' : 'SPY');
    $benchReturn = getBenchReturn($db, $benchTicker, $firstDate, $lastDate);
    // SPY ist USD-denominiert → gleiche EUR-Konvertierung wie Portfolio
    if ($isSp && $benchReturn !== null) {
        $spyStartPrice = null; $spyEndPrice = null;
        $bs = $db->prepare('SELECT adj_close FROM prices WHERE ticker="SPY" AND price_date <= ? ORDER BY price_date DESC LIMIT 1');
        $bs->execute([$firstDate]);
        $spyStartPrice = (float)($bs->fetchColumn() ?: 0);
        $bs2 = $db->prepare('SELECT adj_close FROM prices WHERE ticker="SPY" AND price_date <= ? ORDER BY price_date DESC LIMIT 1');
        $bs2->execute([$lastDate]);
        $spyEndPrice = (float)($bs2->fetchColumn() ?: 0);
        if ($spyStartPrice > 0 && $spyEndPrice > 0) {
            // Benchmark auch in EUR: spyEnd/endEurUsd vs spyStart/startEurUsd
            $benchReturn = ($spyEndPrice / $endEurUsd) / ($spyStartPrice / $startEurUsd) * 100 - 100;
            $benchReturnUsd = ($spyEndPrice / $spyStartPrice - 1) * 100;
        }
    }

    $outperf = ($benchReturn !== null) ? $totalReturn - $benchReturn : null;

    // USD-Werte für S&P 500 (für Währungsumschaltung in JS)
    $finalValueUsd  = $isSp ? $lastPv : $finalValueEur;
    $startCapUsd    = $isSp ? $startCapital * $startEurUsd : $startCapital;
    $totalReturnUsd = $isSp ? ($lastPv / $firstPv - 1) * 100 : $totalReturn;
    $cagrUsd        = $isSp ? (pow($lastPv / $firstPv, 1 / $years) - 1) * 100 : $cagr;
    $benchReturnUsd = $benchReturnUsd ?? $benchReturn;
    $outperfUsd     = ($benchReturnUsd !== null) ? $totalReturnUsd - $benchReturnUsd : null;

    // Chart-Daten: rohe Portfolio-Werte (Normierung erfolgt nach gemeinsamen Datum)
    $chartDates  = array_keys($weekly);
    $chartValues = array_values($weekly);

    return [
        'universe'      => $universe,
        'firstDate'     => $firstDate,
        'lastDate'      => $lastDate,
        'startCapital'  => $startCapital,
        'startCapUsd'   => $startCapUsd,
        'finalValueEur' => $finalValueEur,
        'finalValueUsd' => $finalValueUsd,
        'totalReturn'   => $totalReturn,
        'totalReturnUsd'=> $totalReturnUsd,
        'cagr'          => $cagr,
        'cagrUsd'       => $cagrUsd,
        'maxDD'         => $maxDD,
        'benchReturn'   => $benchReturn,
        'benchReturnUsd'=> $benchReturnUsd,
        'benchTicker'   => $benchTicker,
        'outperf'       => $outperf,
        'outperfUsd'    => $outperfUsd,
        'isSp'          => $isSp,
        'chartDates'    => $chartDates,
        'chartValues'   => $chartValues,
    ];
}

$results = [];
foreach (['sp500', 'dax', 'hdax'] as $univ) {
    $r = runSim($db, $univ, $startDate, $startCapital, $startEurUsd, $currentEurUsd, $maFlagged);
    if (!empty($r)) $results[$univ] = $r;
}

// ETF: eigene Simulationslogik (monatlich, Top 3, Gleichgewichtung, kein Sektorfilter)
function runEtfSim(PDO $db, string $startDate, float $startCapital): array {
    $stmt = $db->prepare(
        'SELECT ranking_date, ticker, current_price, rsl, is_selected
         FROM rsl_rankings
         WHERE ranking_date >= ? AND universe = "etf"
         ORDER BY ranking_date ASC, rank_overall ASC'
    );
    $stmt->execute([$startDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return [];

    $byDate = [];
    foreach ($rows as $r) $byDate[$r['ranking_date']][] = $r;

    $cash     = $startCapital;
    $holdings = [];
    $monthly  = [];
    $maxPv    = $startCapital;
    $maxDD    = 0.0;

    foreach ($byDate as $date => $monthRankings) {
        $rankByTicker = array_column($monthRankings, null, 'ticker');
        $targets = array_filter($monthRankings, fn($r) => (int)$r['is_selected'] === 1);
        $targets = array_column($targets, null, 'ticker');

        // Full-Rebalancing: alles verkaufen, neu kaufen
        $totalPv = $cash;
        foreach ($holdings as $ticker => $h) {
            $price = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            $totalPv += $h['shares'] * $price;
        }
        $cash     = $totalPv;
        $holdings = [];

        $n = count($targets);
        if ($n > 0) {
            $perSlot = $cash / $n;
            foreach ($targets as $ticker => $r) {
                $price = (float)$r['current_price'];
                if ($price <= 0) continue;
                $cash -= $perSlot;
                $holdings[$ticker] = ['shares' => $perSlot / $price, 'buy_price' => $price];
            }
        }

        $invested = 0;
        foreach ($holdings as $ticker => $h) {
            $price = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            $invested += $h['shares'] * $price;
        }
        $pv = $cash + $invested;
        $monthly[$date] = $pv;
        if ($pv > $maxPv) $maxPv = $pv;
        $dd = $maxPv > 0 ? ($maxPv - $pv) / $maxPv * 100 : 0;
        if ($dd > $maxDD) $maxDD = $dd;
    }

    if (empty($monthly)) return [];

    $firstDate = array_key_first($monthly);
    $lastDate  = array_key_last($monthly);
    $firstPv   = $monthly[$firstDate];
    $lastPv    = $monthly[$lastDate];

    $totalReturn = ($lastPv / $firstPv - 1) * 100;
    $years = max(0.01, (strtotime($lastDate) - strtotime($firstDate)) / (365.25 * 86400));
    $cagr  = (pow($lastPv / $firstPv, 1 / $years) - 1) * 100;

    // Benchmark: ACWI (USD → EUR via EUR/USD)
    $benchReturn = null;
    $bs = $db->prepare('SELECT adj_close FROM prices WHERE ticker="ACWI" AND price_date <= ? ORDER BY price_date DESC LIMIT 1');
    $bs->execute([$firstDate]); $acwiStart = (float)($bs->fetchColumn() ?: 0);
    $bs->execute([$lastDate]);  $acwiEnd   = (float)($bs->fetchColumn() ?: 0);
    $eu = $db->prepare('SELECT adj_close FROM prices WHERE ticker="EURUSD=X" AND price_date <= ? ORDER BY price_date DESC LIMIT 1');
    $eu->execute([$firstDate]); $fxStart = (float)($eu->fetchColumn() ?: 1.10);
    $eu->execute([$lastDate]);  $fxEnd   = (float)($eu->fetchColumn() ?: 1.10);
    if ($acwiStart > 0 && $acwiEnd > 0 && $fxStart > 0) {
        $benchReturn = ($acwiEnd / $fxEnd) / ($acwiStart / $fxStart) * 100 - 100;
    }

    $outperf = $benchReturn !== null ? $totalReturn - $benchReturn : null;

    return [
        'universe'      => 'etf',
        'firstDate'     => $firstDate,
        'lastDate'      => $lastDate,
        'startCapital'  => $startCapital,
        'finalValueEur' => $lastPv,
        'totalReturn'   => $totalReturn,
        'cagr'          => $cagr,
        'maxDD'         => $maxDD,
        'benchReturn'   => $benchReturn,
        'benchTicker'   => 'ACWI (EUR)',
        'outperf'       => $outperf,
        'chartDates'    => array_keys($monthly),
        'chartValues'   => array_values($monthly),
        'rebalancing'   => 'monthly',
    ];
}

$etfR = runEtfSim($db, $startDate, $startCapital);
if (!empty($etfR)) $results['etf'] = $etfR;

// Gemeinsame Datumsmenge für Chart: UNION aller Daten, sortiert
$allDatesSet = [];
foreach ($results as $r) {
    foreach ($r['chartDates'] as $d) $allDatesSet[$d] = true;
}
ksort($allDatesSet);
$allDates = array_keys($allDatesSet);

// EUR/USD per Chart-Datum (für S&P 500 Chart-Normalisierung)
$eurByDate = [];
if (!empty($allDates) && !empty($results['sp500'])) {
    $eurRaw = $db->query("SELECT price_date, adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date")->fetchAll(PDO::FETCH_KEY_PAIR);
    $eurDts = array_keys($eurRaw);
    $eI = 0; $eN = count($eurDts); $lastEur = $startEurUsd;
    foreach ($allDates as $d) {
        while ($eI < $eN - 1 && $eurDts[$eI + 1] <= $d) $eI++;
        $lastEur = ($eN > 0 && $eurDts[$eI] <= $d) ? (float)$eurRaw[$eurDts[$eI]] : $lastEur;
        $eurByDate[$d] = $lastEur;
    }
}

// Forward-Fill: für jede Serie einen Lookup aufbauen (Datum => letzter verfügbarer Wert)
// Damit funktionieren unterschiedliche Wochentage (HDAX=Freitag, SP500/DAX=Sonntag)
foreach ($results as $univ => &$r) {
    $map = array_combine($r['chartDates'], $r['chartValues']);
    $filled = [];
    $lastVal = null;
    foreach ($allDates as $d) {
        if (isset($map[$d])) $lastVal = $map[$d];
        $filled[$d] = $lastVal; // null wenn noch kein Wert vorhanden
    }
    $r['_filled'] = $filled;
}
unset($r);

// Gemeinsames Startdatum = erstes Datum, an dem ALLE Serien einen Wert haben
$commonStart = null;
foreach ($allDates as $d) {
    $allHave = true;
    foreach ($results as &$r) {
        if ($r['_filled'][$d] === null) { $allHave = false; break; }
    }
    unset($r);
    if ($allHave) { $commonStart = $d; break; }
}

// Normierung: jede Serie auf ihr eigenes firstDate (= Startkapital), nicht auf commonStart
// Normalisierungs-Helfer: S&P in USD → Konvertierung per Datum-EUR/USD; andere Universen EUR-nativ
$normVal = function(string $univ, string $d, float $v, float $base) use ($startCapital, $eurByDate): int {
    if ($univ === 'sp500' && !empty($eurByDate[$d])) {
        return (int)round($v / $eurByDate[$d]);
    }
    return (int)round($v / $base * $startCapital);
};

// Dadurch zeigen S&P/DAX/HDAX ab 07.01. echte Werte; ETF ab 31.01. — vorher null
foreach ($results as $univ => &$r) {
    $ownFirst = $r['firstDate'] ?? null;
    $base = $ownFirst ? ($r['_filled'][$ownFirst] ?? null) : null;
    if ($base && $base > 0) {
        $r['chartValuesNorm'] = array_map(
            fn($d) => ($d >= $ownFirst && $r['_filled'][$d] !== null)
                ? $normVal($univ, $d, $r['_filled'][$d], $base)
                : null,
            $allDates
        );
    } else {
        $r['chartValuesNorm'] = array_fill(0, count($allDates), null);
    }
}
unset($r);

// Chart-Zeitraum: ab dem frühesten firstDate (nicht commonStart), damit S&P/DAX/HDAX ab 07.01. sichtbar
$earliestStart = null;
foreach ($results as $r2) {
    if (!empty($r2['firstDate'])) {
        $earliestStart = $earliestStart ? min($earliestStart, $r2['firstDate']) : $r2['firstDate'];
    }
}
if ($earliestStart) {
    $allDates = array_values(array_filter($allDates, fn($d) => $d >= $earliestStart));
    foreach ($results as $univ => &$r) {
        $ownFirst = $r['firstDate'] ?? null;
        $base = $ownFirst ? ($r['_filled'][$ownFirst] ?? null) : null;
        if ($base && $base > 0) {
            $r['chartValuesNorm'] = array_map(
                fn($d) => ($d >= $ownFirst && $r['_filled'][$d] !== null)
                    ? $normVal($univ, $d, $r['_filled'][$d], $base)
                    : null,
                $allDates
            );
        } else {
            $r['chartValuesNorm'] = array_fill(0, count($allDates), null);
        }
    }
    unset($r);
}

// Gemeinsames Startdatum für wöchentliche Universen (max der firstDates, damit alle aligned)
$weeklyStart = null;
foreach (['sp500','dax','hdax'] as $u) {
    if (!empty($results[$u]['firstDate'])) {
        $weeklyStart = max($weeklyStart ?? $results[$u]['firstDate'], $results[$u]['firstDate']);
    }
}

function fmt(float $v, int $dec = 1, string $suffix = '%'): string {
    return number_format($v, $dec, ',', '.') . $suffix;
}
function fmtEur(float $v): string {
    return number_format(round($v), 0, ',', '.') . ' €';
}
function fmtEurShort(float $v): string {
    $n = round($v);
    if ($n >= 1000000) return number_format($n / 1000000, 2, ',', '.') . ' Mio €';
    if ($n >= 100000)  return number_format($n / 1000, 1, ',', '.') . ' T€';
    return number_format($n, 0, ',', '.') . ' €';
}
function colorClass(float $v): string {
    return $v >= 0 ? 'text-success' : 'text-danger';
}
function arrow(float $v): string {
    return $v >= 0 ? '▲' : '▼';
}

$universe = 'sp500'; // für inc_navbar (kein Universe-Switcher nötig)
$activePage = 'compare';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relative Stärke nach Levy — Strategie-Vergleich</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
body { background:#0a0f1e; color:#e2e8f0; font-family:'Inter',sans-serif; }
.page-title { font-size:1.6rem; font-weight:800; color:#f1f5f9; }
.card-dark { background:#111827; border:1px solid rgba(255,255,255,.08); border-radius:12px; }
.form-section { background:#0f172a; border:1px solid rgba(255,255,255,.08); border-radius:12px; padding:1.5rem 2rem; }
.compare-table { width:100%; border-collapse:collapse; }
.compare-table th, .compare-table td { padding:.75rem 1.1rem; border-bottom:1px solid rgba(255,255,255,.07); font-size:.9rem; }
.compare-table th { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,255,255,.4); }
.compare-table tr:last-child td { border-bottom:none; }
.compare-table td:first-child { color:rgba(255,255,255,.55); font-size:.82rem; }
.universe-badge { display:inline-flex; align-items:center; gap:.35rem; font-weight:700; font-size:.8rem; padding:.22rem .65rem; border-radius:20px; white-space:nowrap; }
.badge-sp500  { background:rgba(29,78,216,.25); color:#93c5fd; border:1px solid rgba(29,78,216,.4); }
.badge-dax    { background:rgba(185,28,28,.25); color:#fca5a5; border:1px solid rgba(185,28,28,.4); }
.badge-hdax   { background:rgba(194,65,12,.25); color:#fdba74; border:1px solid rgba(194,65,12,.4); }
.badge-etf    { background:rgba(16,185,129,.2); color:#6ee7b7; border:1px solid rgba(16,185,129,.35); }
.rebal-box    { background:#111827; border:1px solid rgba(255,255,255,.08); border-radius:12px; padding:1.25rem 1.5rem; margin-bottom:1.5rem; }
.rebal-grid   { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; margin-top:.85rem; }
.rebal-item   { background:#1e293b; border-radius:10px; padding:.85rem 1rem; }
.rebal-title  { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:rgba(255,255,255,.4); margin-bottom:.5rem; }
.rebal-val    { font-size:.85rem; color:#e2e8f0; line-height:1.5; }
.kpi-box { background:#1e293b; border-radius:10px; padding:.7rem .85rem; height:72px; display:flex; flex-direction:column; justify-content:center; }
.kpi-val { font-size:1.1rem; font-weight:800; line-height:1.15; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.kpi-lbl { font-size:.65rem; color:rgba(255,255,255,.4); margin-top:.2rem; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
.metric-row-highlight { background:rgba(255,255,255,.03); }
.bench-chip { font-size:.72rem; color:rgba(255,255,255,.35); font-weight:400; }
.chart-wrap { position:relative; height:320px; }
  @media (max-width: 575px) { .chart-wrap { height: 240px; } }
.text-success { color:#4ade80 !important; }
.text-danger  { color:#f87171 !important; }
.text-muted-soft { color:rgba(255,255,255,.3); }
.form-control, .form-select { background:#1e293b; border-color:rgba(255,255,255,.12); color:#f1f5f9; }
.form-control:focus, .form-select:focus { background:#1e293b; border-color:#3b82f6; color:#f1f5f9; box-shadow:none; }
.btn-calc { background:#2563eb; border:none; color:#fff; font-weight:700; padding:.55rem 1.5rem; border-radius:8px; }
.btn-calc:hover { background:#1d4ed8; color:#fff; }
.divider { border-color:rgba(255,255,255,.07); margin:1.5rem 0; }
</style>
</head>
<body>
<?php $universe = 'sp500'; include __DIR__ . '/inc_navbar.php'; ?>

<div class="container px-4 py-4">

  <!-- Header -->
  <div class="mb-4">
    <div class="page-title"><i class="bi bi-bar-chart-line me-2" style="color:#10b981;"></i>Strategie-Vergleich</div>
    <p style="color:rgba(255,255,255,.45);font-size:.88rem;margin-top:.35rem;">
      S&amp;P 500, DAX, HDAX und ETF — alle Kennzahlen auf einen Blick. Alle Werte in <strong style="color:#f1f5f9;">EUR</strong>.
    </p>
  </div>

  <!-- Eingabe-Formular -->
  <div class="form-section mb-4">
    <form method="GET" action="compare.php" class="row g-3 align-items-end">
      <div class="col-12 col-sm-4">
        <label class="form-label" style="font-size:.8rem;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.05em;">Startkapital (EUR)</label>
        <div class="input-group">
          <span class="input-group-text" style="background:#1e293b;border-color:rgba(255,255,255,.12);color:#6ee7b7;font-weight:700;">€</span>
          <input type="text" name="capital" id="capitalInput" class="form-control"
                 value="<?= number_format($startCapital, 0, ',', '.') ?>"
                 autocomplete="off" inputmode="numeric">
        </div>
      </div>
      <div class="col-12 col-sm-4">
        <label class="form-label" style="font-size:.8rem;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.05em;">Startdatum</label>
        <input type="date" name="start_date" class="form-control"
               value="<?= htmlspecialchars($startDate) ?>"
               min="2010-01-04" max="<?= date('Y-m-d', strtotime('-4 weeks')) ?>">
      </div>
      <div class="col-12 col-sm-4">
        <button type="submit" class="btn btn-calc w-100">
          <i class="bi bi-calculator me-2"></i>Berechnen
        </button>
      </div>
    </form>
  </div>

<?php if (!empty($results)): ?>

  <!-- KPI-Übersicht -->
  <div class="row g-3 mb-4">
    <?php
    $univConfig = [
        'sp500' => ['label'=>'S&amp;P 500',    'badge'=>'badge-sp500', 'flag'=>'us',   'color'=>'#93c5fd'],
        'dax'   => ['label'=>'DAX',             'badge'=>'badge-dax',   'flag'=>'de',   'color'=>'#fca5a5'],
        'hdax'  => ['label'=>'HDAX',            'badge'=>'badge-hdax',  'flag'=>'de',   'color'=>'#fdba74'],
        'etf'   => ['label'=>'ETF',             'badge'=>'badge-etf',   'flag'=>'globe','color'=>'#6ee7b7'],
    ];
    foreach ($results as $univ => $r):
        $cfg = $univConfig[$univ];
        $ret  = $r['totalReturn'];
        $cagr = $r['cagr'];
        $isSp = ($univ === 'sp500');
        $retUsd  = $r['totalReturnUsd'];
        $cagrUsd = $r['cagrUsd'];
    ?>
    <div class="col-12 col-sm-6 col-md-3">
      <div class="card-dark p-3 h-100" style="min-width:0;">
        <div class="mb-3">
          <span class="universe-badge <?= $cfg['badge'] ?>">
            <?php if ($univ === 'etf'): ?>
              <i class="bi bi-globe" style="font-size:.8rem;"></i>
            <?php else: ?>
              <img src="https://flagcdn.com/16x12/<?= $cfg['flag'] ?>.png" width="14" height="10">
            <?php endif; ?>
            <?= $cfg['label'] ?>
          </span>
          <div style="font-size:.72rem;color:rgba(255,255,255,.3);margin-top:.35rem;line-height:1.3;">
            <?= date('d.m.Y', strtotime($univ === 'etf' ? $r['firstDate'] : ($weeklyStart ?? $r['firstDate']))) ?> – <?= date('d.m.Y', strtotime($r['lastDate'])) ?>
          </div>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <div class="kpi-box">
              <div class="kpi-val <?= colorClass($ret) ?>"
                <?php if ($isSp): ?>
                  data-eur="<?= ($ret>=0?'+':'') . fmt($ret) ?>"
                  data-usd="<?= ($retUsd>=0?'+':'') . fmt($retUsd) ?>"
                  data-clseur="<?= colorClass($ret) ?>" data-clsusd="<?= colorClass($retUsd) ?>"
                <?php endif; ?>
              ><?= ($ret>=0?'+':'') . fmt($ret) ?></div>
              <div class="kpi-lbl">Rendite gesamt</div>
            </div>
          </div>
          <div class="col-6">
            <div class="kpi-box">
              <div class="kpi-val <?= colorClass($cagr) ?>"
                <?php if ($isSp): ?>
                  data-eur="<?= ($cagr>=0?'+':'') . fmt($cagr) ?>"
                  data-usd="<?= ($cagrUsd>=0?'+':'') . fmt($cagrUsd) ?>"
                  data-clseur="<?= colorClass($cagr) ?>" data-clsusd="<?= colorClass($cagrUsd) ?>"
                <?php endif; ?>
              ><?= ($cagr>=0?'+':'') . fmt($cagr) ?></div>
              <div class="kpi-lbl">CAGR p.a.</div>
            </div>
          </div>
          <div class="col-6">
            <div class="kpi-box">
              <div class="kpi-val" style="color:#f87171;">-<?= fmt($r['maxDD']) ?></div>
              <div class="kpi-lbl">Max. Drawdown</div>
            </div>
          </div>
          <div class="col-6">
            <div class="kpi-box">
              <div class="kpi-val" style="color:#f1f5f9;font-size:.95rem;"
                <?php if ($isSp): ?>
                  data-eur="<?= fmtEur($r['finalValueEur']) ?>"
                  data-usd="<?= number_format($r['finalValueUsd'], 0, ',', '.') ?> $"
                <?php endif; ?>
              ><?= fmtEur($r['finalValueEur']) ?></div>
              <div class="kpi-lbl" <?php if ($isSp): ?>data-eur="Endkapital (EUR)" data-usd="Endkapital (USD)"<?php endif; ?>>Endkapital<?= $isSp ? ' (EUR)' : '' ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Detailtabelle -->
  <div class="card-dark p-0 mb-4 overflow-hidden">
    <div class="px-4 py-3" style="border-bottom:1px solid rgba(255,255,255,.07);">
      <span style="font-weight:700;font-size:.95rem;">Detaillierter Vergleich</span>
    </div>
    <div class="table-responsive">
    <table class="compare-table">
      <thead>
        <tr>
          <th style="width:30%;">Kennzahl</th>
          <?php foreach ($results as $univ => $r): $cfg = $univConfig[$univ]; ?>
          <th class="text-center">
            <span class="universe-badge <?= $cfg['badge'] ?>">
              <?php if ($univ === 'etf'): ?>
                <i class="bi bi-globe" style="font-size:.75rem;"></i>
              <?php else: ?>
                <img src="https://flagcdn.com/16x12/<?= $cfg['flag'] ?>.png" width="14" height="10">
              <?php endif; ?>
              <?= $cfg['label'] ?>
            </span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        $rows = [
            ['label'=>'Startkapital',   'key'=>'startCapital',  'keyUsd'=>'startCapUsd',
             'fmt'=>fn($v,$r)=>fmtEur($v), 'fmtUsd'=>fn($v,$r)=>number_format($v,0,',','.').' $'],
            ['label'=>'Endkapital',     'key'=>'finalValueEur', 'keyUsd'=>'finalValueUsd',
             'fmt'=>fn($v,$r)=>fmtEur($v), 'fmtUsd'=>fn($v,$r)=>number_format($v,0,',','.').' $', 'highlight'=>true],
            ['label'=>'Gesamt-Rendite', 'key'=>'totalReturn',   'keyUsd'=>'totalReturnUsd',
             'fmt'=>fn($v,$r)=>sprintf('<span class="%s">%s%s</span>', colorClass($v), $v>=0?'+':'', fmt($v)),
             'fmtUsd'=>fn($v,$r)=>sprintf('<span class="%s">%s%s</span>', colorClass($v), $v>=0?'+':'', fmt($v)),
             'html'=>true, 'highlight'=>true],
            ['label'=>'CAGR p.a.',      'key'=>'cagr',          'keyUsd'=>'cagrUsd',
             'fmt'=>fn($v,$r)=>sprintf('<span class="%s">%s%s</span>', colorClass($v), $v>=0?'+':'', fmt($v)),
             'fmtUsd'=>fn($v,$r)=>sprintf('<span class="%s">%s%s</span>', colorClass($v), $v>=0?'+':'', fmt($v)),
             'html'=>true],
            ['label'=>'Max. Drawdown',  'key'=>'maxDD',
             'fmt'=>fn($v,$r)=>'<span class="text-danger">−'.fmt($v).'</span>', 'html'=>true],
            ['label'=>'Benchmark-Rendite', 'key'=>'benchReturn', 'keyUsd'=>'benchReturnUsd',
             'fmt'=>fn($v,$r)=>$v!==null ? sprintf('<span class="%s">%s%s</span><br><span class="bench-chip">%s</span>', colorClass($v), $v>=0?'+':'', fmt($v), $r['benchTicker']) : '—',
             'fmtUsd'=>fn($v,$r)=>$v!==null ? sprintf('<span class="%s">%s%s</span><br><span class="bench-chip">%s (USD)</span>', colorClass($v), $v>=0?'+':'', fmt($v), $r['benchTicker']) : '—',
             'html'=>true],
            ['label'=>'Outperformance ggü. Benchmark', 'key'=>'outperf', 'keyUsd'=>'outperfUsd',
             'fmt'=>fn($v,$r)=>$v!==null ? sprintf('<span class="%s font-weight-bold">%s%s</span>', colorClass($v), $v>=0?'+':'', fmt($v)) : '—',
             'fmtUsd'=>fn($v,$r)=>$v!==null ? sprintf('<span class="%s font-weight-bold">%s%s</span>', colorClass($v), $v>=0?'+':'', fmt($v)) : '—',
             'html'=>true, 'highlight'=>true],
        ];
        foreach ($rows as $i => $row):
            $hl = !empty($row['highlight']) ? 'metric-row-highlight' : '';
        ?>
        <tr class="<?= $hl ?>">
          <td><?= $row['label'] ?></td>
          <?php foreach ($results as $univ => $r):
              $isSp = ($univ === 'sp500');
              $val = $r[$row['key']] ?? null;
              $rendered = $val !== null ? $row['fmt']($val, $r) : '—';
              $valUsd = isset($row['keyUsd']) ? ($r[$row['keyUsd']] ?? null) : null;
              $renderedUsd = ($isSp && $valUsd !== null && isset($row['fmtUsd'])) ? $row['fmtUsd']($valUsd, $r) : $rendered;
          ?>
          <td class="text-center"
            <?php if ($isSp && isset($row['keyUsd'])): ?>
              data-eur="<?= htmlspecialchars(strip_tags($rendered)) ?>"
              data-usd="<?= htmlspecialchars(strip_tags($renderedUsd)) ?>"
              data-eur-html="<?= htmlspecialchars($rendered) ?>"
              data-usd-html="<?= htmlspecialchars($renderedUsd) ?>"
            <?php endif; ?>
          >
            <?php if (!empty($row['html'])): ?>
              <?= $rendered ?>
            <?php else: ?>
              <?= htmlspecialchars($rendered) ?>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div><!-- /table-responsive -->
  </div>

  <!-- Chart -->
  <?php if (!empty($allDates)): ?>
  <div class="card-dark p-4 mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <span style="font-weight:700;font-size:.95rem;"><i class="bi bi-graph-up me-2" style="color:#10b981;"></i>Portfolio-Entwicklung (in EUR, Startkapital <?= number_format($startCapital, 0, ',', '.') ?> €)</span>
      <span style="font-size:.75rem;color:rgba(255,255,255,.3);">alle Werte in EUR</span>
    </div>
    <div class="chart-wrap">
      <canvas id="compareChart"></canvas>
    </div>
  </div>
  <?php endif; ?>

  <!-- Rebalancing-Annahmen -->
  <div class="rebal-box">
    <div style="font-size:.88rem;font-weight:700;color:#f1f5f9;">
      <i class="bi bi-sliders me-2" style="color:#10b981;"></i>Rebalancing-Annahmen im Vergleich
    </div>
    <div style="font-size:.8rem;color:rgba(255,255,255,.45);margin-top:.25rem;">
      Die vier Strategien unterscheiden sich in Frequenz, Anzahl der Positionen und Auswahlregeln.
    </div>
    <div class="rebal-grid">
      <?php
      $rebalItems = [
        ['color'=>'#93c5fd','icon'=>'<img src="https://flagcdn.com/16x12/us.png" width="14" height="10">','title'=>'S&amp;P 500','rows'=>[
          ['📅','Wöchentlich','Freitag'],
          ['📊','Top 5 Aktien','max. 1 pro Sektor'],
          ['🔁','Halten-Schwelle','Top 125'],
          ['📈','Benchmark','SPY (EUR-adj.)'],
        ],'highlight'=>false],
        ['color'=>'#fca5a5','icon'=>'<img src="https://flagcdn.com/16x12/de.png" width="14" height="10">','title'=>'DAX','rows'=>[
          ['📅','Wöchentlich','Freitag'],
          ['📊','Top 5 Aktien','max. 1 pro Sektor'],
          ['🔁','Halten-Schwelle','Top 10'],
          ['📈','Benchmark','^GDAXI'],
        ],'highlight'=>false],
        ['color'=>'#fdba74','icon'=>'<img src="https://flagcdn.com/16x12/de.png" width="14" height="10">','title'=>'HDAX','rows'=>[
          ['📅','Wöchentlich','Freitag'],
          ['📊','Top 5 Aktien','max. 1 pro Sektor'],
          ['🔁','Halten-Schwelle','Top 25 (aus ~90)'],
          ['📈','Benchmark','^HDAX'],
        ],'highlight'=>false],
        ['color'=>'#6ee7b7','icon'=>'<i class="bi bi-globe"></i>','title'=>'ETF','rows'=>[
          ['📅','Monatlich','Monatsultimo ★'],
          ['📊','Top 3 Asset-Klassen','gleichgewichtet'],
          ['🔁','Full-Rebalancing','kein Sektorfilter'],
          ['📈','Benchmark','ACWI (EUR-adj.)'],
        ],'highlight'=>true],
      ];
      foreach ($rebalItems as $item): ?>
      <div class="rebal-item" <?= $item['highlight'] ? 'style="border:1px solid rgba(16,185,129,.25);"' : '' ?>>
        <div class="rebal-title" style="color:<?= $item['color'] ?>;margin-bottom:.6rem;">
          <?= $item['icon'] ?> <?= $item['title'] ?>
        </div>
        <table style="width:100%;border-collapse:collapse;">
          <?php foreach ($item['rows'] as [$icon,$key,$val]): ?>
          <tr>
            <td style="padding:.18rem .4rem .18rem 0;font-size:.78rem;color:rgba(255,255,255,.4);white-space:nowrap;"><?= $key ?></td>
            <td style="padding:.18rem 0;font-size:.78rem;color:#e2e8f0;font-weight:600;"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Hinweise -->
  <div style="font-size:.75rem;color:rgba(255,255,255,.3);line-height:1.6;">
    <i class="bi bi-info-circle me-1"></i>
    S&amp;P 500-Werte in EUR umgerechnet (EUR/USD am Start- und Enddatum).
    ETF: Simulation in EUR-Basiseinheiten. ACWI-Benchmark EUR-adjustiert.
    Keine Transaktionskosten. Kein Anlageberater.
  </div>

<?php else: ?>
  <div class="card-dark p-4 text-center" style="color:rgba(255,255,255,.4);">
    <i class="bi bi-exclamation-circle me-2"></i>Keine Daten für den gewählten Zeitraum.
  </div>
<?php endif; ?>

</div><!-- /container -->

<script>
<?php if (!empty($allDates) && !empty($results)): ?>
(function() {
  const labels = <?= json_encode($allDates) ?>;
  const univColors = { sp500: '#93c5fd', dax: '#fca5a5', hdax: '#fdba74', etf: '#6ee7b7' };
  const univLabels = { sp500: 'S&P 500', dax: 'DAX', hdax: 'HDAX', etf: 'ETF' };

  const datasets = [
    <?php foreach ($results as $univ => $r): if (empty($r['chartValuesNorm'])) continue; ?>
    {
      label: univLabels['<?= $univ ?>'],
      data: <?= json_encode($r['chartValuesNorm']) ?>,
      borderColor: univColors['<?= $univ ?>'],
      backgroundColor: 'transparent',
      borderWidth: 2,
      pointRadius: 0,
      tension: 0.1,
      spanGaps: true,
    },
    <?php endforeach; ?>
  ];

  new Chart(document.getElementById('compareChart'), {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: 'rgba(255,255,255,.6)', font: { size: 12 } } },
        tooltip: {
          backgroundColor: '#1e293b',
          borderColor: 'rgba(255,255,255,.1)',
          borderWidth: 1,
          titleColor: '#f1f5f9',
          bodyColor: 'rgba(255,255,255,.7)',
          callbacks: {
            title: items => {
              const iso = items[0]?.label ?? '';
              const [y,m,d] = iso.split('-');
              return d && m && y ? `${d}.${m}.${y}` : iso;
            },
            label: ctx => ' ' + ctx.dataset.label + ': ' + ctx.parsed.y?.toLocaleString('de-DE', {minimumFractionDigits:0, maximumFractionDigits:0}) + ' €',
          }
        }
      },
      scales: {
        x: {
          ticks: { color: 'rgba(255,255,255,.3)', maxTicksLimit: 8, font: { size: 11 } },
          grid:  { color: 'rgba(255,255,255,.04)' },
        },
        y: {
          ticks: {
            color: 'rgba(255,255,255,.3)',
            font: { size: 11 },
            callback: v => v.toLocaleString('de-DE', {minimumFractionDigits:0, maximumFractionDigits:0}) + ' €',
          },
          grid: { color: 'rgba(255,255,255,.05)' },
        }
      }
    }
  });
})();
<?php endif; ?>

// Auf compare.php führt Universe-Wechsel zur Indexseite des gewählten Universums
function switchUniverse(universe) {
  localStorage.setItem('universe', universe);
  window.location.href = 'index.php?universe=' + universe;
}

// Währungsumschaltung: S&P 500 Werte zwischen EUR und USD umschalten
function applyCurrencyToggle(cur) {
  localStorage.setItem('currency', cur);
  document.getElementById('btn-eur')?.classList.toggle('active', cur === 'EUR');
  document.getElementById('btn-usd')?.classList.toggle('active', cur === 'USD');

  const useUsd = (cur === 'USD');

  // KPI-Boxes: Elemente mit data-eur / data-usd aktualisieren
  document.querySelectorAll('[data-eur][data-usd]').forEach(el => {
    if (el.dataset.clseur !== undefined) {
      // Rendite / CAGR box (hat color-class data)
      el.textContent = useUsd ? el.dataset.usd : el.dataset.eur;
      el.className = el.className.replace(/kpi-(?:green|red|blue|white)|text-(?:success|danger)/g, '');
      const cls = useUsd ? el.dataset.clsusd : el.dataset.clseur;
      if (cls) el.classList.add(cls);
    } else {
      // Endkapital box
      el.textContent = useUsd ? el.dataset.usd : el.dataset.eur;
    }
  });

  // KPI-Label "Endkapital (EUR/USD)"
  document.querySelectorAll('[data-eur="Endkapital (EUR)"]').forEach(el => {
    el.textContent = useUsd ? el.dataset.usd : el.dataset.eur;
  });

  // Tabellenzellen mit data-eur-html / data-usd-html
  document.querySelectorAll('td[data-eur-html]').forEach(td => {
    td.innerHTML = useUsd ? td.dataset.usdHtml : td.dataset.eurHtml;
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const cur = localStorage.getItem('currency') || 'EUR';
  applyCurrencyToggle(cur);
});

<?php if (!empty($results)): ?>
// Vergleichs-Annahmen in localStorage speichern → Annahmen-Seiten übernehmen diese als Standard
(function() {
  const cap  = <?= json_encode((int)$startCapital) ?>;
  const date = <?= json_encode($startDate) ?>;
  ['sp500','dax','hdax','etf'].forEach(u => {
    localStorage.setItem('sim_capital_'    + u, cap);
    localStorage.setItem('sim_start_date_' + u, date);
  });
})();
<?php endif; ?>

// Kapital-Input formatieren
const capInput = document.getElementById('capitalInput');
if (capInput) {
  capInput.addEventListener('input', function() {
    const raw = parseInt(this.value.replace(/\./g, '').replace(/[^\d]/g, ''), 10) || 0;
    this.value = raw.toLocaleString('de-DE', { maximumFractionDigits: 0 });
  });
  capInput.addEventListener('blur', function() {
    let raw = parseInt(this.value.replace(/\./g, '').replace(/[^\d]/g, ''), 10) || 10000;
    raw = Math.round(raw / 1000) * 1000;
    this.value = raw.toLocaleString('de-DE', { maximumFractionDigits: 0 });
  });
  // Beim Submit: Punkt entfernen damit PHP den Wert als Zahl liest
  capInput.closest('form')?.addEventListener('submit', function() {
    capInput.value = capInput.value.replace(/\./g, '');
  });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
