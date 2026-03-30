<?php
require_once __DIR__ . '/../src/RSLEngine.php';
$rsl = new RSLEngine();

$latestDate = $rsl->getLatestRankingDate();
$top5       = $rsl->getCurrentTop5();
$summary    = $rsl->getPortfolioSummary();
$btResults  = $rsl->getBacktestResults();

$hasData = !empty($top5);
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
    --rsl-dark:   #1a1d23;
    --rsl-card:   #252830;
    --rsl-border: #32363e;
    --rsl-text:   #e8eaed;
    --rsl-muted:  #9aa0a6;
  }
  body { background: var(--rsl-dark); color: var(--rsl-text); font-family: 'Segoe UI', sans-serif; }
  .navbar { background: #12141a !important; border-bottom: 1px solid var(--rsl-border); }
  .navbar-brand { font-weight: 700; letter-spacing: 1px; }
  .card { background: var(--rsl-card); border: 1px solid var(--rsl-border); border-radius: 12px; }
  .card-header { background: rgba(255,255,255,.04); border-bottom: 1px solid var(--rsl-border); font-weight: 600; }
  .metric-card { text-align: center; padding: 1.5rem; }
  .metric-value { font-size: 2rem; font-weight: 700; }
  .metric-label { color: var(--rsl-muted); font-size: .85rem; text-transform: uppercase; letter-spacing: .5px; }
  .table { --bs-table-bg: transparent; --bs-table-color: var(--rsl-text); }
  .table th { color: var(--rsl-muted); font-weight: 500; font-size: .8rem; text-transform: uppercase; letter-spacing: .5px; border-color: var(--rsl-border); }
  .table td { border-color: var(--rsl-border); vertical-align: middle; }
  .table tbody tr:hover { background: rgba(255,255,255,.03); }
  .rsl-badge { font-size: .9rem; font-weight: 700; padding: .3em .7em; border-radius: 6px; }
  .rsl-high  { background: rgba(25,135,84,.2);  color: #4ade80; }
  .rsl-mid   { background: rgba(255,193,7,.15); color: #fbbf24; }
  .rsl-low   { background: rgba(220,53,69,.2);  color: #f87171; }
  .selected-row td:first-child { border-left: 3px solid var(--rsl-green); }
  .sector-badge { font-size: .72rem; background: rgba(255,255,255,.08); color: var(--rsl-muted); padding: .2em .6em; border-radius: 20px; }
  .positive { color: #4ade80; }
  .negative { color: #f87171; }
  .nav-link.active { color: #fff !important; font-weight: 600; }
  .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }
  .dot-green { background: var(--rsl-green); }
  .dot-yellow { background: #fbbf24; }
  .dot-red { background: var(--rsl-red); }
  .setup-banner { background: linear-gradient(135deg, #1e3a5f, #0d2137); border: 1px solid #1d4ed8; border-radius: 12px; padding: 2rem; text-align: center; }
  footer { color: var(--rsl-muted); font-size: .8rem; padding: 2rem 0; border-top: 1px solid var(--rsl-border); margin-top: 3rem; }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-4">
    <a class="navbar-brand text-white" href="index.php">
      <i class="bi bi-graph-up-arrow text-success me-2"></i>RSL nach Levy
    </a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link active" href="index.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <a class="nav-link" href="ranking.php"><i class="bi bi-list-ol me-1"></i>Ranking</a>
      <a class="nav-link" href="backtest.php"><i class="bi bi-clock-history me-1"></i>Backtest</a>
      <a class="nav-link" href="portfolio.php"><i class="bi bi-briefcase me-1"></i>Portfolio</a>
      <a class="nav-link" href="admin.php"><i class="bi bi-gear me-1"></i>Admin</a>
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
    <code class="d-block mb-2 text-info">$ /Applications/XAMPP/xamppfiles/bin/php scripts/01_setup_database.php</code>
    <code class="d-block mb-2 text-info">$ /Applications/XAMPP/xamppfiles/bin/php scripts/02_download_prices.php</code>
    <code class="d-block mb-2 text-info">$ /Applications/XAMPP/xamppfiles/bin/php scripts/03_calculate_rsl.php</code>
    <code class="d-block mb-2 text-info">$ /Applications/XAMPP/xamppfiles/bin/php scripts/04_run_backtest.php</code>
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

<!-- KPI-Karten -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card metric-card">
      <div class="metric-value text-success"><?= count($top5) ?></div>
      <div class="metric-label">Aktive Signale (Top 5)</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card metric-card">
      <?php $pnl = $summary['total_unrealized_pnl']; ?>
      <div class="metric-value <?= $pnl >= 0 ? 'text-success' : 'text-danger' ?>">
        <?= ($pnl >= 0 ? '+' : '') . number_format($pnl, 0, ',', '.') ?> €
      </div>
      <div class="metric-label">Unrealisierter P&L</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card metric-card">
      <?php $rpnl = $summary['total_realized_pnl']; ?>
      <div class="metric-value <?= $rpnl >= 0 ? 'text-success' : 'text-danger' ?>">
        <?= ($rpnl >= 0 ? '+' : '') . number_format($rpnl, 0, ',', '.') ?> €
      </div>
      <div class="metric-label">Realisierter P&L</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card metric-card">
      <?php $cagr = $btResults['cagr_pct'] ?? null; ?>
      <div class="metric-value <?= $cagr !== null && $cagr >= 0 ? 'text-success' : 'text-danger' ?>">
        <?= $cagr !== null ? ($cagr >= 0 ? '+' : '') . number_format($cagr, 1) . '%' : '—' ?>
      </div>
      <div class="metric-label">Backtest CAGR</div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Top-5 Signale -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-star-fill text-warning me-2"></i>Aktuelle Top-5 Signale</span>
        <a href="ranking.php" class="btn btn-sm btn-outline-secondary">Vollständiges Ranking</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th class="ps-3">Rank</th>
              <th>Ticker</th>
              <th>Name</th>
              <th>Sektor</th>
              <th class="text-end">Kurs</th>
              <th class="text-end">SMA 26W</th>
              <th class="text-end pe-3">RSL</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($top5 as $i => $row): ?>
            <tr class="selected-row">
              <td class="ps-3 text-muted">#<?= $row['rank_overall'] ?></td>
              <td><strong><?= htmlspecialchars($row['ticker']) ?></strong></td>
              <td><small class="text-muted"><?= htmlspecialchars(substr($row['name'] ?? '', 0, 22)) ?></small></td>
              <td><span class="sector-badge"><?= htmlspecialchars($row['sector'] ?? '') ?></span></td>
              <td class="text-end"><?= number_format($row['current_price'], 2) ?></td>
              <td class="text-end text-muted"><?= number_format($row['sma_26w'], 2) ?></td>
              <td class="text-end pe-3">
                <span class="rsl-badge <?= $row['rsl'] >= 1.3 ? 'rsl-high' : ($row['rsl'] >= 1.0 ? 'rsl-mid' : 'rsl-low') ?>">
                  <?= number_format($row['rsl'], 4) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Portfolio-Übersicht + Backtest-Metriken -->
  <div class="col-lg-5">
    <!-- Portfolio -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-briefcase me-2"></i>Mein Portfolio</span>
        <a href="portfolio.php" class="btn btn-sm btn-outline-secondary">Details</a>
      </div>
      <div class="card-body">
        <div class="row text-center">
          <div class="col-6 border-end">
            <div class="fw-bold"><?= $summary['num_open'] ?></div>
            <small class="text-muted">Offene Positionen</small>
          </div>
          <div class="col-6">
            <div class="fw-bold"><?= $summary['num_closed'] ?></div>
            <small class="text-muted">Geschlossen</small>
          </div>
        </div>
        <?php if ($summary['num_open'] > 0): ?>
        <hr class="border-secondary">
        <div class="d-flex justify-content-between">
          <small class="text-muted">Investiert</small>
          <small><?= number_format($summary['total_invested'], 0, ',', '.') ?> €</small>
        </div>
        <div class="d-flex justify-content-between">
          <small class="text-muted">Aktueller Wert</small>
          <small><?= number_format($summary['total_current_value'], 0, ',', '.') ?> €</small>
        </div>
        <div class="d-flex justify-content-between mt-1">
          <small class="text-muted">Rendite</small>
          <small class="<?= $summary['return_pct'] >= 0 ? 'positive' : 'negative' ?> fw-bold">
            <?= ($summary['return_pct'] >= 0 ? '+' : '') . number_format($summary['return_pct'], 2) ?>%
          </small>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Backtest -->
    <?php if ($btResults): ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Backtest-Ergebnisse</span>
        <a href="backtest.php" class="btn btn-sm btn-outline-secondary">Chart</a>
      </div>
      <div class="card-body">
        <div class="row g-2 text-center">
          <div class="col-6">
            <div class="fw-bold <?= $btResults['total_return_pct'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= ($btResults['total_return_pct'] >= 0 ? '+' : '') . number_format($btResults['total_return_pct'], 1) ?>%
            </div>
            <small class="text-muted">Gesamt-Rendite</small>
          </div>
          <div class="col-6">
            <div class="fw-bold <?= $btResults['cagr_pct'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= ($btResults['cagr_pct'] >= 0 ? '+' : '') . number_format($btResults['cagr_pct'], 1) ?>% p.a.
            </div>
            <small class="text-muted">CAGR</small>
          </div>
          <div class="col-6">
            <div class="fw-bold text-warning">-<?= number_format($btResults['max_drawdown_pct'], 1) ?>%</div>
            <small class="text-muted">Max. Drawdown</small>
          </div>
          <div class="col-6">
            <div class="fw-bold"><?= number_format($btResults['sharpe_ratio'], 2) ?></div>
            <small class="text-muted">Sharpe Ratio</small>
          </div>
        </div>
        <hr class="border-secondary">
        <div class="d-flex justify-content-between">
          <small class="text-muted">vs. S&P 500</small>
          <small class="<?= $btResults['outperformance_pct'] >= 0 ? 'positive' : 'negative' ?> fw-bold">
            <?= ($btResults['outperformance_pct'] >= 0 ? '+' : '') . number_format($btResults['outperformance_pct'], 1) ?>%
          </small>
        </div>
        <small class="text-muted d-block mt-1">
          Zeitraum: <?= date('d.m.Y', strtotime($btResults['start_date'])) ?>
          – <?= date('d.m.Y', strtotime($btResults['end_date'])) ?>
        </small>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>
</div>

<footer class="container-fluid px-4 text-center">
  RSL nach Levy — S&P 500 Momentum-System &nbsp;|&nbsp;
  Powered by XAMPP + MariaDB + PHP 8.2 &nbsp;|&nbsp;
  Daten: Yahoo Finance &nbsp;|&nbsp;
  <small>Kein Anlageberater — nur zu Informationszwecken</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
