<?php
/**
 * Gemeinsame Navbar-Komponente mit Universe-Switcher
 *
 * Erwartet:
 *   $activePage  (string) — 'landing'|'index'|'simulation'|'ranking'|'backtest'
 *   $universe    (string) — 'sp500'|'dax'|'hdax'|'etf'
 *
 * Optionale Variablen:
 *   $currentEurUsd (float) — aktueller EUR/USD-Kurs
 */

$universe     = $universe ?? 'sp500';
$activePage   = $activePage ?? '';
$eurUsd       = $currentEurUsd ?? 1.10;
$isDax        = ($universe === 'dax');
$isHdax       = ($universe === 'hdax');
$isEtf        = ($universe === 'etf');
$isEurUniverse = ($isDax || $isHdax || $isEtf);

// URL-Parameter für alle Links (universe wird immer mitgegeben)
function navUrl(string $page, string $universe, array $extra = []): string {
    $params = array_merge(['universe' => $universe], $extra);
    return $page . '?' . http_build_query($params);
}
?>
<style>
  .universe-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; gap: 2px; }
  .univ-btn { background: transparent; border: none; color: rgba(255,255,255,.5); font-size: .78rem; font-weight: 700; padding: .22rem .75rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; display: flex; align-items: center; gap: .3rem; white-space: nowrap; }
  .univ-btn:hover { color: #fff; background: rgba(255,255,255,.12); }
  .univ-btn.active { color: #fff; box-shadow: 0 0 0 2px rgba(255,255,255,.25); }
  .univ-btn.sp500.active { background: #1d4ed8; }
  .univ-btn.dax.active   { background: #b91c1c; }
  .univ-btn.hdax.active  { background: #c2410c; }
  .univ-btn.etf.active   { background: #065f46; }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; width: 100%; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }
  .flag-icon { font-size: 1rem; line-height: 1; }
  /* Mobile: Navbar-Brand kürzen, Buttons kompakter */
  @media (max-width: 575px) {
    .navbar-brand-text { display: none; }
    .univ-btn { padding: .22rem .45rem; font-size: .72rem; }
    .univ-btn .univ-label { display: none; }
    .cur-btn { padding: .2rem .5rem; font-size: .72rem; }
  }
</style>

<div style="background:#0a0f1e;border-bottom:1px solid rgba(255,255,255,.07);padding:.4rem 1.5rem;">
  <a href="https://investsignal.de"
     style="display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;font-weight:600;color:#64748b;text-decoration:none;transition:color .15s;"
     onmouseover="this.style.color='#10b981'" onmouseout="this.style.color='#64748b'">
    <svg width="14" height="11" viewBox="0 0 28 22" fill="none">
      <polyline points="2,18 8,12 14,15 22,5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      <polyline points="18,4 23,4 23,9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    ← investsignal.de
  </a>
</div>
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold" href="<?= navUrl('landing.php', $universe) ?>">
      <svg width="28" height="22" viewBox="0 0 28 22" fill="none" style="margin-right:9px;flex-shrink:0;">
        <polyline points="2,18 8,12 14,15 22,5" stroke="#10b981" stroke-width="2.2"
          stroke-linecap="round" stroke-linejoin="round"/>
        <polyline points="18,4 23,4 23,9" stroke="#10b981" stroke-width="2.2"
          stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <span class="navbar-brand-text">Relative <span style="color:#10b981;margin-left:4px;">Stärke</span></span>
    </a>

    <!-- Schalter: direkt neben Brand, immer sichtbar, schiebt Navlinks nach rechts -->
    <div class="d-flex flex-column align-items-stretch gap-1 ms-3 me-auto">
      <div class="universe-toggle">
        <button class="univ-btn sp500 <?= (!$isDax && !$isHdax && !$isEtf) ? 'active' : '' ?>" onclick="switchUniverse('sp500')" title="S&P 500 — US-Aktien">
          <img src="https://flagcdn.com/16x12/us.png" width="16" height="12" style="vertical-align:middle;"> <span class="univ-label">S&amp;P 500</span>
        </button>
        <button class="univ-btn dax <?= $isDax ? 'active' : '' ?>" onclick="switchUniverse('dax')" title="DAX 40 — Größte deutsche Aktien">
          <img src="https://flagcdn.com/16x12/de.png" width="16" height="12" style="vertical-align:middle;"> <span class="univ-label">DAX</span>
        </button>
        <button class="univ-btn hdax <?= $isHdax ? 'active' : '' ?>" onclick="switchUniverse('hdax')" title="HDAX 100 — Top 100 deutsche Aktien">
          <img src="https://flagcdn.com/16x12/de.png" width="16" height="12" style="vertical-align:middle;"> <span class="univ-label">HDAX</span>
        </button>
        <button class="univ-btn etf <?= $isEtf ? 'active' : '' ?>" onclick="switchUniverse('etf')" title="ETF — Multi-Asset Momentum">
          <span class="flag-icon">🌐</span> <span class="univ-label">ETF</span>
        </button>
      </div>
      <?php if (!$isEurUniverse): ?>
      <div class="currency-toggle">
        <button class="cur-btn" id="btn-eur" style="flex:1;">€ EUR</button>
        <button class="cur-btn" id="btn-usd" style="flex:1;">$ USD</button>
      </div>
      <?php else: ?>
      <div style="height:26px;"></div>
      <?php endif; ?>
    </div>

    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <div class="navbar-nav ms-auto">
        <a class="nav-link <?= $activePage === 'landing'     ? 'active' : '' ?>" href="<?= navUrl('landing.php',    $universe) ?>"><i class="bi bi-house me-1"></i>Start</a>
        <a class="nav-link <?= $activePage === 'index'       ? 'active' : '' ?>" href="<?= navUrl('index.php',      $universe) ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a class="nav-link <?= $activePage === 'simulation'  ? 'active' : '' ?>" href="<?= navUrl('simulation.php', $universe) ?>"><i class="bi bi-sliders me-1"></i>Annahmen</a>
        <a class="nav-link <?= $activePage === 'ranking'     ? 'active' : '' ?>" href="<?= navUrl('ranking.php',    $universe) ?>"><i class="bi bi-list-ol me-1"></i>Ranking</a>
        <a class="nav-link <?= $activePage === 'backtest'    ? 'active' : '' ?>" href="<?= navUrl('backtest.php',   $universe) ?>"><i class="bi bi-clock-history me-1"></i>Backtest</a>
        <a class="nav-link <?= $activePage === 'compare'     ? 'active' : '' ?>" href="compare.php"><i class="bi bi-bar-chart-line me-1"></i>Vergleich</a>
      </div>
    </div>
  </div>
</nav>

<script>
// Universe-Switcher: localStorage setzen und auf aktuelle Seite mit neuem Universe weiterleiten
function switchUniverse(universe) {
  localStorage.setItem('universe', universe);
  const url = new URL(window.location.href);
  url.searchParams.set('universe', universe);
  // Datum/Kapital beibehalten, aber universe neu setzen
  window.location.href = url.toString();
}

// Universe aus localStorage lesen und ggf. weiterleiten
(function() {
  const stored = localStorage.getItem('universe') || 'sp500';
  const current = '<?= $universe ?>';
  if (stored !== current) {
    // Nur weiterleiten wenn kein expliziter universe-Parameter in der URL
    const urlParams = new URLSearchParams(window.location.search);
    if (!urlParams.has('universe')) {
      switchUniverse(stored);
    } else {
      // URL hat expliziten Parameter → localStorage aktualisieren
      localStorage.setItem('universe', current);
    }
  }
})();

<?php if (!$isEurUniverse): ?>
// Währungs-Toggle (nur S&P 500)
const EUR_USD = <?= json_encode($eurUsd) ?>;
function applyCurrency() {
  const cur = localStorage.getItem('currency') || 'EUR';
  document.getElementById('btn-usd')?.classList.toggle('active', cur === 'USD');
  document.getElementById('btn-eur')?.classList.toggle('active', cur === 'EUR');
}
document.getElementById('btn-usd')?.addEventListener('click', () => {
  // Falls Seite eigene applyCurrencyToggle hat (backtest.php), diese nutzen — sonst reload
  if (typeof applyCurrencyToggle === 'function') { applyCurrencyToggle('USD'); }
  else { localStorage.setItem('currency', 'USD'); location.reload(); }
});
document.getElementById('btn-eur')?.addEventListener('click', () => {
  if (typeof applyCurrencyToggle === 'function') { applyCurrencyToggle('EUR'); }
  else { localStorage.setItem('currency', 'EUR'); location.reload(); }
});
document.addEventListener('DOMContentLoaded', applyCurrency);
<?php endif; ?>
</script>
