<?php
require_once __DIR__ . '/../src/RSLEngine.php';
$rsl = new RSLEngine();
$db  = getDB();

$latestDate = $rsl->getLatestRankingDate();
$hasData    = !empty($latestDate);

// ── M&A-Filter (identisch zu simulation.php) ──────────────────────────────
$maFlagged = [];
foreach ($db->query('SELECT ticker FROM m_and_a_flags WHERE is_active = 1')
             ->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $maFlagged[$t] = true;
}

// ── Simulation ab Nutzer-Startdatum (identische Logik wie simulation.php) ──
$minDate      = '2010-01-04';
$maxDate      = $db->query('SELECT MAX(ranking_date) FROM rsl_rankings')->fetchColumn() ?: date('Y-m-d');
$simStartDate = $_GET['start_date'] ?? '2024-01-01';
if ($simStartDate < $minDate) $simStartDate = $minDate;
if ($simStartDate > $maxDate) $simStartDate = $maxDate;

$simStmt = $db->prepare(
    'SELECT r.ranking_date, r.ticker, r.sector, r.current_price, r.rsl, r.rank_overall,
            COALESCE(s.name, r.ticker) AS company
     FROM rsl_rankings r
     LEFT JOIN stocks s ON s.ticker = r.ticker
     WHERE r.ranking_date >= ?
     ORDER BY r.ranking_date ASC, r.rank_overall ASC'
);
$simStmt->execute([$simStartDate]);
$simByDate = [];
foreach ($simStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $simByDate[$row['ranking_date']][] = $row;
}
$simSundays = array_keys($simByDate);

$simStartCapital = max(1000.0, (float)($_GET['capital'] ?? 50000));
$simCash         = $simStartCapital;
$simHoldings = [];   // ticker → [shares, buy_price, sector, rsl, company]
$latestPortfolioDate = null;
$latestRawSnap = [];
$latestRawTotal = 0.0;
$prevTickers = [];

