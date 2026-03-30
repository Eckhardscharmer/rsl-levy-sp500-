<?php
require_once __DIR__ . '/../src/RSLEngine.php';
$rsl = new RSLEngine();

$latestDate = $rsl->getLatestRankingDate();
$date       = $_GET['date'] ?? $latestDate;
$limit      = (int)($_GET['limit'] ?? 100);
$ranking    = $rsl->getFullRanking($date, $limit);
$top5       = $rsl->getCurrentTop5($date);
$top5Tickers = array_column($top5, 'ticker');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RSL Ranking — S&P 500</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root { --rsl-dark:#1a1d23; --rsl-card:#252830; --rsl-border:#32363e; --rsl-text:#e8eaed; --rsl-muted:#9aa0a6; }
  body { background: var(--rsl-dark); color: var(--rsl-text); }
  .navbar { background: #12141a !important; border-bottom: 1px solid var(--rsl-border); }
  .card { background: var(--rsl-card); border: 1px solid var(--rsl-border); border-radius: 12px; }
  .card-header { background: rgba(255,255,255,.04); border-bottom: 1px solid var(--rsl-border); font-weight: 600; }
  .table { --bs-table-bg: transparent; --bs-table-color: var(--rsl-text); }
  .table th { color: var(--rsl-muted); font-weight: 500; font-size: .8rem; text-transform: uppercase; border-color: var(--rsl-border); }
  .table td { border-color: var(--rsl-border); vertical-align: middle; }
  .table tbody tr:hover { background: rgba(255,255,255,.03); cursor: default; }
  .selected-row { background: rgba(25,135,84,.05) !important; }
  .selected-row td:first-child { border-left: 3px solid #198754; }
  .rsl-bar { height: 6px; border-radius: 3px; background: rgba(255,255,255,.1); }
  .rsl-bar-fill { height: 6px; border-radius: 3px; }
  .sector-badge { font-size: .7rem; background: rgba(255,255,255,.07); color: var(--rsl-muted); padding: .15em .55em; border-radius: 20px; white-space: nowrap; }
  .rank-badge { font-size: .75rem; color: var(--rsl-muted); font-weight: 600; }
  .rsl-value { font-family: monospace; font-weight: 700; }
  .filter-bar input, .filter-bar select { background: var(--rsl-card); border: 1px solid var(--rsl-border); color: var(--rsl-text); }
  .filter-bar input:focus, .filter-bar select:focus { background: var(--rsl-card); border-color: #0d6efd; color: var(--rsl-text); box-shadow: none; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-4">
    <a class="navbar-brand text-white" href="index.php"><i class="bi bi-graph-up-arrow text-success me-2"></i>RSL nach Levy</a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link" href="index.php"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <a class="nav-link active" href="ranking.php"><i class="bi bi-list-ol me-1"></i>Ranking</a>
      <a class="nav-link" href="backtest.php"><i class="bi bi-clock-history me-1"></i>Backtest</a>
      <a class="nav-link" href="portfolio.php"><i class="bi bi-briefcase me-1"></i>Portfolio</a>
      <a class="nav-link" href="admin.php"><i class="bi bi-gear me-1"></i>Admin</a>
    </div>
  </div>
</nav>

<div class="container-fluid px-4 py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">RSL Ranking</h4>
      <small class="text-muted">Datum: <?= $date ? date('d.m.Y', strtotime($date)) : '—' ?></small>
    </div>
    <form method="get" class="d-flex gap-2 filter-bar">
      <input type="date" name="date" class="form-control form-control-sm"
             value="<?= htmlspecialchars($date ?? '') ?>" max="<?= $latestDate ?>">
      <select name="limit" class="form-select form-select-sm" style="width:auto">
        <option value="50"  <?= $limit==50?'selected':'' ?>>Top 50</option>
        <option value="100" <?= $limit==100?'selected':'' ?>>Top 100</option>
        <option value="200" <?= $limit==200?'selected':'' ?>>Top 200</option>
        <option value="500" <?= $limit==500?'selected':'' ?>>Alle</option>
      </select>
      <button class="btn btn-sm btn-primary">Anzeigen</button>
    </form>
  </div>

  <!-- Sektor-Filter (clientseitig) -->
  <div class="mb-3 d-flex gap-2 flex-wrap">
    <input type="text" id="searchInput" class="form-control form-control-sm" style="max-width:200px"
           placeholder="Ticker oder Name suchen...">
    <select id="sectorFilter" class="form-select form-select-sm" style="width:auto">
      <option value="">Alle Sektoren</option>
      <?php
        $sectors = array_unique(array_column($ranking, 'sector'));
        sort($sectors);
        foreach ($sectors as $s): ?>
        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="form-check-label d-flex align-items-center gap-1 text-muted ms-2">
      <input type="checkbox" id="showSelectedOnly" class="form-check-input"> Nur Top-5
    </label>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span><i class="bi bi-list-ol me-2"></i><?= count($ranking) ?> Aktien</span>
      <span class="text-muted small">
        <span class="me-3"><i class="bi bi-star-fill text-warning me-1"></i>= ausgewählt (Top-5)</span>
        RSL = Kurs / SMA 26 Wochen
      </span>
    </div>
    <div class="card-body p-0">
      <table class="table table-hover mb-0" id="rankingTable">
        <thead>
          <tr>
            <th class="ps-3" style="width:60px">Rang</th>
            <th style="width:80px">Ticker</th>
            <th>Name</th>
            <th>Sektor</th>
            <th class="text-end">Kurs</th>
            <th class="text-end">SMA 26W</th>
            <th class="text-end" style="width:180px">RSL</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $maxRsl = !empty($ranking) ? max(array_column($ranking, 'rsl')) : 2;
        foreach ($ranking as $r):
          $isSelected = in_array($r['ticker'], $top5Tickers);
          $rslPct = min(100, ($r['rsl'] / max($maxRsl, 0.01)) * 100);
          $barColor = $r['rsl'] >= 1.3 ? '#4ade80' : ($r['rsl'] >= 1.0 ? '#fbbf24' : '#f87171');
        ?>
          <tr class="<?= $isSelected ? 'selected-row' : '' ?>"
              data-ticker="<?= $r['ticker'] ?>"
              data-name="<?= htmlspecialchars(strtolower($r['name'] ?? '')) ?>"
              data-sector="<?= htmlspecialchars($r['sector'] ?? '') ?>"
              data-selected="<?= $isSelected ? '1' : '0' ?>">
            <td class="ps-3">
              <?php if ($isSelected): ?><i class="bi bi-star-fill text-warning me-1" style="font-size:.75rem"></i><?php endif; ?>
              <span class="rank-badge"><?= $r['rank_overall'] ?></span>
            </td>
            <td><strong><?= htmlspecialchars($r['ticker']) ?></strong></td>
            <td><small class="text-muted"><?= htmlspecialchars(substr($r['name'] ?? '', 0, 35)) ?></small></td>
            <td><span class="sector-badge"><?= htmlspecialchars($r['sector'] ?? '') ?></span></td>
            <td class="text-end"><?= number_format($r['current_price'], 2) ?></td>
            <td class="text-end text-muted small"><?= number_format($r['sma_26w'], 2) ?></td>
            <td class="text-end pe-3">
              <div class="d-flex align-items-center justify-content-end gap-2">
                <div style="width:80px">
                  <div class="rsl-bar">
                    <div class="rsl-bar-fill" style="width:<?= $rslPct ?>%;background:<?= $barColor ?>"></div>
                  </div>
                </div>
                <span class="rsl-value" style="color:<?= $barColor ?>;min-width:52px">
                  <?= number_format($r['rsl'], 4) ?>
                </span>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const searchInput    = document.getElementById('searchInput');
const sectorFilter   = document.getElementById('sectorFilter');
const showSelectedOnly = document.getElementById('showSelectedOnly');
const rows           = document.querySelectorAll('#rankingTable tbody tr');

function applyFilter() {
  const q        = searchInput.value.toLowerCase();
  const sector   = sectorFilter.value;
  const selOnly  = showSelectedOnly.checked;

  rows.forEach(row => {
    const ticker  = row.dataset.ticker.toLowerCase();
    const name    = row.dataset.name;
    const rowSector = row.dataset.sector;
    const selected = row.dataset.selected === '1';

    const matchQ      = !q || ticker.includes(q) || name.includes(q);
    const matchSector = !sector || rowSector === sector;
    const matchSel    = !selOnly || selected;

    row.style.display = (matchQ && matchSector && matchSel) ? '' : 'none';
  });
}

searchInput.addEventListener('input', applyFilter);
sectorFilter.addEventListener('change', applyFilter);
showSelectedOnly.addEventListener('change', applyFilter);
</script>
</body>
</html>
