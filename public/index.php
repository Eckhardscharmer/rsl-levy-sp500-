<?php
require_once __DIR__ . '/../src/RSLEngine.php';
$rsl = new RSLEngine();
$db  = getDB();

// ── Universe ───────────────────────────────────────────────────────────────
$universe = $_GET['universe'] ?? 'sp500';
if (!in_array($universe, ['sp500', 'dax', 'hdax', 'etf'])) $universe = 'sp500';
$isDax        = ($universe === 'dax');
$isHdax       = ($universe === 'hdax');
$isEtf        = ($universe === 'etf');
$isEurUniverse = ($isDax || $isHdax || $isEtf);

$latestDate = $rsl->getLatestRankingDate($universe);
$hasData    = !empty($latestDate);

// ── M&A-Filter (identisch zu simulation.php) ──────────────────────────────
$maFlagged = [];
foreach ($db->query('SELECT ticker FROM m_and_a_flags WHERE is_active = 1 AND (expires_date IS NULL OR expires_date > CURDATE())')
             ->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $maFlagged[$t] = true;
}

// ── Simulation ab Nutzer-Startdatum (identische Logik wie simulation.php) ──
$minDate      = $isEtf ? '2000-01-31' : '2010-01-04';
$_stmt = $db->prepare("SELECT MAX(ranking_date) FROM rsl_rankings WHERE universe=?");
$_stmt->execute([$universe]);
$maxDate = $_stmt->fetchColumn() ?: date('Y-m-d');
$simStartDate = $_GET['start_date'] ?? ($isEtf ? '2010-01-31' : '2024-01-01');
if ($simStartDate < $minDate) $simStartDate = $minDate;
if ($simStartDate > $maxDate) $simStartDate = $maxDate;

$simStmt = $db->prepare(
    'SELECT r.ranking_date, r.ticker, r.sector, r.current_price, r.rsl, r.rank_overall,
            r.is_selected, COALESCE(s.name, r.ticker) AS company
     FROM rsl_rankings r
     LEFT JOIN stocks s ON s.ticker = r.ticker
     WHERE r.ranking_date >= ? AND r.universe = ?
     ORDER BY r.ranking_date ASC, r.rank_overall ASC'
);
$simStmt->execute([$simStartDate, $universe]);
$simByDate = [];
foreach ($simStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $simByDate[$row['ranking_date']][] = $row;
}
$simSundays = array_keys($simByDate);

// ETF: immer EUR 50.000 als Startkapital; DAX: Rundung auf 10.000er-Schritte (wie simulation.php)
$simStartCapital = $isEtf ? 50000.0 : max(1000.0, (float)($_GET['capital'] ?? 50000));
if ($isDax || $isHdax) $simStartCapital = max(10000.0, round($simStartCapital / 10000) * 10000);

// S&P 500: Startkapital in EUR → USD umrechnen
// Referenz: erster Ranking-Tag (nicht Input-Datum, das ggf. kein Handelstag ist)
// → identisch mit backtest.php (eurAtPortStart) und simulation.php (startEurUsdSim)
$startEurUsdIdx = 1.10;
if (!$isEurUniverse && !empty($simSundays)) {
    $stEur = $db->prepare("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' AND price_date <= ? ORDER BY price_date DESC LIMIT 1");
    $stEur->execute([$simSundays[0]]);
    $startEurUsdIdx = (float)($stEur->fetchColumn() ?: 1.10);
}
$simCash = $isEurUniverse ? $simStartCapital : $simStartCapital * $startEurUsdIdx;
$simHoldings = [];   // ticker → [shares, buy_price, sector, rsl, company]
$weeklyPortfolio = [];
$latestPortfolioDate = null;

// EUR/USD-Kurse pro Datum (für GuV-je-Aktie-Berechnung bei S&P 500)
$eurRateByDateIdx = [];
if (!$isEurUniverse && !empty($simSundays)) {
    $eurRaw2 = $db->query("SELECT price_date, adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date")->fetchAll(PDO::FETCH_KEY_PAIR);
    $eurDs2 = array_keys($eurRaw2); $nEur3 = count($eurDs2); $eIdx3 = 0;
    foreach ($simSundays as $d) {
        while ($eIdx3 < $nEur3 - 1 && $eurDs2[$eIdx3 + 1] <= $d) $eIdx3++;
        $eurRateByDateIdx[$d] = ($nEur3 > 0 && $eurDs2[$eIdx3] <= $d) ? (float)$eurRaw2[$eurDs2[$eIdx3]] : $startEurUsdIdx;
    }
}
$stockGuv = [];  // ticker → [company, invested_eur, proceeds_eur, invested_usd, proceeds_usd]
$latestRawSnap = [];
$latestRawTotal = 0.0;
$prevTickers = [];

$etfNameMap = [
    '^GSPC'  => 'USA Large Caps (S&P 500)',  '^NDX'   => 'USA Wachstum (Nasdaq-100)',
    '^STOXX' => 'Europa (STOXX 600)',         '^N225'  => 'Japan (Nikkei 225)',
    'EEM'    => 'Emerging Markets',           'GC=F'   => 'Gold',
    'AGG'    => 'Staatsanleihen',             'SHY'    => 'Cash / Geldmarkt',
];