foreach ($simSundays as $i => $sunday) {
    $weekRankings = $simByDate[$sunday];
    $rankByTicker = array_column($weekRankings, null, 'ticker');
    $isLast = ($i === count($simSundays) - 1);

    $saleProceeds = [];

    // VERKAUF: Rang > 125 oder nicht mehr im Index
    foreach (array_keys($simHoldings) as $ticker) {
        $rank = isset($rankByTicker[$ticker])
            ? (int)$rankByTicker[$ticker]['rank_overall'] : PHP_INT_MAX;
        if ($rank > 125) {
            $price = (float)($rankByTicker[$ticker]['current_price'] ?? $simHoldings[$ticker]['buy_price']);
            $net   = $simHoldings[$ticker]['shares'] * $price;
            $simCash += $net;
            $saleProceeds[] = $net;
            unset($simHoldings[$ticker]);
        }
    }

    // KAUF
    $vacancies   = 5 - count($simHoldings);
    $heldSectors = array_column(array_values($simHoldings), 'sector');
    $cashPerSlot = $vacancies > 0 ? $simCash / $vacancies : 0;

    foreach ($weekRankings as $stock) {
        if ($vacancies <= 0) break;
        if (isset($simHoldings[$stock['ticker']])) continue;
        if ($isLast && isset($maFlagged[$stock['ticker']])) continue;
        $sector = $stock['sector'] ?? 'Unknown';
        if (in_array($sector, $heldSectors)) continue;
        $price = (float)$stock['current_price'];
        if ($price <= 0) continue;

        $budget = !empty($saleProceeds) ? array_shift($saleProceeds) : $cashPerSlot;
        if ($budget < 1) continue;
        $shares = $budget / $price;
        $simCash -= $budget;

        $simHoldings[$stock['ticker']] = [
            'shares'    => $shares,
            'buy_price' => $price,
            'sector'    => $sector,
            'rsl'       => (float)$stock['rsl'],
            'company'   => $stock['company'],
        ];
        $heldSectors[] = $sector;
        $vacancies--;
    }

    // Letzten Snapshot merken (für is_new: vorletzter Snapshot)
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

// ── Rendite aus eigener Simulation (frischer Start, identisch zu simulation.php) ─
$simReturn = $latestRawTotal > 0 ? ($latestRawTotal - $simStartCapital) / $simStartCapital : 0;

// Gewichte aus Simulation (skalenunabhängig) — USD-Beträge werden clientseitig skaliert
$latestPortfolio = [];
foreach ($latestRawSnap as $ticker => $h) {
    $weight = $latestRawTotal > 0 ? $h['raw_mv'] / $latestRawTotal * 100 : 0;
    $latestPortfolio[] = [
        'ticker'  => $ticker,
        'company' => $h['company'],
        'sector'  => $h['sector'],
        'rsl'     => $h['rsl'],
        'weight'  => $weight,
        'is_new'  => !in_array($ticker, $prevTickers),
    ];
}
usort($latestPortfolio, fn($a, $b) => $b['weight'] <=> $a['weight']);

// EUR/USD aktueller Kurs
$currentEurUsd = (float)($db->query("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date DESC LIMIT 1")->fetchColumn() ?: 1.10);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RSL nach Levy — S&P 500 Dashboard</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root {
    --rsl-green:  #198754;
    --rsl-red:    #dc3545;
    --rsl-blue:   #0d6efd;
    --rsl-card:   #ffffff;
    --rsl-border: #dee2e6;
    --rsl-muted:  #6c757d;
  }
  body { background: #f5f7fa; font-family: 'Segoe UI', sans-serif; }
  .navbar { background: #0f172a !important; border-bottom: 1px solid #1e2d4a; box-shadow: 0 2px 12px rgba(0,0,0,.3); min-height: 56px; }
  .navbar .container-fluid { min-height: 56px; height: auto; }
  .navbar .navbar-brand { color: #fff !important; font-weight: 700; padding: 0; }
  .navbar .nav-link { color: rgba(255,255,255,.6) !important; padding: .375rem .65rem !important; font-size: .875rem; }
  .navbar .nav-link:hover { color: #fff !important; }
  .card { background: #ffffff; border: 1px solid #dee2e6; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
  .card-header { background: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 600; }
  .metric-card { text-align: center; padding: 1.5rem; }
  .metric-value { font-size: 2rem; font-weight: 700; }
  .metric-label { color: #6c757d; font-size: .85rem; text-transform: uppercase; letter-spacing: .5px; }
  .table { --bs-table-bg: transparent; }
  .table th { color: #6c757d; font-weight: 500; font-size: .8rem; text-transform: uppercase; letter-spacing: .5px; border-color: #dee2e6; }
  .table td { border-color: #dee2e6; vertical-align: middle; }
  .table tbody tr:hover { background: #f8f9fa; }
  .rsl-badge { font-size: .9rem; font-weight: 700; padding: .3em .7em; border-radius: 6px; }
  .rsl-high  { background: rgba(22,163,74,.12);  color: #15803d; }
  .rsl-mid   { background: rgba(202,138,4,.12);  color: #92400e; }
  .rsl-low   { background: rgba(220,38,38,.12);  color: #dc2626; }
  .selected-row td:first-child { border-left: 3px solid var(--rsl-green); }
  .sector-badge { font-size: .72rem; background: #f0f2f5; color: #6c757d; padding: .2em .6em; border-radius: 20px; }
  .positive { color: #16a34a; }
  .negative { color: #dc2626; }
  .nav-link.active { color: #fff !important; font-weight: 600; }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }
  html { overflow-y: scroll; }
  .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
  .dot-green { background: var(--rsl-green); }
  .dot-yellow { background: #d97706; }
  .dot-red { background: var(--rsl-red); }
  .setup-banner { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 2rem; text-align: center; }
  footer { color: #6c757d; font-size: .8rem; padding: 2rem 0; border-top: 1px solid #dee2e6; margin-top: 3rem; }
  .ptable th { color:#6c757d; font-weight:500; font-size:.76rem; text-transform:uppercase; letter-spacing:.4px; border-color:#dee2e6; }
  .ptable td { border-color:#dee2e6; vertical-align:middle; font-size:.82rem; }
  .ptable tfoot td { font-size:.82rem; }
  .rsl-pill { background:#f0f2f5; border-radius:6px; padding:.15em .5em; font-size:.78rem; font-weight:600; color:#374151; }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold" href="index.php"><i class="bi bi-graph-up-arrow text-success me-2"></i>RSL nach Levy</a>
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <div class="navbar-nav ms-auto">
        <a class="nav-link" href="landing.php"><i class="bi bi-house me-1"></i>Start</a>
        <a class="nav-link active" href="index.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a class="nav-link" href="simulation.php"><i class="bi bi-sliders me-1"></i>Annahmen</a>
        <a class="nav-link" href="ranking.php"><i class="bi bi-list-ol me-1"></i>Ranking</a>
        <a class="nav-link" href="backtest.php"><i class="bi bi-clock-history me-1"></i>Backtest</a>
      </div>
      <div class="currency-toggle ms-lg-3 mt-2 mt-lg-0 mb-2 mb-lg-0">
        <button class="cur-btn" id="btn-usd">$ USD</button>
        <button class="cur-btn" id="btn-eur">€ EUR</button>
      </div>
    </div>
  </div>
</nav>

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
    <h4 class="mb-0">Dashboard</h4>
    <small class="text-muted">Stand: <?= $latestDate ? date('d.m.Y', strtotime($latestDate)) : '—' ?> (letzter Sonntag)</small>
  </div>
  <div>
    <span class="status-dot dot-green"></span><small class="text-muted">Live-Daten aktiv</small>
  </div>
</div>

<div class="row g-4">
  <!-- Aktuelles Portfolio -->
  <div class="col-lg-7">
    <div class="card">
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
              <th class="ps-3">Unternehmen</th>
              <th>Sektor</th>
              <th class="text-end">Gewicht</th>
              <th class="text-end" id="th-betrag">Betrag in USD</th>
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
              <td class="ps-3" style="color:#374151;"><?= htmlspecialchars($row['company']) ?></td>
              <td style="color:#6c757d;"><?= htmlspecialchars($row['sector']) ?></td>
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
      <div class="card-body d-flex flex-column align-items-center justify-content-center py-3">
        <?php if (!empty($latestPortfolio)): ?>
        <canvas id="portfolioPie" style="max-width:220px;max-height:220px;"></canvas>
        <div class="mt-3 text-center">
          <div style="font-size:.76rem;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;">Gesamtwert</div>
          <div id="js-total-display" style="font-size:1.5rem;font-weight:700;color:#212529;">
            — <span style="font-size:.9rem;font-weight:400;color:#6c757d;">USD</span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
</div>

<footer class="container-fluid px-4 text-center">
  RSL nach Levy — S&P 500 Momentum-System &nbsp;|&nbsp;
  Powered by Apache + MariaDB + PHP 8.2 &nbsp;|&nbsp;
  Daten: Yahoo Finance &nbsp;|&nbsp;
  <small>Kein Anlageberater — nur zu Informationszwecken</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  // ── Simulation-Parameter aus localStorage mit URL-Parametern abgleichen ──
  const params      = new URLSearchParams(window.location.search);
  const simStart    = localStorage.getItem('sim_start_date');
  const simCapSaved = localStorage.getItem('sim_capital');
  const urlStart    = params.get('start_date');
  const urlCap      = params.get('capital');
  const needStart   = simStart   && simStart   !== urlStart;
  const needCap     = simCapSaved && simCapSaved !== urlCap;
  if (needStart || needCap) {
    const s = simStart    || urlStart    || '2024-01-01';
    const c = simCapSaved || urlCap      || '50000';
    window.location.replace('index.php?start_date=' + encodeURIComponent(s) + '&capital=' + encodeURIComponent(c));
    return;
  }

  // Currency toggle init
  const _currency = localStorage.getItem('currency') || 'USD';
  document.getElementById('btn-usd')?.classList.toggle('active', _currency === 'USD');
  document.getElementById('btn-eur')?.classList.toggle('active', _currency === 'EUR');
  document.getElementById('btn-usd')?.addEventListener('click', () => { localStorage.setItem('currency', 'USD'); location.reload(); });
  document.getElementById('btn-eur')?.addEventListener('click', () => { localStorage.setItem('currency', 'EUR'); location.reload(); });

  const currentEurUsd = <?= round($currentEurUsd, 6) ?>;

  // ── Gesamtwert aus eigener Simulation (identisch zu simulation.php) ────
  const simReturn    = <?= round($simReturn, 8) ?>;
  const simCapital   = parseFloat(localStorage.getItem('sim_capital')) || 50000;
  const totalUsd     = simCapital * (1 + simReturn);
  const total        = _currency === 'EUR' ? totalUsd / currentEurUsd : totalUsd;
  const sym          = _currency === 'EUR' ? '€' : '$';

  // ── Tabellen-Header aktualisieren ───────────────────────────────────────
  const thBetrag = document.getElementById('th-betrag');
  if (thBetrag) thBetrag.textContent = 'Betrag in ' + _currency;

  // ── Tabellen-Werte befüllen ──────────────────────────────────────────────
  document.querySelectorAll('.js-mv').forEach(el => {
    const mv = total * parseFloat(el.dataset.weight) / 100;
    el.textContent = Math.round(mv).toLocaleString('de-DE');
  });
  const totalEl = document.getElementById('js-mv-total');
  if (totalEl) totalEl.textContent = Math.round(total).toLocaleString('de-DE');
  const dispEl = document.getElementById('js-total-display');
  if (dispEl) dispEl.innerHTML =
    Math.round(total).toLocaleString('de-DE') +
    ' <span style="font-size:.9rem;font-weight:400;color:#6c757d;">' + _currency + '</span>';

  // ── Pie-Chart ────────────────────────────────────────────────────────────
  const canvas = document.getElementById('portfolioPie');
  if (!canvas) return;

  const data = <?= json_encode(array_map(fn($r) => [
    'ticker'  => $r['ticker'],
    'company' => $r['company'],
    'weight'  => round($r['weight'], 6),
  ], $latestPortfolio)) ?>;

  const palette = ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed'];

  new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: data.map(d => d.ticker),
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
              return ` ${d.weight.toFixed(1).replace('.',',')}%  —  ${mv.toLocaleString('de-DE')} ${_currency}`;
            }
          }
        }
      }
    }
  });
})();
</script>
</body>
</html>
