<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

// ── Universe ───────────────────────────────────────────────────────────────
$universe = $_GET['universe'] ?? 'sp500';
if (!in_array($universe, ['sp500', 'dax', 'hdax', 'etf'])) $universe = 'sp500';
$isDax        = ($universe === 'dax');
$isHdax       = ($universe === 'hdax');
$isEtf        = ($universe === 'etf');
$isEurUniverse = ($isDax || $isHdax || $isEtf);

// ── Parameter (via GET, gesetzt vom JS-Redirect) ───────────────────────────
$minDate      = $isEtf ? '2000-01-31' : '2010-01-04';
$maxDate      = $db->query("SELECT MAX(ranking_date) FROM rsl_rankings WHERE universe='$universe'")->fetchColumn() ?: date('Y-m-d');
$startDate    = $_GET['start_date'] ?? $minDate;
$startCapital = max(1000, (float)($_GET['capital'] ?? 100000));
if ($startDate < $minDate) $startDate = $minDate;
if ($startDate > $maxDate) $startDate = $maxDate;

$hasData  = (bool)$db->query("SELECT COUNT(*) FROM rsl_rankings WHERE universe='$universe' LIMIT 1")->fetchColumn();
$numBuys  = 0;
$endDate  = date('Y-m-d');
$chartDates = $chartPortfolio = $chartBenchmark = $allBuyDatesJson = 'null';
$allSellDatesJson = 'null';
$currentEurUsd = 1.10;
$chartEurRates = [];   // leeres Array, kein String 'null'

