<?php
require_once __DIR__ . '/../src/RSLEngine.php';
$rsl     = new RSLEngine();
$results = $rsl->getBacktestResults();
$chartData = $rsl->getBacktestChartData();
$trades    = $rsl->getBacktestTrades(1, 200);

// Chart-Daten für JavaScript aufbereiten
$chartDates    = json_encode(array_column($chartData, 'value_date'));
$chartPortfolio = json_encode(array_map(fn($r) => round((float)$r['portfolio_value'], 2), $chartData));
$chartBenchmark = json_encode(array_map(fn($r) => $r['sp500_indexed'] ? round((float)$r['sp500_indexed'], 2) : null, $chartData));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backtest — RSL nach Levy</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  :root { --rsl-dark:#1a1d23; --rsl-card:#252830; --rsl-border:#32363e; --rsl-text:#e8eaed; --rsl-muted:#9aa0a6; }
  body { background: var(--rsl-dark); color: var(--rsl-text); }
  .navbar { background: #12141a !important; border-bottom: 1px solid var(--rsl-border); }
  .card { background: var(--rsl-card); border: 1px solid var(--rsl-border); border-radius: 12px; }
  .card-header { background: rgba(255,255,255,.04); border-bottom: 1px solid var(--rsl-border); font-weight: 600; }
  .metric-card { text-align: center; padding: 1.2rem; }
  .metric-value { font-size: 1.6rem; font-weight: 700; }
  .metric-label { color: var(--rsl-muted); font-size: .78rem; text-transform: uppercase; }
  .table { --bs-table-bg: transparent; --bs-table-color: var(--rsl-text); }
  .table th { color: var(--rsl-muted); font-size: .78rem; text-transform: uppercase; border-color: var(--rsl-border); }
  .table td { border-color: var(--rsl-border); font-size: .87rem; vertical-align: middle; }
  .buy-action { color: #4ade80; }
  .sell-action { color: #f87171; }
  .positive { color: #4ade80; }
  .negative { color: #f87171; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-4">
    <a class="navbar-brand text-white" href="index.php"><i class="bi bi-graph-up-arrow text-success me-2"></i>RSL nach Levy</a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link" href="index.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <a class="nav-link" href="ranking.php"><i class="bi bi-list-ol me-1"></i>Ranking</a>
      <a class="nav-link active" href="backtest.php"><i class="bi bi-clock-history me-1"></i>Backtest</a>
      <a class="nav-link" href="portfolio.php"><i class="bi bi-briefcase me-1"></i>Portfolio</a>
      <a class="nav-link" href="admin.php"><i class="bi bi-gear me-1"></i>Admin</a>
    </div>
  </div>
</nav>

<div class="container-fluid px-4 py-4">
  <h4 class="mb-1">Backtest — RSL Top-5 (wöchentlich)</h4>
  <p class="text-muted small mb-4">
    Equal Weight · Max. 1 Aktie/Sektor · 0,1% Transaktionskosten · Benchmark: SPY (S&P 500)
  </p>

<?php if (!$results): ?>
  <div class="alert" style="background:#1e3a5f;border:1px solid #1d4ed8;border-radius:12px">
    <i class="bi bi-info-circle me-2"></i>
    Noch keine Backtest-Daten. Führe zuerst alle Setup-Scripts aus.
  </div>
<?php else: ?>

  <!-- Metriken -->
  <div class="row g-3 mb-4">
    <?php
    $metrics = [
      ['label'=>'Gesamt-Rendite',   'value'=>($results['total_return_pct']>=0?'+':'').number_format($results['total_return_pct'],1).'%', 'class'=>$results['total_return_pct']>=0?'text-success':'text-danger'],
      ['label'=>'CAGR p.a.',        'value'=>($results['cagr_pct']>=0?'+':'').number_format($results['cagr_pct'],1).'%',               'class'=>$results['cagr_pct']>=0?'text-success':'text-danger'],
      ['label'=>'Max. Drawdown',    'value'=>'-'.number_format($results['max_drawdown_pct'],1).'%',                                     'class'=>'text-warning'],
      ['label'=>'Sharpe Ratio',     'value'=>number_format($results['sharpe_ratio'],2),                                                  'class'=>$results['sharpe_ratio']>=1?'text-success':'text-muted'],
      ['label'=>'S&P 500 (SPY)',    'value'=>($results['benchmark_return_pct']>=0?'+':'').number_format($results['benchmark_return_pct'],1).'%', 'class'=>'text-muted'],
      ['label'=>'Outperformance',   'value'=>($results['outperformance_pct']>=0?'+':'').number_format($results['outperformance_pct'],1).'%', 'class'=>$results['outperformance_pct']>=0?'text-success':'text-danger'],
      ['label'=>'Trades gesamt',    'value'=>$results['num_total_trades'],                                                               'class'=>'text-muted'],
      ['label'=>'Zeitraum',         'value'=>date('m/Y',strtotime($results['start_date'])).' – '.date('m/Y',strtotime($results['end_date'])), 'class'=>'text-muted'],
    ];
    foreach ($metrics as $m): ?>
    <div class="col-6 col-md-3">
      <div class="card metric-card">
        <div class="metric-value <?= $m['class'] ?>"><?= $m['value'] ?></div>
        <div class="metric-label"><?= $m['label'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Chart -->
  <div class="card mb-4">
    <div class="card-header"><i class="bi bi-graph-up me-2"></i>Portfolio-Entwicklung vs. S&P 500</div>
    <div class="card-body">
      <canvas id="btChart" height="100"></canvas>
    </div>
  </div>

  <!-- Trades -->
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span><i class="bi bi-arrow-left-right me-2"></i>Letzte Trades</span>
      <span class="text-muted small"><?= count($trades) ?> Einträge</span>
    </div>
    <div class="card-body p-0" style="max-height:450px;overflow-y:auto">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-3">Datum</th>
            <th>Aktion</th>
            <th>Ticker</th>
            <th>Name</th>
            <th>Sektor</th>
            <th class="text-end">Preis</th>
            <th class="text-end">Stück</th>
            <th class="text-end">Betrag</th>
            <th class="text-end pe-3">RSL</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($trades as $t): ?>
          <tr>
            <td class="ps-3 text-muted"><?= date('d.m.Y', strtotime($t['trade_date'])) ?></td>
            <td><strong class="<?= $t['action']==='BUY' ? 'buy-action' : 'sell-action' ?>">
              <?= $t['action'] === 'BUY' ? 'KAUF' : 'VERKAUF' ?>
            </strong></td>
            <td><strong><?= htmlspecialchars($t['ticker']) ?></strong></td>
            <td><small class="text-muted"><?= htmlspecialchars(substr($t['name']??'',0,25)) ?></small></td>
            <td><small class="text-muted"><?= htmlspecialchars(substr($t['sector']??'',0,20)) ?></small></td>
            <td class="text-end"><?= number_format($t['price'], 2) ?></td>
            <td class="text-end text-muted"><?= number_format($t['shares'], 2) ?></td>
            <td class="text-end"><?= number_format($t['gross_amount'], 0, ',', '.') ?></td>
            <td class="text-end pe-3 text-muted small"><?= $t['rsl_at_trade'] ? number_format($t['rsl_at_trade'], 3) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($results && !empty($chartData)): ?>
<script>
const labels    = <?= $chartDates ?>;
const portfolio = <?= $chartPortfolio ?>;
const benchmark = <?= $chartBenchmark ?>;

const ctx = document.getElementById('btChart').getContext('2d');
new Chart(ctx, {
  type: 'line',
  data: {
    labels,
    datasets: [
      {
        label: 'RSL Top-5 Portfolio',
        data: portfolio,
        borderColor: '#4ade80',
        backgroundColor: 'rgba(74,222,128,.08)',
        fill: true,
        tension: 0.3,
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
      },
      {
        label: 'S&P 500 (SPY)',
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
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { labels: { color: '#9aa0a6', usePointStyle: true } },
      tooltip: {
        backgroundColor: '#252830',
        borderColor: '#32363e',
        borderWidth: 1,
        titleColor: '#e8eaed',
        bodyColor: '#9aa0a6',
        callbacks: {
          label: ctx => {
            const v = ctx.parsed.y;
            return ` ${ctx.dataset.label}: ${v ? v.toLocaleString('de-DE', {style:'currency', currency:'USD', maximumFractionDigits:0}) : '—'}`;
          }
        }
      }
    },
    scales: {
      x: {
        ticks: { color: '#9aa0a6', maxTicksLimit: 12 },
        grid:  { color: 'rgba(255,255,255,.05)' }
      },
      y: {
        ticks: {
          color: '#9aa0a6',
          callback: v => '$' + v.toLocaleString('de-DE', {maximumFractionDigits: 0})
        },
        grid: { color: 'rgba(255,255,255,.05)' }
      }
    }
  }
});
</script>
<?php endif; ?>
</body>
</html>