foreach ($simSundays as $i => $sunday) {
    $weekRankings = $simByDate[$sunday];
    $rankByTicker = array_column($weekRankings, null, 'ticker');
    $isLast = ($i === count($simSundays) - 1);

    if ($isEtf) {
        // ETF: monatliches Full-Rebalancing, Top 3, gleiche Gewichtung
        $targetTickers = [];
        foreach ($weekRankings as $r) {
            if ($r['is_selected']) $targetTickers[$r['ticker']] = $r;
        }
        $totalValue = $simCash;
        foreach ($simHoldings as $ticker => $h) {
            $totalValue += $h['shares'] * (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
        }
        $prevHoldings = array_keys($simHoldings);
        // GuV-Tracking ETF: Erlöse für jede gehaltene Position buchen (Full-Rebalancing)
        foreach ($simHoldings as $t3 => $h3) {
            $mv3 = $h3['shares'] * (float)($rankByTicker[$t3]['current_price'] ?? $h3['buy_price']);
            if (isset($stockGuv[$t3])) {
                $stockGuv[$t3]['proceeds_eur'] += $mv3;
                $stockGuv[$t3]['proceeds_usd'] += $mv3;
            }
        }
        $simCash = $totalValue;
        $simHoldings = [];
        $n = count($targetTickers);
        if ($n > 0) {
            $perSlot = $simCash / $n;
            foreach ($targetTickers as $ticker => $r) {
                $price = (float)$r['current_price'];
                if ($price <= 0) continue;
                $simCash -= $perSlot;
                $companyName = $etfNameMap[$ticker] ?? $ticker;
                $simHoldings[$ticker] = [
                    'shares'    => $perSlot / $price,
                    'buy_price' => $price,
                    'sector'    => $r['sector'] ?? '',
                    'rsl'       => (float)$r['rsl'],
                    'company'   => $companyName,
                ];
                // GuV-Tracking ETF: vorherige Erlöse bereits in proceeds_eur gebucht;
                // neuen Investment-Slot erfassen (ETF immer EUR-nativ)
                if (!isset($stockGuv[$ticker])) {
                    $stockGuv[$ticker] = ['company' => $companyName, 'invested_eur' => 0.0, 'proceeds_eur' => 0.0, 'invested_usd' => 0.0, 'proceeds_usd' => 0.0];
                }
                $stockGuv[$ticker]['invested_eur'] += $perSlot;
                $stockGuv[$ticker]['invested_usd'] += $perSlot;
            }
        }
    } else {
        $eurRate = $isEurUniverse ? 1.0 : ($eurRateByDateIdx[$sunday] ?? $startEurUsdIdx);
        $saleProceeds = [];
        $holdRank = $isHdax ? 25 : ($isDax ? ($sunday >= '2021-09-20' ? 10 : 7) : 125);
        foreach (array_keys($simHoldings) as $ticker) {
            $rank = isset($rankByTicker[$ticker])
                ? (int)$rankByTicker[$ticker]['rank_overall'] : PHP_INT_MAX;
            if ($rank > $holdRank) {
                $price = (float)($rankByTicker[$ticker]['current_price'] ?? $simHoldings[$ticker]['buy_price']);
                $net   = $simHoldings[$ticker]['shares'] * $price;
                $simCash += $net;
                $saleProceeds[] = $net;
                // GuV-Tracking: Erlös buchen (EUR + native USD)
                if (isset($stockGuv[$ticker])) {
                    $stockGuv[$ticker]['proceeds_eur'] += $net / $eurRate;
                    $stockGuv[$ticker]['proceeds_usd'] += $net;
                }
                unset($simHoldings[$ticker]);
            }
        }
        $vacancies   = 5 - count($simHoldings);
        $heldSectors = array_column(array_values($simHoldings), 'sector');
        $cashPerSlot = $vacancies > 0 ? $simCash / $vacancies : 0;
        foreach ($weekRankings as $stock) {
            if ($vacancies <= 0) break;
            if (isset($simHoldings[$stock['ticker']])) continue;
            if (isset($maFlagged[$stock['ticker']])) continue;
            $sector = $stock['sector'] ?? 'Unknown';
            if (in_array($sector, $heldSectors)) continue;
            $price = (float)$stock['current_price'];
            if ($price <= 0) continue;
            $budget = !empty($saleProceeds) ? array_shift($saleProceeds) : $cashPerSlot;
            if ($budget < 1) continue;
            $simCash -= $budget;
            $simHoldings[$stock['ticker']] = [
                'shares'    => $budget / $price,
                'buy_price' => $price,
                'sector'    => $sector,
                'rsl'       => (float)$stock['rsl'],
                'company'   => $stock['company'],
            ];
            $heldSectors[] = $sector;
            $vacancies--;
            // GuV-Tracking: Investment erfassen (EUR + native USD)
            $t2 = $stock['ticker'];
            if (!isset($stockGuv[$t2])) {
                $stockGuv[$t2] = ['company' => $stock['company'], 'invested_eur' => 0.0, 'proceeds_eur' => 0.0, 'invested_usd' => 0.0, 'proceeds_usd' => 0.0];
            }
            $stockGuv[$t2]['invested_eur'] += $budget / $eurRate;
            $stockGuv[$t2]['invested_usd'] += $budget;
        }
    }

    // Wochenwert für Chart-Array (identisch mit backtest.php-Logik)
    $wkInvested = 0;
    foreach ($simHoldings as $t => $h) {
        $wkInvested += $h['shares'] * (float)($rankByTicker[$t]['current_price'] ?? $h['buy_price']);
    }
    $weeklyPortfolio[$sunday] = $simCash + $wkInvested;

    if ($isLast) {
        $latestPortfolioDate = $sunday;
        $snap = []; $snapTotal = $simCash;
        foreach ($simHoldings as $ticker => $h) {
            $price = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            $mv = $h['shares'] * $price;
            $snapTotal += $mv;
            $snap[$ticker] = [
                'ticker'  => $ticker,
                'company' => $rankByTicker[$ticker]['company'] ?? $h['company'],
                'sector'  => $h['sector'],
                'rsl'     => (float)($rankByTicker[$ticker]['rsl'] ?? $h['rsl']),
                'raw_mv'  => $mv,
            ];
        }
        $latestRawSnap  = $snap;
        $latestRawTotal = $snapTotal;
    } elseif ($i === count($simSundays) - 2) {
        $prevTickers = array_keys($simHoldings);
    }
}

// ── Rendite aus eigener Simulation ──────────────────────────────────────────
// S&P 500: latestRawTotal ist in USD, Startkapital war simStartCapital * startEurUsdIdx USD
$simStartUsd = $isEurUniverse ? $simStartCapital : $simStartCapital * $startEurUsdIdx;
$simReturn   = $latestRawTotal > 0 ? ($latestRawTotal - $simStartUsd) / $simStartUsd : 0;

// ETF: WKN-Mapping für Dashboard-Tabelle (wie in simulation.php)
$etfWknMap = [
    '^GSPC'  => 'A0YEDG', '^NDX'   => '801498',
    '^STOXX' => '263530', '^N225'  => 'DBX0NJ',
    'EEM'    => 'A12GVR', 'GC=F'  => 'A0S9GB',
    'AGG'    => 'A0RGEM', 'SHY'   => 'DBX0AN',
];
$etfNameMapIdx = [
    '^GSPC'  => 'USA Large Caps (S&P 500)',  '^NDX'   => 'USA Wachstum (Nasdaq-100)',
    '^STOXX' => 'Europa (STOXX 600)',         '^N225'  => 'Japan (Nikkei 225)',
    'EEM'    => 'Emerging Markets',           'GC=F'   => 'Gold',
    'AGG'    => 'Staatsanleihen',             'SHY'    => 'Cash / Geldmarkt',
];

// Gewichte aus Simulation (skalenunabhängig) — USD-Beträge werden clientseitig skaliert
$latestPortfolio = [];
foreach ($latestRawSnap as $ticker => $h) {
    $weight = $latestRawTotal > 0 ? $h['raw_mv'] / $latestRawTotal * 100 : 0;
    $latestPortfolio[] = [
        'ticker'  => $ticker,
        'wkn'     => $isEtf ? ($etfWknMap[$ticker] ?? $ticker) : $ticker,
        'company' => $isEtf ? ($etfNameMapIdx[$ticker] ?? $h['company']) : $h['company'],
        'sector'  => $h['sector'],
        'rsl'     => $h['rsl'],
        'weight'  => $weight,
        'is_new'  => !in_array($ticker, $prevTickers),
    ];
}
usort($latestPortfolio, fn($a, $b) => $b['weight'] <=> $a['weight']);

// EUR/USD aktueller Kurs
$currentEurUsd = (float)($db->query("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date DESC LIMIT 1")->fetchColumn() ?: 1.10);

// GuV-je-Aktie: noch gehaltene Positionen mit aktuellem Marktwert abschließen
$eurRateForCurrent = $isEurUniverse ? 1.0 : $currentEurUsd;
foreach ($latestRawSnap as $ticker => $h) {
    if (isset($stockGuv[$ticker])) {
        $stockGuv[$ticker]['proceeds_eur'] += $h['raw_mv'] / $eurRateForCurrent;
        $stockGuv[$ticker]['proceeds_usd'] += $h['raw_mv'];  // raw_mv ist USD für S&P 500, EUR für DAX/ETF
    }
}
// Sortiert nach GuV absteigend
$stockGuvData = [];
foreach ($stockGuv as $ticker => $g) {
    $stockGuvData[] = [
        'ticker'   => $ticker,
        'company'  => $g['company'],
        'guv_eur'  => round($g['proceeds_eur'] - $g['invested_eur']),
        'guv_usd'  => round(($g['proceeds_usd'] - $g['invested_usd'])),
    ];
}
usort($stockGuvData, fn($a, $b) => $b['guv'] <=> $a['guv']);
$stockGuvJson = json_encode($stockGuvData, JSON_UNESCAPED_UNICODE);

// ── Chart-Daten: Benchmark + EUR-Raten (identisch mit backtest.php) ──────────
$idxChartDates     = 'null';
$idxChartPortfolio = 'null';
$idxChartBenchmark = 'null';
$idxChartEurRates  = [];
$idxStartEurUsd    = $startEurUsdIdx;
$idxEndEurUsd      = $currentEurUsd;
if (!empty($weeklyPortfolio)) {
    $benchTicker = $isHdax ? '^HDAX' : ($isDax ? '^GDAXI' : ($isEtf ? 'ACWI' : 'SPY'));
    $spyStmt = $db->prepare('SELECT price_date, adj_close FROM prices WHERE ticker=? AND price_date>=? ORDER BY price_date ASC');
    $spyStmt->execute([$benchTicker, $simStartDate]);
    $spyByDate = $spyStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $spyDates  = array_keys($spyByDate);
    $spyStartP = null; $spyIdx2 = 0; $nSpy2 = count($spyDates);
    $weeklyBench = [];
    foreach (array_keys($weeklyPortfolio) as $d) {
        while ($spyIdx2 < $nSpy2 - 1 && $spyDates[$spyIdx2 + 1] <= $d) $spyIdx2++;
        if ($nSpy2 > 0 && $spyDates[$spyIdx2] <= $d) {
            $sc = $spyByDate[$spyDates[$spyIdx2]];
            if ($spyStartP === null) $spyStartP = $sc;
            $weeklyBench[$d] = round($simStartCapital * ($sc / $spyStartP));
        } else {
            $weeklyBench[$d] = null;
        }
    }
    if (!$isDax && !$isHdax) {
        $eurRaw = $db->query("SELECT price_date, adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date")->fetchAll(PDO::FETCH_KEY_PAIR);
        $eurDs  = array_keys($eurRaw); $nEur2 = count($eurDs); $eIdx2 = 0;
        $rates2 = [];
        foreach (array_keys($weeklyPortfolio) as $d) {
            while ($eIdx2 < $nEur2 - 1 && $eurDs[$eIdx2 + 1] <= $d) $eIdx2++;
            $rates2[] = ($nEur2 > 0 && $eurDs[$eIdx2] <= $d) ? (float)$eurRaw[$eurDs[$eIdx2]] : $currentEurUsd;
        }
        $idxChartEurRates = $rates2;
        $stEurEnd = $db->prepare("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' AND price_date<=? ORDER BY price_date DESC LIMIT 1");
        $stEurEnd->execute([end($simSundays)]);
        $idxEndEurUsd = (float)($stEurEnd->fetchColumn() ?: $currentEurUsd);
    }
    $idxChartDates     = json_encode(array_keys($weeklyPortfolio));
    $idxChartPortfolio = json_encode(array_map('round', array_values($weeklyPortfolio)));
    $idxChartBenchmark = json_encode(array_map(fn($d) => $weeklyBench[$d] ?? null, array_keys($weeklyPortfolio)));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relative Stärke nach Levy — <?= $isEtf ? 'ETF Multi-Asset' : ($isHdax ? 'HDAX' : ($isDax ? 'DAX' : 'S&P 500')) ?> Dashboard</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root {
    --rsl-green:  #4ade80;
    --rsl-red:    #f87171;
    --rsl-blue:   #3b82f6;
    --rsl-card:   #111827;
    --rsl-border: rgba(255,255,255,.08);
    --rsl-muted:  rgba(255,255,255,.4);
  }
  body { background: #0a0f1e; color: #e2e8f0; font-family: 'Inter', sans-serif; }
  .navbar { background: #0f172a !important; border-bottom: 1px solid #1e2d4a; box-shadow: 0 2px 12px rgba(0,0,0,.3); min-height: 56px; }
  .navbar .container-fluid { min-height: 56px; height: auto; }
  .navbar .navbar-brand { color: #fff !important; font-weight: 700; padding: 0; }
  .navbar .nav-link { color: rgba(255,255,255,.6) !important; padding: .375rem .65rem !important; font-size: .875rem; }
  .navbar .nav-link:hover { color: #fff !important; }
  .card { background: #111827; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; }
  .card-header { background: #0f172a; border-bottom: 1px solid rgba(255,255,255,.08); font-weight: 600; color: #f1f5f9; }
  .metric-card { text-align: center; padding: 1.5rem; }
  .metric-value { font-size: 2rem; font-weight: 700; color: #f1f5f9; }
  .metric-label { color: rgba(255,255,255,.4); font-size: .85rem; text-transform: uppercase; letter-spacing: .5px; }
  .table { --bs-table-bg: transparent; --bs-table-color: #e2e8f0; }
  .table th { color: rgba(255,255,255,.4); font-weight: 700; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; border-color: rgba(255,255,255,.06); }
  .table td { border-color: rgba(255,255,255,.06); vertical-align: middle; color: #e2e8f0; }
  .table tbody tr:hover { background: rgba(255,255,255,.03); }
  .rsl-badge { font-size: .9rem; font-weight: 700; padding: .3em .7em; border-radius: 6px; }
  .rsl-high  { background: rgba(74,222,128,.15); color: #4ade80; }
  .rsl-mid   { background: rgba(251,191,36,.15); color: #fbbf24; }
  .rsl-low   { background: rgba(248,113,113,.15); color: #f87171; }
  .selected-row td:first-child { border-left: 3px solid var(--rsl-green); }
  .sector-badge { font-size: .72rem; background: rgba(255,255,255,.08); color: rgba(255,255,255,.6); padding: .2em .6em; border-radius: 20px; }
  .positive { color: #4ade80; }
  .negative { color: #f87171; }
  .nav-link.active { color: #fff !important; font-weight: 600; }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }
  html { overflow-y: scroll; }
  .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
  .dot-green { background: #4ade80; }
  .dot-yellow { background: #fbbf24; }
  .dot-red { background: #f87171; }
  .setup-banner { background: rgba(29,78,216,.15); border: 1px solid rgba(29,78,216,.4); border-radius: 12px; padding: 2rem; text-align: center; }
  footer { color: rgba(255,255,255,.3); font-size: .8rem; padding: 2rem 0; border-top: 1px solid rgba(255,255,255,.07); margin-top: 3rem; }
  .ptable th { color:rgba(255,255,255,.4); font-weight:700; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; border-color:rgba(255,255,255,.06); }
  .ptable td { border-color:rgba(255,255,255,.06); vertical-align:middle; font-size:.82rem; color:#e2e8f0; }
  .ptable tfoot td { font-size:.82rem; border-color:rgba(255,255,255,.1); color:#f1f5f9; }
  .rsl-pill { background:rgba(255,255,255,.1); border-radius:6px; padding:.15em .5em; font-size:.78rem; font-weight:600; color:#e2e8f0; }
  .ticker-badge { font-weight:700; font-size:.82rem; letter-spacing:.02em; }
  .text-muted { color: rgba(255,255,255,.4) !important; }
  hr { border-color: rgba(255,255,255,.07); }
</style>
</head>
<body>

<!-- Navbar -->
<?php $activePage = 'index'; include __DIR__ . '/inc_navbar.php'; ?>

<div class="container-fluid px-4 py-4">

<?php if (!$hasData): ?>
<!-- Setup-Banner wenn noch keine Daten -->
<div class="setup-banner mb-4">
  <h3><i class="bi bi-database-add text-primary me-2"></i>System wird eingerichtet</h3>
  <p class="text-muted mb-3">Führe folgende Schritte in der Kommandozeile aus:</p>
  <div class="text-start d-inline-block">
    <code class="d-block mb-2">$ /Applications/XAMPP/xamppfiles/bin/php scripts/01_setup_database.php</code>
    <code class="d-block mb-2">$ /Applications/XAMPP/xamppfiles/bin/php scripts/02_download_prices.php</code>
    <code class="d-block mb-2">$ /Applications/XAMPP/xamppfiles/bin/php scripts/03_calculate_rsl.php</code>
    <code class="d-block mb-2">$ /Applications/XAMPP/xamppfiles/bin/php scripts/04_run_backtest.php</code>
  </div>
  <p class="text-muted mt-3 small">Der Download aller S&P 500-Aktien dauert ca. 30–60 Minuten.</p>
</div>
<?php else: ?>

<!-- Datum & Status -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <small class="text-muted">Stand: <?= $latestDate ? date('d.m.Y', strtotime($latestDate)) : '—' ?> (<?= $isEtf ? 'letzter Monatsultimo' : 'letzter Freitag' ?>)</small>
  </div>
  <div>
    <span class="status-dot dot-green"></span><small class="text-muted">Live-Daten aktiv</small>
  </div>
</div>

<div class="row g-4">
  <!-- Aktuelles Portfolio -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <i class="bi bi-briefcase-fill text-success me-2"></i>
          Aktuelles Portfolio per
          <?= $latestPortfolioDate ? date('d.m.Y', strtotime($latestPortfolioDate)) : '—' ?>
          <span class="text-muted fw-normal"> — Start der Strategie am <?= date('d.m.Y', strtotime($simStartDate)) ?></span>
        </span>
        <a href="simulation.php" class="btn btn-sm btn-outline-secondary">Annahmen</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($latestPortfolio)): ?>
          <p class="text-muted p-3 mb-0">Keine Portfolio-Daten vorhanden.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table ptable mb-0">
          <thead>
            <tr>
              <?php if ($isEtf): ?>
              <th class="ps-3">WKN</th>
              <th>ETF</th>
              <?php else: ?>
              <th class="ps-3">Unternehmen</th>
              <th>Sektor</th>
              <?php endif; ?>
              <th class="text-end">Gewicht</th>
              <th class="text-end" id="th-betrag">Betrag in <?= $isEurUniverse ? 'EUR' : 'USD' /* JS überschreibt bei EUR-Modus */ ?></th>
              <th class="text-end pe-3">RSL Score</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $sumW = 0;
          foreach ($latestPortfolio as $row):
            $sumW += $row['weight'];
          ?>
            <tr>
              <td class="ps-3" style="color:#e2e8f0;">
                <?php if ($isEtf): ?>
                  <span class="ticker-badge"><?= htmlspecialchars($row['wkn']) ?></span>
                <?php else: ?>
                  <?= htmlspecialchars($row['company']) ?>
                <?php endif; ?>
              </td>
              <td style="color:rgba(255,255,255,.4);">
                <?php if ($isEtf): ?>
                  <?= htmlspecialchars($row['company']) ?>
                <?php else: ?>
                  <span class="sector-badge"><?= htmlspecialchars($row['sector']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= number_format($row['weight'], 1, ',', '.') ?>%</td>
              <td class="text-end js-mv" data-weight="<?= round($row['weight'], 6) ?>">—</td>
              <td class="text-end pe-3">
                <span class="rsl-pill"><?= number_format($row['rsl'], 4, ',', '.') ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight:600; border-top:2px solid #dee2e6;">
              <td colspan="2" class="ps-3 text-muted">Summe</td>
              <td class="text-end"><?= number_format($sumW, 1, ',', '.') ?>%</td>
              <td class="text-end" id="js-mv-total">—</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Kuchendiagramm -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-pie-chart-fill text-success me-2"></i>Portfolio-Zusammensetzung
      </div>
      <div class="card-body d-flex flex-column align-items-center justify-content-center py-2">
        <?php if (!empty($latestPortfolio)): ?>
        <canvas id="portfolioPie" style="max-width:160px;max-height:160px;"></canvas>
        <div class="mt-2 text-center">
          <div style="font-size:.76rem;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;">Gesamtwert</div>
          <div id="js-total-display" style="font-size:1.5rem;font-weight:700;color:#f1f5f9;">
            — <span style="font-size:.9rem;font-weight:400;color:#6c757d;" id="js-currency-label"><?= $isEurUniverse ? 'EUR' : 'USD' ?></span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($idxChartDates !== 'null'): ?>
<div class="row g-4 mt-0">
  <div class="col-lg-7" style="align-self:flex-start;">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-graph-up me-2"></i>Portfolio-Entwicklung vs. <?= $isEtf ? 'MSCI ACWI (ACWI)' : ($isHdax ? 'HDAX (^HDAX)' : ($isDax ? 'DAX (^GDAXI)' : 'S&amp;P 500 (SPY)')) ?>
      </div>
      <div class="card-body" style="height:320px;">
        <canvas id="idxChart"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-5" id="quadrant-bottom-right">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-bar-chart-steps me-2"></i>GuV je Aktie
        <span class="text-muted fw-normal" style="font-size:.78rem;"> — kumuliert seit Strategie-Start</span>
      </div>
      <div class="card-body" style="overflow-y:auto;">
        <canvas id="guvPerStockChart"></canvas>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
</div>

<footer class="container-fluid px-4 text-center">
  Relative Stärke nach Levy — <?= $isEtf ? 'ETF Multi-Asset' : ($isHdax ? 'HDAX' : ($isDax ? 'DAX' : 'S&P 500')) ?> Momentum-System &nbsp;|&nbsp;
  Powered by Apache + MariaDB + PHP 8.2 &nbsp;|&nbsp;
  Daten: Yahoo Finance &nbsp;|&nbsp;
  <small>Kein Anlageberater — nur zu Informationszwecken</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const _isDax     = <?= $isDax ? 'true' : 'false' ?>;
const _isHdax    = <?= $isHdax ? 'true' : 'false' ?>;
const _isEtf     = <?= $isEtf ? 'true' : 'false' ?>;
const _isEurUni  = (_isDax || _isHdax || _isEtf);
const _currency  = _isEurUni ? 'EUR' : (localStorage.getItem('currency') || 'EUR');
const _defStart  = _isEtf ? '2010-01-31' : '2024-01-01';

(function () {
  // ── Simulation-Parameter aus localStorage mit URL-Parametern abgleichen ──
  const params = new URLSearchParams(window.location.search);
  const urlStart = params.get('start_date');
  const urlCap   = params.get('capital');
  const univ     = params.get('universe') || 'sp500';

  // Universumsabhängiger Key — verhindert Überschreiben durch andere Universen
  const _startKey = 'sim_start_date_' + univ;
  const targetStart = localStorage.getItem(_startKey) || _defStart;
  // ETF: Kapital immer 50.000; andere Universen aus localStorage
  const _capKey   = 'sim_capital_' + univ;
  const targetCap = _isEtf ? '50000' : localStorage.getItem(_capKey);

  const needRedirect = (targetStart !== urlStart) || (targetCap && targetCap !== urlCap);
  if (needRedirect) {
    const p = new URLSearchParams(params);
    p.set('start_date', targetStart);
    if (targetCap) p.set('capital', targetCap);
    window.location.replace('index.php?' + p.toString());
    return;
  }

  const currentEurUsd  = <?= round($currentEurUsd, 6) ?>;
  const startEurUsdIdx = <?= round($startEurUsdIdx, 6) ?>;

  // ── Gesamtwert aus eigener Simulation ──────────────────────────────────
  // latestRawTotal: direkt aus PHP-Simulation (USD für S&P 500, EUR für DAX/ETF)
  const latestRawTotal = <?= round($latestRawTotal, 2) ?>;
  const simReturn      = <?= round($simReturn, 8) ?>;
  // DAX + ETF: Werte bereits in EUR; S&P 500: USD → EUR via currentEurUsd
  const total = _isEurUni ? latestRawTotal
              : (_currency === 'EUR' ? latestRawTotal / currentEurUsd : latestRawTotal);
  const dispCurr   = _isEurUni ? 'EUR' : _currency;

  // ── Labels & Werte ──────────────────────────────────────────────────────
  const thBetrag = document.getElementById('th-betrag');
  if (thBetrag) thBetrag.textContent = 'Betrag in ' + dispCurr;
  const currLbl = document.getElementById('js-currency-label');
  if (currLbl) currLbl.textContent = dispCurr;

  document.querySelectorAll('.js-mv').forEach(el => {
    const mv = total * parseFloat(el.dataset.weight) / 100;
    el.textContent = Math.round(mv).toLocaleString('de-DE');
  });
  const totalEl = document.getElementById('js-mv-total');
  if (totalEl) totalEl.textContent = Math.round(total).toLocaleString('de-DE');
  const dispEl = document.getElementById('js-total-display');
  if (dispEl) dispEl.innerHTML =
    Math.round(total).toLocaleString('de-DE') +
    ' <span style="font-size:.9rem;font-weight:400;color:#6c757d;">' + dispCurr + '</span>';

  // ── Pie-Chart ────────────────────────────────────────────────────────────
  const canvas = document.getElementById('portfolioPie');
  if (!canvas) return;

  const data = <?= json_encode(array_map(fn($r) => [
    'ticker'  => $r['ticker'],
    'company' => $r['company'],
    'weight'  => round($r['weight'], 6),
  ], $latestPortfolio)) ?>;

  const palette = ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#0891b2','#b91c1c','#065f46'];

  new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: data.map(d => _isEtf ? d.company : d.ticker),
      datasets: [{
        data: data.map(d => d.weight),
        backgroundColor: palette,
        borderWidth: 2,
        borderColor: '#fff',
        hoverOffset: 8,
      }]
    },
    options: {
      cutout: '58%',
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: ctx => data[ctx[0].dataIndex].company,
            label: ctx => {
              const d = data[ctx.dataIndex];
              const mv = Math.round(total * d.weight / 100);
              return ` ${d.weight.toFixed(1).replace('.',',')}%  —  ${mv.toLocaleString('de-DE')} ${dispCurr}`;
            }
          }
        }
      }
    }
  });
})();

// ── GuV je Aktie (horizontaler Bar-Chart) ─────────────────────────────────
(function () {
  const canvas = document.getElementById('guvPerStockChart');
  if (!canvas) return;
  const data = <?= $stockGuvJson ?>;
  if (!data || !data.length) return;

  const dispCur = _isEurUni ? 'EUR' : _currency;
  const useEur  = _isEurUni || _currency === 'EUR';
  // Absteigend nach angezeigtem Wert sortieren (EUR oder USD je nach Toggle)
  data.sort((a, b) => (useEur ? b.guv_eur - a.guv_eur : b.guv_usd - a.guv_usd));
  const labels = data.map(d => _isEtf ? d.company.split('(')[0].trim() : (d.ticker || d.company));
  const values = data.map(d => useEur ? d.guv_eur : d.guv_usd);
  const colors = values.map(v => v >= 0 ? 'rgba(74,222,128,.75)' : 'rgba(248,113,113,.75)');
  const borders = values.map(v => v >= 0 ? '#4ade80' : '#f87171');

  // Canvas-Höhe dynamisch an Anzahl Bars anpassen
  const barH = 28;
  canvas.style.height = Math.max(200, data.length * barH + 40) + 'px';

  new Chart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: colors,
        borderColor: borders,
        borderWidth: 1,
        borderRadius: 3,
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#ffffff', borderColor: '#dee2e6', borderWidth: 1,
          titleColor: '#212529', bodyColor: '#6c757d',
          callbacks: {
            title: ctx => data[ctx[0].dataIndex].company,
            label: ctx => {
              const v = ctx.parsed.x;
              return ` ${v >= 0 ? '+' : ''}${v.toLocaleString('de-DE')} ${dispCur}`;
            }
          }
        }
      },
      scales: {
        x: {
          ticks: {
            color: 'rgba(255,255,255,.4)',
            callback: v => (v >= 0 ? '+' : '') + v.toLocaleString('de-DE', {maximumFractionDigits:0})
          },
          grid: { color: 'rgba(255,255,255,.06)' }
        },
        y: {
          ticks: { color: 'rgba(255,255,255,.5)', font: { size: 11 } },
          grid: { display: false }
        }
      }
    }
  });
})();

// ── Linien-Chart: Portfolio vs. Benchmark ─────────────────────────────────
(function () {
  const canvas = document.getElementById('idxChart');
  if (!canvas) return;

  const allLabels    = <?= $idxChartDates ?>;
  const allPortfolio = <?= $idxChartPortfolio ?>;
  const allBenchmark = <?= $idxChartBenchmark ?>;
  const allEurRates  = <?= json_encode($idxChartEurRates) ?>;
  const startEurUsd  = <?= round($idxStartEurUsd, 6) ?>;
  const endEurUsd    = <?= round($idxEndEurUsd, 6) ?>;
  const startCapital = <?= (int)$simStartCapital ?>;
  const currency     = _isEurUni ? 'EUR' : _currency;
  const sym          = currency === 'EUR' ? '€' : '$';

  const base = allPortfolio.find(v => v !== null) || 1;
  const eurAtPortStart = allEurRates.length ? (allEurRates.find(v => v > 0) || startEurUsd) : startEurUsd;

  const portfolio = allPortfolio.map((v, i) => {
    if (v === null) return null;
    const scaled = v / base * startCapital;
    if (_isEurUni) return Math.round(scaled);
    return currency === 'EUR' ? Math.round(scaled * eurAtPortStart / (allEurRates[i] || currentEurUsd)) : Math.round(scaled);
  });

  const firstBenchIdx = allBenchmark.findIndex(v => v !== null);
  const baseBench = allBenchmark.find(v => v !== null) || 1;
  let benchmark;
  if (_isEtf) {
    const anchorIdx   = firstBenchIdx >= 0 ? firstBenchIdx : 0;
    const anchorPort  = portfolio[anchorIdx] != null ? portfolio[anchorIdx] : startCapital;
    const eurAtAnchor = allEurRates[anchorIdx] > 0 ? allEurRates[anchorIdx] : currentEurUsd;
    benchmark = allBenchmark.map((v, i) => {
      if (v === null) return null;
      const eurI = allEurRates[i] > 0 ? allEurRates[i] : currentEurUsd;
      return Math.round(v / baseBench * anchorPort * (eurAtAnchor / eurI));
    });
  } else {
    const eurAtBenchStart = (!_isEurUni && currency === 'EUR')
      ? (allEurRates[firstBenchIdx >= 0 ? firstBenchIdx : 0] || eurAtPortStart) : 1;
    benchmark = allBenchmark.map((v, i) => {
      if (v === null) return null;
      const norm = Math.round(v / baseBench * startCapital);
      return (!_isEurUni && currency === 'EUR') ? Math.round(norm * eurAtBenchStart / (allEurRates[i] || currentEurUsd)) : norm;
    });
  }

  new Chart(canvas, {
    type: 'line',
    data: {
      labels: allLabels,
      datasets: [
        {
          label: _isEtf ? 'ETF Top-3 Portfolio' : 'RS Top-5 Portfolio',
          data: portfolio,
          borderColor: _isEtf ? '#6ee7b7' : '#4ade80',
          backgroundColor: _isEtf ? 'rgba(110,231,183,.08)' : 'rgba(74,222,128,.08)',
          fill: true, tension: 0.3, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4,
        },
        {
          label: _isEtf ? 'MSCI ACWI (ACWI)' : '<?= $isHdax ? 'HDAX (^HDAX)' : ($isDax ? 'DAX (^GDAXI)' : 'S&P 500 (SPY)') ?>',
          data: benchmark,
          borderColor: '#60a5fa', backgroundColor: 'transparent',
          fill: false, tension: 0.3, borderWidth: 1.5, borderDash: [4, 3],
          pointRadius: 0, pointHoverRadius: 4,
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: 'rgba(255,255,255,.4)', usePointStyle: true } },
        tooltip: {
          backgroundColor: '#ffffff', borderColor: '#dee2e6', borderWidth: 1,
          titleColor: '#212529', bodyColor: '#6c757d',
          callbacks: {
            title: items => {
              const iso = items[0]?.label ?? '';
              const [y,m,d] = iso.split('-');
              return d && m && y ? `${d}.${m}.${y}` : iso;
            },
            label: ctx => {
              const v = ctx.parsed.y;
              const fmt = v ? (currency === 'EUR'
                ? sym + v.toLocaleString('de-DE', {maximumFractionDigits:0})
                : v.toLocaleString('de-DE', {style:'currency', currency:'USD', maximumFractionDigits:0})) : '—';
              return ` ${ctx.dataset.label}: ${fmt}`;
            }
          }
        }
      },
      scales: {
        x: {
          ticks: {
            color: 'rgba(255,255,255,.4)', maxTicksLimit: 10, maxRotation: 0, autoSkip: true,
            callback: function(val, idx) {
              const d = allLabels[idx];
              return d ? d.slice(5,7) + '/' + d.slice(0,4) : '';
            }
          },
          grid: { color: 'rgba(255,255,255,.06)' }
        },
        y: {
          ticks: {
            color: 'rgba(255,255,255,.4)',
            callback: v => sym + v.toLocaleString('de-DE', {maximumFractionDigits:0})
          },
          grid: { color: 'rgba(255,255,255,.06)' }
        }
      }
    }
  });
})();
</script>
</body>
</html>
