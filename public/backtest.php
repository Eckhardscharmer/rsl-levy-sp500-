<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

// ── Universe ───────────────────────────────────────────────────────────────
$universe = $_GET['universe'] ?? 'sp500';
if (!in_array($universe, ['sp500', 'dax', 'etf'])) $universe = 'sp500';
$isDax    = ($universe === 'dax');
$isEtf    = ($universe === 'etf');

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
        foreach ($db->query('SELECT ticker FROM m_and_a_flags WHERE is_active = 1')
                     ->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $maFlagged[$t] = true;
        }

        foreach ($simSundays as $i => $sunday) {
            $weekRankings = $byDate[$sunday];
            $rankByTicker = array_column($weekRankings, null, 'ticker');
            $isLast       = ($i === count($simSundays) - 1);
            $saleProceeds = [];

            $holdRank = $isDax ? ($sunday >= '2021-09-20' ? 10 : 7) : 125;
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
                if ($isLast && isset($maFlagged[$stock['ticker']])) continue;
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
        $benchTicker = $isDax ? '^GDAXI' : 'SPY';
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
    if (!$isDax) {
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
        $chartEurRates = $rates;   // als PHP-Array, json_encode() erfolgt in JS-Ausgabe
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backtest — Relative Stärke</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  body { background: #f5f7fa; }
  .navbar { background: #0f172a !important; border-bottom: 1px solid #1e2d4a; box-shadow: 0 2px 12px rgba(0,0,0,.3); min-height: 56px; }
  .navbar .container-fluid { min-height: 56px; height: auto; }
  .navbar .navbar-brand { color: #fff !important; font-weight: 700; padding: 0; }
  .navbar .nav-link { color: rgba(255,255,255,.6) !important; padding: .375rem .65rem !important; font-size: .875rem; }
  .navbar .nav-link:hover { color: #fff !important; }
  .card { background: #ffffff; border: 1px solid #dee2e6; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
  .card-header { background: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 600; }
  .metric-card { text-align: center; padding: .85rem 1rem; }
  .metric-value { font-size: 1.25rem; font-weight: 700; }
  .metric-label { color: #6c757d; font-size: .72rem; text-transform: uppercase; letter-spacing: .4px; margin-top: .15rem; }
  .table { --bs-table-bg: transparent; }
  .table th { color: #6c757d; font-size: .78rem; text-transform: uppercase; border-color: #dee2e6; }
  .table td { border-color: #dee2e6; font-size: .87rem; vertical-align: middle; }
  .buy-action { color: #16a34a; }
  .sell-action { color: #dc2626; }
  .positive { color: #16a34a; }
  .negative { color: #dc2626; }
  .nav-link.active { color: #fff !important; font-weight: 600; }
  html { overflow-y: scroll; }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }
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
        <div class="metric-value text-warning" id="kpiDrawdown">—</div>
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
        <div class="metric-value text-muted" id="kpiBenchmark">—</div>
        <div class="metric-label"><?= $isEtf ? 'MSCI ACWI (ACWI)' : ($isDax ? 'DAX (^GDAXI)' : 'S&amp;P 500 (SPY)') ?> <span id="kpi-bench-curr" class="text-muted" style="font-size:.65rem;text-transform:none;letter-spacing:0;opacity:.75;"></span><?php if ($isEtf): ?><sup style="color:#f59e0b;font-size:.7rem;">*</sup><?php endif; ?></div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card metric-card">
        <div class="metric-value text-muted" id="tradesValue"><?= $numTrades ?></div>
        <div class="metric-label">Trades gesamt</div>
      </div>
    </div>
    <div class="col-6 col-md-2">
      <div class="card metric-card">
        <div class="metric-value text-muted" id="zeitraumValue">—</div>
        <div class="metric-label">Zeitraum</div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><i class="bi bi-graph-up me-2"></i>Portfolio-Entwicklung vs. <?= $isEtf ? 'MSCI ACWI (ACWI)' : ($isDax ? 'DAX (^GDAXI)' : 'S&amp;P 500 (SPY)') ?></div>
        <div class="card-body" style="height:422px;">
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
      </div>
    </div>
  </div>


<?php if ($isEtf): ?>
  <!-- Fußnote MSCI World Datenverfügbarkeit — Inhalt wird per JS gesetzt -->
  <div id="bench-footnote" style="display:none; margin-top:1.5rem; padding:.75rem 1rem;
       background:#fefce8; border:1px solid #fde68a; border-radius:10px;
       font-size:.8rem; color:#78350f; line-height:1.6;">
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
const endDate      = '<?= $endDate ?>';
const allBuyDates  = <?= $allBuyDatesJson ?>;
const allSellDates = <?= $allSellDatesJson ?>;
const startCapital = <?= (int)$startCapital ?>;
const _isDax       = <?= $isDax ? 'true' : 'false' ?>;
const _isEtf       = <?= $isEtf ? 'true' : 'false' ?>;
const _defStart    = _isEtf ? '2010-01-31' : '2010-01-04';

// Währungs-Toggle: DAX + ETF immer EUR; S&P 500 per localStorage
const _currency = (_isDax || _isEtf) ? 'EUR' : (localStorage.getItem('currency') || 'USD');
document.getElementById('btn-usd')?.classList.toggle('active', _currency === 'USD');
document.getElementById('btn-eur')?.classList.toggle('active', _currency === 'EUR');
document.getElementById('btn-usd')?.addEventListener('click', () => { localStorage.setItem('currency', 'USD'); location.reload(); });
document.getElementById('btn-eur')?.addEventListener('click', () => { localStorage.setItem('currency', 'EUR'); location.reload(); });

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
  return (v >= 0 ? '+' : '') + v.toFixed(decimals) + '%';
}

function buildChart(startDate) {
  // Find slice index
  let startIdx = allLabels.findIndex(d => d >= startDate);
  if (startIdx < 0) startIdx = 0;

  // DAX + ETF: EUR; S&P 500: localStorage
  const currency  = (_isDax || _isEtf) ? 'EUR' : (localStorage.getItem('currency') || 'USD');
  const sym       = currency === 'EUR' ? '€' : '$';

  const labels    = allLabels.slice(startIdx);
  const rawPort   = allPortfolio.slice(startIdx);
  const rawBench  = allBenchmark.slice(startIdx);
  const eurSlice  = allEurRates.slice(startIdx);

  // Portfolio normieren
  const base = rawPort.find(v => v !== null) || 1;
  const portfolio = rawPort.map((v, i) => {
    if (v === null) return null;
    const scaled = v / base * startCapital;
    // ETF + DAX: Simulationswerte sind EUR-nativ → keine FX-Division
    // S&P 500 EUR-Modus: USD-Werte in EUR umrechnen
    if (_isEtf || _isDax) return Math.round(scaled);
    return currency === 'EUR' ? Math.round(scaled / (eurSlice[i] || currentEurUsd)) : Math.round(scaled);
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
    benchmark = rawBench.map((v, i) => {
      if (v === null) return null;
      const usd = Math.round(v / baseBench * startCapital);
      return currency === 'EUR' ? Math.round(usd / (eurSlice[i] || currentEurUsd)) : usd;
    });
  }

  // --- KPI Berechnung ---
  const endPort  = [...rawPort].reverse().find(v => v !== null);
  const endBench = [...rawBench].reverse().find(v => v !== null);
  const eurStart = eurSlice.find(v => v > 0) || currentEurUsd;
  const eurEnd   = [...eurSlice].reverse().find(v => v > 0) || currentEurUsd;
  // ETF: Simulationswerte sind EUR-Basiseinheiten (kein FX-Faktor), wie DAX
  // S&P 500 im EUR-Modus: FX-Faktor (USD→EUR) anwenden
  const fxFactor = (!_isEtf && currency === 'EUR') ? (eurStart / eurEnd) : 1;
  const maxDD    = calcMaxDrawdown(rawPort);

  // Gesamt-Rendite Portfolio (voller Zeitraum ab Sim-Start)
  const totalReturn = base > 0 ? ((endPort / base) * fxFactor - 1) * 100 : 0;

  // Benchmark-Rendite + Outperformance:
  // Für ETF startet MSCI ACWI erst später → nur gemeinsamen Zeitraum vergleichen
  let benchReturn, outperf, benchStartLabel = null;
  if (_isEtf && firstBenchIdx > 0) {
    // Gemeinsamer Zeitraum ab firstBenchIdx (= erster ACWI-Datenpunkt)
    // Portfolio in EUR-Basiseinheiten — kein FX-Faktor nötig
    const portCommonStart  = portfolio[firstBenchIdx];
    const portCommonEnd    = [...portfolio].reverse().find(v => v !== null);
    const portReturnCommon = portCommonStart > 0 ? (portCommonEnd / portCommonStart - 1) * 100 : 0;

    // ACWI EUR-Rendite: USD-Preisrendite × FX-Änderung (nur für den Benchmark-Zeitraum)
    const rawBenchEnd    = [...rawBench].reverse().find(v => v !== null);
    const eurAtBenchStart = (eurSlice[firstBenchIdx] > 0 ? eurSlice[firstBenchIdx] : currentEurUsd);
    const eurAtEnd        = ([...eurSlice].reverse().find(v => v > 0) || currentEurUsd);
    const benchFxFactor   = currency === 'EUR' ? (eurAtBenchStart / eurAtEnd) : 1;
    benchReturn  = baseBench > 0 ? ((rawBenchEnd / baseBench) * benchFxFactor - 1) * 100 : 0;
    outperf      = portReturnCommon - benchReturn;
    benchStartLabel = labels[firstBenchIdx]; // z.B. "2009-10-30"
  } else {
    // S&P 500 / DAX / ETF (firstBenchIdx=0): voller gemeinsamer Zeitraum
    // Benchmark-FX: ACWI/SPY sind USD → auch für ETF FX anwenden (Portfolio ist EUR-nativ, Benchmark nicht)
    const benchFxElse = (_isDax) ? 1 : (currency === 'EUR' ? (eurStart / eurEnd) : 1);
    benchReturn = baseBench > 0 ? ((endBench / baseBench) * benchFxElse - 1) * 100 : 0;
    outperf     = totalReturn - benchReturn;
  }

  const kpiReturn = document.getElementById('kpiReturn');
  if (kpiReturn) {
    kpiReturn.textContent = fmtPct(totalReturn);
    kpiReturn.className = 'metric-value ' + (totalReturn >= 0 ? 'text-success' : 'text-danger');
  }
  const kpiDD = document.getElementById('kpiDrawdown');
  if (kpiDD) kpiDD.textContent = '-' + maxDD.toFixed(1) + '%';

  const kpiOut = document.getElementById('kpiOutperformance');
  if (kpiOut) {
    kpiOut.textContent = fmtPct(outperf);
    kpiOut.className = 'metric-value ' + (outperf >= 0 ? 'text-success' : 'text-danger');
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
          labels: { color: '#495057', usePointStyle: true, boxWidth: 10 }
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
          ticks: { color: '#6c757d', maxTicksLimit: 10, maxRotation: 0, autoSkip: true },
          grid:  { display: false }
        },
        y: {
          stacked: true,
          ticks: { color: '#6c757d', stepSize: 1, precision: 0 },
          grid:  { color: 'rgba(0,0,0,.06)' },
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
    lastValByMonth[m] = currency === 'EUR' ? filteredPort[i] / rate : filteredPort[i];
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
          ticks: { color: '#6c757d', maxTicksLimit: 10, maxRotation: 0, autoSkip: true },
          grid:  { display: false }
        },
        y: {
          ticks: {
            color: '#6c757d',
            callback: v => (v >= 0 ? '+' : '') + v.toLocaleString('de-DE', {maximumFractionDigits: 0})
          },
          grid: { color: 'rgba(0,0,0,.06)' }
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
          label: _isEtf ? 'MSCI ACWI (ACWI)' : '<?= $isDax ? 'DAX (^GDAXI)' : 'S&P 500 (SPY)' ?>',
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
        legend: { labels: { color: '#495057', usePointStyle: true } },
        tooltip: {
          backgroundColor: '#ffffff',
          borderColor: '#dee2e6',
          borderWidth: 1,
          titleColor: '#212529',
          bodyColor: '#6c757d',
          callbacks: {
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
            color: '#6c757d',
            maxTicksLimit: 10,
            maxRotation: 0,
            autoSkip: true,
            callback: function(val, idx) {
              const d = labels[idx];
              return d ? d.slice(5, 7) + '/' + d.slice(0, 4) : '';
            }
          },
          grid: { color: 'rgba(0,0,0,.06)' }
        },
        y: {
          ticks: {
            color: '#6c757d',
            callback: v => sym + v.toLocaleString('de-DE', {maximumFractionDigits: 0})
          },
          grid: { color: 'rgba(0,0,0,.06)' }
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
  const simCapital = _isEtf ? 50000 : localStorage.getItem('sim_capital');
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
