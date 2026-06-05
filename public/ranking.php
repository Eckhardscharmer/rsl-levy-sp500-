<?php
require_once __DIR__ . '/../src/RSLEngine.php';
$rsl = new RSLEngine();
$db  = getDB();

$universe = $_GET['universe'] ?? 'sp500';
if (!in_array($universe, ['sp500', 'dax', 'etf'])) $universe = 'sp500';
$isDax    = ($universe === 'dax');
$isEtf    = ($universe === 'etf');

$latestDate = $rsl->getLatestRankingDate($universe);
$date       = $_GET['date'] ?? $latestDate;
$limit      = (int)($_GET['limit'] ?? ($isEtf ? 8 : 100));

// ETF: alle Instrumente laden inkl. sma_200
if ($isEtf) {
    $stmtEtf = $db->prepare(
        'SELECT r.ticker, COALESCE(s.name, r.ticker) AS name, r.sector,
                r.current_price, r.sma_26w AS sma_27w, r.sma_200, r.rsl, r.rank_overall, r.is_selected
         FROM rsl_rankings r
         LEFT JOIN stocks s ON s.ticker = r.ticker
         WHERE r.ranking_date = ? AND r.universe = "etf"
         ORDER BY r.rank_overall ASC'
    );
    $stmtEtf->execute([$date]);
    $ranking = $stmtEtf->fetchAll(PDO::FETCH_ASSOC);
} else {
    $ranking = $rsl->getFullRanking($date, $limit, $universe);
}
// Portfolio-Markierung erfolgt client-seitig via localStorage (sim_portfolio_tickers)
$db            = getDB();
$currentEurUsd = (float)($db->query("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date DESC LIMIT 1")->fetchColumn() ?: 1.10);
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
  body { background: #f5f7fa; }
  .navbar { background: #0f172a !important; border-bottom: 1px solid #1e2d4a; box-shadow: 0 2px 12px rgba(0,0,0,.3); min-height: 56px; }
  .navbar .container-fluid { min-height: 56px; height: auto; }
  .navbar .navbar-brand { color: #fff !important; font-weight: 700; padding: 0; }
  .navbar .nav-link { color: rgba(255,255,255,.6) !important; padding: .375rem .65rem !important; font-size: .875rem; }
  .navbar .nav-link:hover { color: #fff !important; }
  .card { background: #ffffff; border: 1px solid #dee2e6; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
  .card-header { background: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 600; }
  .table { --bs-table-bg: transparent; }
  .table th { color: #6c757d; font-weight: 500; font-size: .76rem; text-transform: uppercase; letter-spacing: .4px; border-color: #dee2e6; }
  .table td { border-color: #dee2e6; vertical-align: middle; font-size: .82rem; }
  .table tbody tr:hover { background: #f8f9fa; cursor: default; }
  .portfolio-row { background: rgba(37,99,235,.04) !important; }
  .portfolio-row td:first-child { border-left: 3px solid #2563eb; }
  .portfolio-badge { display:inline-flex; align-items:center; gap:.3rem;
    background:#dbeafe; color:#1d4ed8; border-radius:5px;
    padding:.1em .45em; font-size:.68rem; font-weight:700;
    letter-spacing:.02em; white-space:nowrap; margin-right:.35rem; }
  .rsl-bar { height: 6px; border-radius: 3px; background: #e9ecef; }
  .rsl-bar-fill { height: 6px; border-radius: 3px; }
  .sector-badge { font-size: .82rem; background: #f0f2f5; color: #6c757d; padding: .15em .55em; border-radius: 20px; white-space: nowrap; }
  .rsl-value { font-size: .82rem; font-weight: 700; }
  .nav-link.active { color: #fff !important; font-weight: 600; }
  html { overflow-y: scroll; }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }
</style>
</head>
<body>
<?php $activePage = 'ranking'; include __DIR__ . '/inc_navbar.php'; ?>

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

<?php if ($isEtf): ?>
  <!-- ETF: kompakte Anzeige, kein Sektor-Filter -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-list-ol me-2"></i>8 Anlageklassen &nbsp;·&nbsp; Monatliches Rebalancing</span>
      <span class="text-muted small">
        <span class="portfolio-badge me-2" style="background:#d1fae5;color:#065f46;">● Top 3</span>= Kaufsignal (RSL-Rang + SMA200-Filter) &nbsp;·&nbsp;
        RSL = Kurs / SMA 27 Wochen
      </span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-3" style="width:60px">Rang</th>
            <th style="width:100px">Index-Ticker</th>
            <th>Anlageklasse</th>
            <th>ETF (reale Umsetzung)</th>
            <th class="text-end">Kurs</th>
            <th class="text-end">SMA 27W</th>
            <th class="text-end">SMA 200</th>
            <th class="text-center">Trend</th>
            <th class="text-end" style="width:160px">RSL</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $maxRsl = !empty($ranking) ? max(array_column($ranking, 'rsl')) : 2;
        $etfMap = [
            '^GSPC'  => 'SXR8',   '^NDX'  => 'EQQQ',  '^STOXX' => 'EXSA',
            '^N225'  => 'DBXJ',   'EEM'   => 'XMME',  'GC=F'   => 'Xetra-Gold',
            'AGG'    => 'IGLO',   'SHY'   => 'Geldmarkt-ETF',
        ];
        foreach ($ranking as $r):
          $rslPct    = min(100, ($r['rsl'] / max($maxRsl, 0.01)) * 100);
          $barColor  = $r['rsl'] >= 1.1 ? '#4ade80' : ($r['rsl'] >= 1.0 ? '#fbbf24' : '#f87171');
          $aboveSma  = $r['sma_200'] && $r['current_price'] > $r['sma_200'];
          $isTop3    = $r['is_selected'] == 1;
          $etfTicker = $etfMap[$r['ticker']] ?? '';
        ?>
          <tr class="<?= $isTop3 ? 'portfolio-row' : '' ?>">
            <td class="ps-3 fw-600">
              <?php if ($isTop3): ?>
              <span class="portfolio-badge" style="background:#d1fae5;color:#065f46;">● TOP 3</span>
              <?php else: ?>
              <?= $r['rank_overall'] ?>
              <?php endif; ?>
            </td>
            <td style="font-weight:700;font-size:.85rem;"><?= htmlspecialchars($r['ticker']) ?></td>
            <td><?= htmlspecialchars($r['name'] ?? '') ?></td>
            <td class="text-muted" style="font-size:.8rem;"><?= htmlspecialchars($etfTicker) ?></td>
            <td class="text-end"><?= number_format($r['current_price'], 2) ?></td>
            <td class="text-end text-muted"><?= number_format($r['sma_27w'] ?? $r['sma_26w'] ?? 0, 2) ?></td>
            <td class="text-end text-muted"><?= $r['sma_200'] ? number_format($r['sma_200'], 2) : '—' ?></td>
            <td class="text-center">
              <?php if ($aboveSma): ?>
                <span style="color:#4ade80;font-size:1.1rem;" title="Kurs &gt; SMA200 — investierbar">▲</span>
              <?php else: ?>
                <span style="color:#f87171;font-size:1.1rem;" title="Kurs &lt; SMA200 — nicht investierbar">▼</span>
              <?php endif; ?>
            </td>
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
<?php else: ?>
  <!-- Sektor-Filter (clientseitig, nur S&P 500 / DAX) -->
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
        <span class="portfolio-badge me-2">● Portfolio</span>= aktuelle Simulation &nbsp;·&nbsp;
        RSL = Kurs / SMA 26 Wochen
      </span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
      <table class="table table-hover mb-0" id="rankingTable">
        <thead>
          <tr>
            <th class="ps-3" style="width:60px">Rang</th>
            <th style="width:80px">Ticker</th>
            <th>Name</th>
            <th>Sektor</th>
            <th class="text-end" id="th-kurs">Kurs (<?= $isDax ? 'EUR' : 'USD' ?>)</th>
            <th class="text-end" id="th-sma">SMA 26W (<?= $isDax ? 'EUR' : 'USD' ?>)</th>
            <th class="text-end" style="width:180px">RSL</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $maxRsl = !empty($ranking) ? max(array_column($ranking, 'rsl')) : 2;
        foreach ($ranking as $r):
          $rslPct = min(100, ($r['rsl'] / max($maxRsl, 0.01)) * 100);
          $barColor = $r['rsl'] >= 1.3 ? '#4ade80' : ($r['rsl'] >= 1.0 ? '#fbbf24' : '#f87171');
        ?>
          <tr data-ticker="<?= $r['ticker'] ?>"
              data-name="<?= htmlspecialchars(strtolower($r['name'] ?? '')) ?>"
              data-sector="<?= htmlspecialchars($r['sector'] ?? '') ?>"
              data-selected="0">
            <td class="ps-3 text-muted fw-600">
              <?= $r['rank_overall'] ?>
            </td>
            <td style="font-weight:600;"><?= htmlspecialchars($r['ticker']) ?></td>
            <td class="text-muted"><?= htmlspecialchars(substr($r['name'] ?? '', 0, 35)) ?></td>
            <td><span class="sector-badge"><?= htmlspecialchars($r['sector'] ?? '') ?></span></td>
            <td class="text-end price-cell" data-usd="<?= $r['current_price'] ?>"><?= number_format($r['current_price'], 2) ?></td>
            <td class="text-end text-muted price-cell" data-usd="<?= $r['sma_26w'] ?>"><?= number_format($r['sma_26w'], 2) ?></td>
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
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Currency toggle
const _isDax       = <?= $isDax ? 'true' : 'false' ?>;
const _isEtf       = <?= $isEtf ? 'true' : 'false' ?>;
const _currency    = (_isDax || _isEtf) ? 'EUR' : (localStorage.getItem('currency') || 'USD');
const currentEurUsd = <?= round($currentEurUsd, 6) ?>;
document.getElementById('btn-usd')?.classList.toggle('active', _currency === 'USD');
document.getElementById('btn-eur')?.classList.toggle('active', _currency === 'EUR');
document.getElementById('btn-usd')?.addEventListener('click', () => { localStorage.setItem('currency', 'USD'); location.reload(); });
document.getElementById('btn-eur')?.addEventListener('click', () => { localStorage.setItem('currency', 'EUR'); location.reload(); });

// S&P 500 mit EUR-Anzeige: USD-Kurse umrechnen (nicht für ETF/DAX — deren Preise sind Indexwerte)
if (!_isDax && !_isEtf && _currency === 'EUR') {
  const thKurs = document.getElementById('th-kurs'); if (thKurs) thKurs.textContent = 'Kurs (EUR)';
  const thSma  = document.getElementById('th-sma');  if (thSma)  thSma.textContent  = 'SMA 26W (EUR)';
  document.querySelectorAll('.price-cell').forEach(el => {
    const usd = parseFloat(el.dataset.usd);
    if (!isNaN(usd)) el.textContent = (usd / currentEurUsd).toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2});
  });
}

const searchInput      = document.getElementById('searchInput');
const sectorFilter     = document.getElementById('sectorFilter');
const showSelectedOnly = document.getElementById('showSelectedOnly');
const rows             = document.querySelectorAll('#rankingTable tbody tr');

// Portfolio-Ticker aus localStorage (gespeichert von simulation.php, universe-spezifisch)
const _universe = '<?= $universe ?>';
const storedTickers = JSON.parse(localStorage.getItem('sim_portfolio_tickers_' + _universe) || '[]');
const portfolioSet  = new Set(storedTickers);

// Zeilen markieren
rows.forEach(row => {
  const ticker = row.dataset.ticker;
  if (portfolioSet.has(ticker)) {
    row.classList.add('portfolio-row');
    row.dataset.selected = '1';
    // Badge vor Rangzahl einfügen
    const rankCell = row.querySelector('td:first-child');
    const badge = document.createElement('span');
    badge.className = 'portfolio-badge';
    badge.textContent = '● Portfolio';
    rankCell.prepend(badge);
  }
});

function applyFilter() {
  const q       = searchInput.value.toLowerCase();
  const sector  = sectorFilter.value;
  const selOnly = showSelectedOnly.checked;

  rows.forEach(row => {
    const ticker    = row.dataset.ticker.toLowerCase();
    const name      = row.dataset.name;
    const rowSector = row.dataset.sector;
    const selected  = row.dataset.selected === '1';

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