if ($hasData) {
    // ── Rankings ab Startdatum laden ─────────────────────────────────────────
    $stmt = $db->prepare(
        'SELECT r.ranking_date, r.ticker, r.sector, r.current_price, r.rsl,
                r.rank_overall, r.is_selected
         FROM rsl_rankings r
         WHERE r.ranking_date >= ? AND r.universe = ?
         ORDER BY r.ranking_date ASC, r.rank_overall ASC'
    );
    $stmt->execute([$startDate, $universe]);
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDate[$row['ranking_date']][] = $row;
    }
    $simSundays = array_keys($byDate);

    $cash             = $startCapital;
    $holdings         = [];
    $weeklyPortfolio  = [];
    $allBuyDatesList  = [];
    $allSellDatesList = [];

    if ($isEtf) {
        // ── ETF-Simulation: monatlich, Top 3, SMA200-Filter, Cash-Regel ────────
        $prevHoldings = [];
        foreach ($simSundays as $monthEnd) {
            $monthRankings = $byDate[$monthEnd];
            $rankByTicker  = array_column($monthRankings, null, 'ticker');

            // Ziel-Portfolio: is_selected=1
            $targetTickers = [];
            foreach ($monthRankings as $r) {
                if ($r['is_selected']) $targetTickers[$r['ticker']] = $r;
            }

            // Gesamtwert (Cash + Holdings zum aktuellen Kurs)
            $totalValue = $cash;
            foreach ($holdings as $ticker => $h) {
                $totalValue += $h['shares'] * (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            }

            // Trades nur bei Zusammensetzungsänderung zählen
            $prevSet   = array_keys($holdings);
            $targetSet = array_keys($targetTickers);
            foreach (array_diff($prevSet, $targetSet) as $t)   $allSellDatesList[] = $monthEnd;
            foreach (array_diff($targetSet, $prevSet) as $t)   $allBuyDatesList[]  = $monthEnd;

            // Alles liquidieren (monatliches Full-Rebalancing)
            $cash     = $totalValue;
            $holdings = [];

            // Neu kaufen: gleichmäßig auf Ziel-Positionen aufteilen
            $n = count($targetTickers);
            if ($n > 0) {
                $perSlot = $cash / $n;
                foreach ($targetTickers as $ticker => $r) {
                    $price = (float)$r['current_price'];
                    if ($price <= 0) continue;
                    $cash -= $perSlot;
                    $holdings[$ticker] = ['shares' => $perSlot / $price, 'buy_price' => $price];
                }
            }

            // Monatlicher Portfolio-Wert
            $invested = 0;
            foreach ($holdings as $ticker => $h) {
                $invested += $h['shares'] * (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            }
            $weeklyPortfolio[$monthEnd] = $cash + $invested;
        }
        // Benchmark für ETF: MSCI ACWI (iShares MSCI ACWI ETF, Daten ab März 2008)
        $benchTicker = 'ACWI';
    } else {
        // ── S&P 500 / DAX Simulation (wöchentlich, Top 5, Sektordiversifikation) ─
        $maFlagged = [];
        foreach ($db->query('SELECT ticker FROM m_and_a_flags WHERE is_active = 1 AND (expires_date IS NULL OR expires_date > CURDATE())')
                     ->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $maFlagged[$t] = true;
        }

        foreach ($simSundays as $i => $sunday) {
            $weekRankings = $byDate[$sunday];
            $rankByTicker = array_column($weekRankings, null, 'ticker');
            $saleProceeds = [];

            $holdRank = $isHdax ? 25 : ($isDax ? ($sunday >= '2021-09-20' ? 10 : 7) : 125);
            foreach (array_keys($holdings) as $ticker) {
                $rank = isset($rankByTicker[$ticker])
                    ? (int)$rankByTicker[$ticker]['rank_overall'] : PHP_INT_MAX;
                if ($rank > $holdRank) {
                    $price = (float)($rankByTicker[$ticker]['current_price'] ?? $holdings[$ticker]['buy_price']);
                    $net   = $holdings[$ticker]['shares'] * $price;
                    $cash += $net;
                    $saleProceeds[] = $net;
                    $allSellDatesList[] = $sunday;
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
                $heldSectors[]     = $sector;
                $allBuyDatesList[] = $sunday;
                $vacancies--;
            }

            $invested = 0;
            foreach ($holdings as $ticker => $h) {
                $price     = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
                $invested += $h['shares'] * $price;
            }
            $weeklyPortfolio[$sunday] = $cash + $invested;
        }
        $benchTicker = $isHdax ? '^HDAX' : ($isDax ? '^GDAXI' : 'SPY');
    }

    // ── Benchmark auf startCapital normiert ───────────────────────────────────
    $spyStmt = $db->prepare(
        'SELECT price_date, adj_close FROM prices
         WHERE ticker = ? AND price_date >= ? ORDER BY price_date ASC'
    );
    $spyStmt->execute([$benchTicker, $startDate]);
    $spyByDate = [];
    foreach ($spyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $spyByDate[$row['price_date']] = (float)$row['adj_close'];
    }
    $spyDates    = array_keys($spyByDate);
    $spyStartP   = null;
    $spyIdx      = 0;
    $nSpy        = count($spyDates);
    $weeklyBench = [];
    foreach ($simSundays as $sunday) {
        while ($spyIdx < $nSpy - 1 && $spyDates[$spyIdx + 1] <= $sunday) $spyIdx++;
        if ($nSpy > 0 && $spyDates[$spyIdx] <= $sunday) {
            $sc = $spyByDate[$spyDates[$spyIdx]];
            if ($spyStartP === null) $spyStartP = $sc;
            $weeklyBench[$sunday] = round($startCapital * ($sc / $spyStartP));
        } else {
            $weeklyBench[$sunday] = null;
        }
    }

    // ── JSON für JavaScript ───────────────────────────────────────────────
    $numTrades        = count($allBuyDatesList) + count($allSellDatesList);
    $endDate          = end($simSundays) ?: date('Y-m-d');
    $chartDates       = json_encode(array_keys($weeklyPortfolio));
    $chartPortfolio   = json_encode(array_map('round', array_values($weeklyPortfolio)));
    $chartBenchmark   = json_encode(array_map(fn($d) => $weeklyBench[$d] ?? null, array_keys($weeklyPortfolio)));
    $allBuyDatesJson  = json_encode($allBuyDatesList);
    $allSellDatesJson = json_encode($allSellDatesList);

    // ── EUR/USD historische Kurse (S&P 500 und ETF) ──────────────────────────
    // ETF: Portfolio EUR-nativ, aber ACWI-Benchmark ist USD → FX-Kurse nötig
    if (!$isDax && !$isHdax) {
        $eurRatesRaw   = $db->query("SELECT price_date, adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date")->fetchAll(PDO::FETCH_KEY_PAIR);
        $currentEurUsd = $eurRatesRaw ? (float)end($eurRatesRaw) : 1.10;
        $eurDates      = array_keys($eurRatesRaw);
        $nEur          = count($eurDates);
        $eurIdx        = 0;
        $rates         = [];
        foreach (array_keys($weeklyPortfolio) as $sunday) {
            while ($eurIdx < $nEur - 1 && $eurDates[$eurIdx + 1] <= $sunday) $eurIdx++;
            $rates[] = ($nEur > 0 && $eurDates[$eurIdx] <= $sunday)
                ? (float)$eurRatesRaw[$eurDates[$eurIdx]] : $currentEurUsd;
        }
        $chartEurRates = $rates;

        // Start-EUR/USD: Kurs auf oder vor dem ersten Ranking-Datum (nicht Input-Datum!)
        // → identisch mit compare.php, das ebenfalls $firstDate = $simSundays[0] nutzt
        $stmtEurStart = $db->prepare("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' AND price_date <= ? ORDER BY price_date DESC LIMIT 1");
        $stmtEurStart->execute([$simSundays[0]]);
        $startEurUsd = (float)($stmtEurStart->fetchColumn() ?: $currentEurUsd);

        // End-EUR/USD: Kurs am letzten Ranking-Datum (nicht currentEurUsd, das kann neuer sein)
        $stmtEurStart->execute([end($simSundays)]);
        $endEurUsd = (float)($stmtEurStart->fetchColumn() ?: $currentEurUsd);
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backtest — Relative Stärke nach Levy</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  body { background: #0a0f1e; color: #e2e8f0; font-family: 'Inter', sans-serif; }
  .navbar { background: #0f172a !important; border-bottom: 1px solid #1e2d4a; box-shadow: 0 2px 12px rgba(0,0,0,.3); min-height: 56px; }
  .navbar .container-fluid { min-height: 56px; height: auto; }
  .navbar .navbar-brand { color: #fff !important; font-weight: 700; padding: 0; }
  .navbar .nav-link { color: rgba(255,255,255,.6) !important; padding: .375rem .65rem !important; font-size: .875rem; }
  .navbar .nav-link:hover { color: #fff !important; }
  .card { background: #111827; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; }
  .card-header { background: #0f172a; border-bottom: 1px solid rgba(255,255,255,.08); font-weight: 600; color: #f1f5f9; }
  .card-footer { background: #0f172a; border-top: 1px solid rgba(255,255,255,.08); color: rgba(255,255,255,.4); }
  .metric-card { text-align: center; padding: .85rem 1rem; background: #1e293b; border-radius: 10px; }
  .metric-value { font-size: 1.25rem; font-weight: 700; color: #f1f5f9; }
  .metric-label { color: rgba(255,255,255,.4); font-size: .72rem; text-transform: uppercase; letter-spacing: .4px; margin-top: .15rem; }
  .table { --bs-table-bg: transparent; --bs-table-color: #e2e8f0; }
  .table th { color: rgba(255,255,255,.4); font-weight: 700; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; border-color: rgba(255,255,255,.06); }
  .table td { border-color: rgba(255,255,255,.06); font-size: .87rem; vertical-align: middle; color: #e2e8f0; }
  .table tbody tr:hover { background: rgba(255,255,255,.03); }
  .buy-action { color: #4ade80; font-weight: 600; }
  .sell-action { color: #f87171; font-weight: 600; }
  .positive { color: #4ade80; }
  .negative { color: #f87171; }
  .nav-link.active { color: #fff !important; font-weight: 600; }
  html { overflow-y: scroll; }
  .bt-chart-body { height: 422px; }
  @media (max-width: 575px) { .bt-chart-body { height: 280px; } }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }
  .text-muted { color: rgba(255,255,255,.4) !important; }
  .text-success { color: #4ade80 !important; }
  .text-danger  { color: #f87171 !important; }
  .kpi-green  { color: #4ade80; }
  .kpi-red    { color: #f87171; }
  .kpi-blue   { color: #60a5fa; }
  .kpi-white  { color: #f1f5f9; }
  hr { border-color: rgba(255,255,255,.07); }
</style>
</head>
<body>
<?php $activePage = 'backtest'; include __DIR__ . '/inc_navbar.php'; ?>

<div class="container-fluid px-4 py-4">
  <h4 class="mb-4">Backtest — <?= $isEtf ? 'ETF-Momentum Top-3 (monatlich)' : 'RS Top-5 (wöchentlich)' ?></h4>

<?php if (!$hasData): ?>
  <div class="alert" style="background:#1e3a5f;border:1px solid #1d4ed8;border-radius:12px">
    <i class="bi bi-info-circle me-2"></i>
    Noch keine Backtest-Daten. Führe zuerst alle Setup-Scripts aus.
  </div>
<?php else: ?>

  <!-- Metriken -->
  <div class="row g-2 mb-4">
    <div class="col-6 col-md-2">
      <div class="card metric-card">
        <div class="metric-value" id="kpiReturn">—</div>
        <div class="metric-label">Gesamt-Rendite <span id="kpi-return-curr" class="text-muted" style="font-size:.65rem;text-transform:none;letter-spacing:0;opacity:.75;"></span></div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card metric-card">
        <div class="metric-value kpi-red" id="kpiDrawdown">—</div>
        <div class="metric-label">Max. Drawdown</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card metric-card">
        <div class="metric-value" id="kpiOutperformance">—</div>
        <div class="metric-label">Outperformance<?php if ($isEtf): ?><sup style="color:#f59e0b;font-size:.7rem;">*</sup><?php endif; ?> <span id="kpi-outperf-curr" class="text-muted" style="font-size:.65rem;text-transform:none;letter-spacing:0;opacity:.75;"></span></div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card metric-card">
        <div class="metric-value kpi-blue" id="kpiBenchmark">—</div>
        <div class="metric-label"><?= $isEtf ? 'MSCI ACWI (ACWI)' : ($isHdax ? 'HDAX (^HDAX)' : ($isDax ? 'DAX (^GDAXI)' : 'S&amp;P 500 (SPY)')) ?> <span id="kpi-bench-curr" class="text-muted" style="font-size:.65rem;text-transform:none;letter-spacing:0;opacity:.75;"></span><?php if ($isEtf): ?><sup style="color:#f59e0b;font-size:.7rem;">*</sup><?php endif; ?></div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card metric-card">
        <div class="metric-value kpi-white" id="tradesValue"><?= $numTrades ?></div>
        <div class="metric-label">Trades gesamt</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card metric-card">
        <div class="metric-value kpi-white" id="zeitraumValue">—</div>
        <div class="metric-label">Zeitraum</div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-graph-up me-2"></i>Portfolio-Entwicklung vs. <?= $isEtf ? 'MSCI ACWI (ACWI)' : ($isHdax ? 'HDAX (^HDAX)' : ($isDax ? 'DAX (^GDAXI)' : 'S&amp;P 500 (SPY)')) ?></div>
        <div class="card-body bt-chart-body">
          <canvas id="btChart"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-6 d-flex flex-column gap-3">
      <div class="card">
        <div class="card-header"><i class="bi bi-bar-chart-fill me-2"></i>Trades pro Monat</div>
        <div class="card-body" style="height:180px;">
          <canvas id="tradesChart"></canvas>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><i class="bi bi-bar-chart-steps me-2"></i>GuV pro Monat</div>
        <div class="card-body" style="height:180px;">
          <canvas id="guvChart"></canvas>
        </div>
        <div class="card-footer" style="font-size:.78rem;color:rgba(255,255,255,.4);line-height:1.5;">
          <i class="bi bi-info-circle me-1"></i>
          Jeder Balken zeigt die Wertveränderung des Gesamtportfolios im jeweiligen Kalendermonat.
          Der laufende Monat endet am letzten verfügbaren Datenpunkt und ist daher nur ein Teilmonat.
        </div>
      </div>
    </div>
  </div>


<?php if ($isEtf): ?>
  <!-- Fußnote MSCI World Datenverfügbarkeit — Inhalt wird per JS gesetzt -->
  <div id="bench-footnote" style="display:none; margin-top:1.5rem; padding:.75rem 1rem;
       background:rgba(251,191,36,.1); border:1px solid rgba(251,191,36,.3); border-radius:10px;
       font-size:.8rem; color:#fbbf24; line-height:1.6;">
  </div>
<?php endif; ?>
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($hasData): ?>
<script>
const allLabels    = <?= $chartDates ?>;
const allPortfolio = <?= $chartPortfolio ?>;
const allBenchmark = <?= $chartBenchmark ?>;
const allEurRates  = <?= json_encode(is_array($chartEurRates) ? $chartEurRates : []) ?>;
const currentEurUsd = <?= round($currentEurUsd, 6) ?>;
const startEurUsd   = <?= round($startEurUsd ?? $currentEurUsd, 6) ?>; // EUR/USD am Input-Startdatum
const endEurUsd     = <?= round($endEurUsd ?? $currentEurUsd, 6) ?>;   // EUR/USD am letzten Ranking-Datum
const endDate      = '<?= $endDate ?>';
const allBuyDates  = <?= $allBuyDatesJson ?>;
const allSellDates = <?= $allSellDatesJson ?>;
const startCapital = <?= (int)$startCapital ?>;
const _isDax       = <?= $isDax ? 'true' : 'false' ?>;
const _isHdax      = <?= $isHdax ? 'true' : 'false' ?>;
const _isEtf       = <?= $isEtf ? 'true' : 'false' ?>;
const _isEurUni    = <?= $isEurUniverse ? 'true' : 'false' ?>;
const _defStart    = _isEtf ? '2010-01-31' : '2010-01-04';

// Währungs-Toggle: DAX/HDAX/ETF immer EUR; S&P 500 per localStorage
let _currency = _isEurUni ? 'EUR' : (localStorage.getItem('currency') || 'EUR');

function applyCurrencyToggle(newCur) {
  if (_isEurUni) return; // EUR-Universen immer EUR
  localStorage.setItem('currency', newCur);
  _currency = newCur;
  document.getElementById('btn-usd')?.classList.toggle('active', newCur === 'USD');
  document.getElementById('btn-eur')?.classList.toggle('active', newCur === 'EUR');
  const lbl = newCur === 'EUR' ? '(EUR)' : '(USD)';
  ['kpi-return-curr', 'kpi-outperf-curr', 'kpi-bench-curr'].forEach(id => {
    const el = document.getElementById(id); if (el) el.textContent = lbl;
  });
  buildChart(allLabels[0]);
}

document.getElementById('btn-usd')?.classList.toggle('active', _currency === 'USD');
document.getElementById('btn-eur')?.classList.toggle('active', _currency === 'EUR');
document.getElementById('btn-usd')?.addEventListener('click', () => applyCurrencyToggle('USD'));
document.getElementById('btn-eur')?.addEventListener('click', () => applyCurrencyToggle('EUR'));

// Currency labels on KPI boxes
const _currLabel = _currency === 'EUR' ? '(EUR)' : '(USD)';
['kpi-return-curr', 'kpi-outperf-curr', 'kpi-bench-curr'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.textContent = _currLabel;
});

function formatMonthYear(dateStr) {
  const d = new Date(dateStr);
  return (d.getMonth()+1).toString().padStart(2,'0') + '/' + d.getFullYear();
}

function calcMaxDrawdown(values) {
  let peak = -Infinity, maxDD = 0;
  for (const v of values) {
    if (v === null || v === undefined) continue;
    if (v > peak) peak = v;
    if (peak > 0) { const dd = (peak - v) / peak; if (dd > maxDD) maxDD = dd; }
  }
  return maxDD * 100;
}

function fmtPct(v, decimals = 1) {
  return (v >= 0 ? '+' : '') + v.toFixed(decimals).replace('.', ',') + '%';
}

function buildChart(startDate) {
  // Find slice index
  let startIdx = allLabels.findIndex(d => d >= startDate);
  if (startIdx < 0) startIdx = 0;

  // DAX + ETF: EUR; S&P 500: localStorage
  const currency  = _isEurUni ? 'EUR' : (localStorage.getItem('currency') || 'EUR');
  const sym       = currency === 'EUR' ? '€' : '$';

  const labels    = allLabels.slice(startIdx);
  const rawPort   = allPortfolio.slice(startIdx);
  const rawBench  = allBenchmark.slice(startIdx);
  const eurSlice  = allEurRates.slice(startIdx);

  // Portfolio normieren
  const base = rawPort.find(v => v !== null) || 1;
  const eurAtPortStart = eurSlice.find(v => v > 0) || startEurUsd;
  const portfolio = rawPort.map((v, i) => {
    if (v === null) return null;
    const scaled = v / base * startCapital;
    // ETF + DAX: Simulationswerte sind EUR-nativ → keine FX-Division
    // S&P 500 EUR-Modus: USD-Werte in EUR umrechnen; eurAtPortStart korrigiert so dass Start=startCapital EUR
    if (_isEurUni) return Math.round(scaled);
    return currency === 'EUR' ? Math.round(scaled * eurAtPortStart / (eurSlice[i] || currentEurUsd)) : Math.round(scaled);
  });

  // Benchmark normieren
  const firstBenchIdx = rawBench.findIndex(v => v !== null);
  const baseBench     = rawBench.find(v => v !== null) || 1;
  let benchmark;
  if (_isEtf) {
    // ETF: Benchmark immer mit per-Datenpunkt EUR/USD-Umrechnung + Normierung,
    // damit Startpunkt und Chart konsistent mit Portfolio (beide in EUR, beide bei startCapital).
    const anchorIdx  = firstBenchIdx >= 0 ? firstBenchIdx : 0;
    const anchorPort = portfolio[anchorIdx] != null ? portfolio[anchorIdx] : startCapital;
    const eurAtAnchor = eurSlice[anchorIdx] > 0 ? eurSlice[anchorIdx] : currentEurUsd;
    benchmark = rawBench.map((v, i) => {
      if (v === null) return null;
      const eurI = eurSlice[i] > 0 ? eurSlice[i] : currentEurUsd;
      return Math.round(v / baseBench * anchorPort * (eurAtAnchor / eurI));
    });
  } else {
    // S&P 500 / DAX: Standard-Normierung auf Startkapital mit optionaler EUR-Umrechnung
    // eurAtBenchStart: EUR/USD am ersten Benchmark-Datenpunkt → damit startet SPY bei startCapital EUR
    const eurAtBenchStart = (!_isEurUni && currency === 'EUR')
      ? (eurSlice[firstBenchIdx >= 0 ? firstBenchIdx : 0] || eurAtPortStart)
      : 1;
    benchmark = rawBench.map((v, i) => {
      if (v === null) return null;
      const normalized = Math.round(v / baseBench * startCapital);
      // DAX: ^GDAXI ist EUR-nativ → keine FX-Umrechnung
      // S&P 500: SPY ist USD-notiert → bei EUR-Modus mit eurAtBenchStart skalieren (Start = startCapital EUR)
      return (!_isEurUni && currency === 'EUR') ? Math.round(normalized * eurAtBenchStart / (eurSlice[i] || currentEurUsd)) : normalized;
    });
  }

  // --- KPI Berechnung ---
  // fxFactor: startEurUsd (am Input-Datum) / endEurUsd (am letzten Ranking-Datum)
  // Entspricht exakt compare.php runSim() — NICHT currentEurUsd verwenden (kann neuer sein als Rankings).
  // Drawdown aus nativen Simulationswerten (USD für S&P 500, EUR für DAX/HDAX)
  const maxDD   = calcMaxDrawdown(!_isEurUni ? rawPort : portfolio);
  const endPort = [...rawPort].reverse().find(v => v !== null);
  const fxFactor = (!_isEurUni && currency === 'EUR') ? (startEurUsd / endEurUsd) : 1;
  const totalReturn = base > 0 ? ((endPort / base) * fxFactor - 1) * 100 : 0;

  // Benchmark-Rendite + Outperformance
  const endBench = [...rawBench].reverse().find(v => v !== null);
  let benchReturn, outperf, benchStartLabel = null;
  if (_isEtf && firstBenchIdx > 0) {
    // ETF: gemeinsamer Zeitraum ab erstem ACWI-Datenpunkt
    const portCommonStart  = rawPort[firstBenchIdx];
    const portCommonEnd    = endPort;
    const portReturnCommon = portCommonStart > 0 ? ((portCommonEnd / portCommonStart) * fxFactor - 1) * 100 : 0;
    const eurAtBenchStart  = (allEurRates[firstBenchIdx] > 0 ? allEurRates[firstBenchIdx] : startEurUsd);
    const acwiFx           = eurAtBenchStart / endEurUsd;
    benchReturn  = baseBench > 0 ? ((endBench / baseBench) * acwiFx - 1) * 100 : 0;
    outperf      = portReturnCommon - benchReturn;
    benchStartLabel = labels[firstBenchIdx];
  } else {
    // S&P 500 / DAX / HDAX / ETF (firstBenchIdx=0): voller gemeinsamer Zeitraum
    // ETF: ACWI ist USD-denominiert → FX-Umrechnung nötig obwohl Portfolio EUR-nativ
    const eurAtSimStart0 = (_isEtf && allEurRates && allEurRates[0] > 0)
      ? allEurRates[0] : startEurUsd;
    const benchFx = _isEtf
      ? (eurAtSimStart0 / endEurUsd)
      : (_isEurUni ? 1 : (currency === 'EUR' ? (startEurUsd / endEurUsd) : 1));
    benchReturn = baseBench > 0 ? ((endBench / baseBench) * benchFx - 1) * 100 : 0;
    outperf     = totalReturn - benchReturn;
  }

  const kpiReturn = document.getElementById('kpiReturn');
  if (kpiReturn) {
    kpiReturn.textContent = fmtPct(totalReturn);
    kpiReturn.className = 'metric-value ' + (totalReturn >= 0 ? 'text-success' : 'text-danger');
  }
  const kpiDD = document.getElementById('kpiDrawdown');
  if (kpiDD) kpiDD.textContent = '-' + maxDD.toFixed(1).replace('.', ',') + '%';

  const kpiOut = document.getElementById('kpiOutperformance');
  if (kpiOut) {
    kpiOut.textContent = fmtPct(outperf);
    kpiOut.className = 'metric-value kpi-blue';
  }
  const kpiBench = document.getElementById('kpiBenchmark');
  if (kpiBench) kpiBench.textContent = fmtPct(benchReturn);

  // Fußnoten-Hinweis für ETF (MSCI ACWI erst ab Benchmark-Startdatum)
  const benchNote = document.getElementById('bench-footnote');
  if (benchNote && benchStartLabel) {
    const mon = formatMonthYear(benchStartLabel);
    benchNote.innerHTML =
      '<sup>*</sup> MSCI ACWI (iShares ACWI ETF) verfügbar ab ' + mon +
      ' — Outperformance und Benchmark-Rendite beziehen sich ausschließlich auf diesen gemeinsamen Zeitraum. ' +
      'Vor ' + mon + ' existierte kein MSCI-ACWI-ETF mit ausreichender Kurshistorie auf Yahoo Finance. ' +
      'Alle Werte in EUR (inkl. USD/EUR-Währungseffekt).';
    benchNote.style.display = 'block';
  }

  // Update Zeitraum box
  const zeitraum = document.getElementById('zeitraumValue');
  if (zeitraum) zeitraum.textContent = formatMonthYear(labels[0]) + ' – ' + formatMonthYear(endDate);

  // Trades filtern ab startDate
  const filteredBuys  = allBuyDates.filter(d => d >= startDate);
  const filteredSells = allSellDates.filter(d => d >= startDate);
  const tradesEl = document.getElementById('tradesValue');
  if (tradesEl) tradesEl.textContent = filteredBuys.length + filteredSells.length;

  // Monatsweise aggregieren
  const buysByMonth  = {};
  const sellsByMonth = {};
  filteredBuys.forEach(d  => { const m = d.slice(0,7); buysByMonth[m]  = (buysByMonth[m]  || 0) + 1; });
  filteredSells.forEach(d => { const m = d.slice(0,7); sellsByMonth[m] = (sellsByMonth[m] || 0) + 1; });

  const allMonths = [...new Set([...Object.keys(buysByMonth), ...Object.keys(sellsByMonth)])].sort();
  const barDisplayLabels = allMonths.map(m => m.slice(5) + '/' + m.slice(0, 4));
  const buyData  = allMonths.map(m => buysByMonth[m]  || 0);
  const sellData = allMonths.map(m => sellsByMonth[m] || 0);

  const tradesCtx = document.getElementById('tradesChart').getContext('2d');
  if (window._tradesChart) window._tradesChart.destroy();
  window._tradesChart = new Chart(tradesCtx, {
    type: 'bar',
    data: {
      labels: barDisplayLabels,
      datasets: [
        {
          label: 'Käufe',
          data: buyData,
          backgroundColor: 'rgba(22,163,74,.75)',
          borderColor: '#16a34a',
          borderWidth: 1,
          borderRadius: 0,
          stack: 'trades',
        },
        {
          label: 'Verkäufe',
          data: sellData,
          backgroundColor: 'rgba(220,38,38,.70)',
          borderColor: '#dc2626',
          borderWidth: 1,
          borderRadius: 0,
          stack: 'trades',
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          labels: { color: 'rgba(255,255,255,.4)', usePointStyle: true, boxWidth: 10 }
        },
        tooltip: {
          backgroundColor: '#ffffff',
          borderColor: '#dee2e6',
          borderWidth: 1,
          titleColor: '#212529',
          bodyColor: '#6c757d',
          callbacks: {
            label: ctx => ` ${ctx.parsed.y} ${ctx.dataset.label}`
          }
        }
      },
      scales: {
        x: {
          stacked: true,
          ticks: { color: 'rgba(255,255,255,.4)', maxTicksLimit: 10, maxRotation: 0, autoSkip: true },
          grid:  { display: false }
        },
        y: {
          stacked: true,
          ticks: { color: 'rgba(255,255,255,.4)', stepSize: 1, precision: 0 },
          grid:  { color: 'rgba(255,255,255,.06)' },
          beginAtZero: true
        }
      }
    }
  });

  // --- GuV pro Monat ---
  const filteredLabels = allLabels.filter(d => d >= startDate);
  const filteredPort   = allPortfolio.slice(allLabels.findIndex(d => d >= startDate));
  const filteredEur    = allEurRates.slice(allLabels.findIndex(d => d >= startDate));

  // Group: last portfolio value per month
  const lastValByMonth = {};
  filteredLabels.forEach((d, i) => {
    const m = d.slice(0, 7);
    const rate = filteredEur[i] || currentEurUsd;
    // DAX: Portfolio-Werte EUR-nativ → keine FX-Division; S&P 500 EUR-Modus: durch EUR/USD dividieren
    lastValByMonth[m] = (!_isEurUni && currency === 'EUR') ? filteredPort[i] / rate : filteredPort[i];
  });
  const months = Object.keys(lastValByMonth).sort();

  const guvLabels  = [];
  const guvData    = [];
  const guvColors  = [];
  for (let i = 1; i < months.length; i++) {
    const guv = lastValByMonth[months[i]] - lastValByMonth[months[i - 1]];
    guvLabels.push(months[i].slice(5) + '/' + months[i].slice(0, 4));
    guvData.push(Math.round(guv));
    guvColors.push(guv >= 0 ? 'rgba(22,163,74,.8)' : 'rgba(220,38,38,.8)');
  }

  const guvCtx = document.getElementById('guvChart').getContext('2d');
  if (window._guvChart) window._guvChart.destroy();
  window._guvChart = new Chart(guvCtx, {
    type: 'bar',
    data: {
      labels: guvLabels,
      datasets: [{
        data: guvData,
        backgroundColor: guvColors,
        borderColor: guvColors.map(c => c.replace('.8)', '1)')),
        borderWidth: 1,
        borderRadius: 3,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#ffffff',
          borderColor: '#dee2e6',
          borderWidth: 1,
          titleColor: '#212529',
          bodyColor: '#6c757d',
          callbacks: {
            label: ctx => {
              const v = ctx.parsed.y;
              const prefix = v >= 0 ? '+' : '';
              return ` ${prefix}${v.toLocaleString('de-DE')} ${currency}`;
            }
          }
        }
      },
      scales: {
        x: {
          ticks: { color: 'rgba(255,255,255,.4)', maxTicksLimit: 10, maxRotation: 0, autoSkip: true },
          grid:  { display: false }
        },
        y: {
          ticks: {
            color: 'rgba(255,255,255,.4)',
            callback: v => (v >= 0 ? '+' : '') + v.toLocaleString('de-DE', {maximumFractionDigits: 0})
          },
          grid: { color: 'rgba(255,255,255,.06)' }
        }
      }
    }
  });

  const ctx = document.getElementById('btChart').getContext('2d');
  if (window._btChart) window._btChart.destroy();
  window._btChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: _isEtf ? 'ETF Top-3 Portfolio' : 'RS Top-5 Portfolio',
          data: portfolio,
          borderColor: _isEtf ? '#6ee7b7' : '#4ade80',
          backgroundColor: _isEtf ? 'rgba(110,231,183,.08)' : 'rgba(74,222,128,.08)',
          fill: true,
          tension: 0.3,
          borderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 4,
        },
        {
          label: _isEtf ? 'MSCI ACWI (ACWI)' : '<?= $isHdax ? 'HDAX (^HDAX)' : ($isDax ? 'DAX (^GDAXI)' : 'S&P 500 (SPY)') ?>',
          data: benchmark,
          borderColor: '#60a5fa',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.3,
          borderWidth: 1.5,
          borderDash: [4, 3],
          pointRadius: 0,
          pointHoverRadius: 4,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: 'rgba(255,255,255,.4)', usePointStyle: true } },
        tooltip: {
          backgroundColor: '#ffffff',
          borderColor: '#dee2e6',
          borderWidth: 1,
          titleColor: '#212529',
          bodyColor: '#6c757d',
          callbacks: {
            title: items => {
              const iso = items[0]?.label ?? '';
              const [y,m,d] = iso.split('-');
              return d && m && y ? `${d}.${m}.${y}` : iso;
            },
            label: ctx => {
              const v = ctx.parsed.y;
              const fmt = v ? (currency === 'EUR' ? sym + v.toLocaleString('de-DE', {maximumFractionDigits:0}) : v.toLocaleString('de-DE', {style:'currency', currency:'USD', maximumFractionDigits:0})) : '—';
              return ` ${ctx.dataset.label}: ${fmt}`;
            }
          }
        }
      },
      scales: {
        x: {
          ticks: {
            color: 'rgba(255,255,255,.4)',
            maxTicksLimit: 10,
            maxRotation: 0,
            autoSkip: true,
            callback: function(val, idx) {
              const d = labels[idx];
              return d ? d.slice(5, 7) + '/' + d.slice(0, 4) : '';
            }
          },
          grid: { color: 'rgba(255,255,255,.06)' }
        },
        y: {
          ticks: {
            color: 'rgba(255,255,255,.4)',
            callback: v => sym + v.toLocaleString('de-DE', {maximumFractionDigits: 0})
          },
          grid: { color: 'rgba(255,255,255,.06)' }
        }
      }
    }
  });
}

// Wenn start_date in der URL fehlt: mit Standardwert ergänzen und neu laden
const urlParams  = new URLSearchParams(window.location.search);
const urlStart   = urlParams.get('start_date');
const urlCapital = urlParams.get('capital');

if (!urlStart) {
  // Kein start_date in URL → Universe-spezifischen Standard setzen
  const _startKey = 'sim_start_date_' + (<?= json_encode($universe) ?>);
  const simStart = localStorage.getItem(_startKey) || _defStart;
  // ETF: Kapital immer EUR 50.000 (localStorage kann veralteten Wert enthalten)
  const simCapital = _isEtf ? 50000 : localStorage.getItem('sim_capital_' + (<?= json_encode($universe) ?>));
  const p = new URLSearchParams(urlParams);
  p.set('start_date', simStart);
  if (simCapital) p.set('capital', parseInt(simCapital));
  window.location.replace('backtest.php?' + p.toString());
} else {
  buildChart(allLabels[0]);
}
</script>
<?php endif; ?>
</body>
</html>
