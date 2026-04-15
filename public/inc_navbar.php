<?php
/**
 * Gemeinsame Navbar-Komponente mit Universe-Switcher
 *
 * Erwartet:
 *   $activePage  (string) — 'landing'|'index'|'simulation'|'ranking'|'backtest'
 *   $universe    (string) — 'sp500'|'dax'
 *
 * Optionale Variablen:
 *   $currentEurUsd (float) — aktueller EUR/USD-Kurs
 */

$universe     = $universe ?? 'sp500';
$activePage   = $activePage ?? '';
$eurUsd       = $currentEurUsd ?? 1.10;
$isDax        = ($universe === 'dax');

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
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; width: 100%; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }
  .flag-icon { font-size: 1rem; line-height: 1; }
</style>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold" href="<?= navUrl('index.php', $universe) ?>">
      <i class="bi bi-graph-up-arrow text-success me-2"></i>RSL nach Levy
    </a>
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
      </div>

      <div class="d-flex flex-column align-items-stretch gap-1 ms-lg-3 mt-2 mt-lg-0 mb-2 mb-lg-0">

        <!-- Universe-Switcher -->
        <div class="universe-toggle">
          <button class="univ-btn sp500 <?= !$isDax ? 'active' : '' ?>" onclick="switchUniverse('sp500')" title="S&P 500 — US-Aktien">
            <span class="flag-icon">🇺🇸</span> S&amp;P 500
          </button>
          <button class="univ-btn dax <?= $isDax ? 'active' : '' ?>" onclick="switchUniverse('dax')" title="DAX — Deutsche Aktien">
            <span class="flag-icon">🇩🇪</span> DAX
          </button>
        </div>

        <!-- Währungs-Toggle (nur für S&P 500, gleiche Breite wie Universe-Switcher) -->
        <?php if (!$isDax): ?>
        <div class="currency-toggle" style="width:100%;">
          <button class="cur-btn" id="btn-usd" style="flex:1;">$ USD</button>
          <button class="cur-btn" id="btn-eur" style="flex:1;">€ EUR</button>
        </div>
        <?php else: ?>
        <div style="height:28px;"></div>
        <?php endif; ?>

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

<?php if (!$isDax): ?>
// Währungs-Toggle (nur S&P 500)
const EUR_USD = <?= json_encode($eurUsd) ?>;
function applyCurrency() {
  const cur = localStorage.getItem('currency') || 'USD';
  document.getElementById('btn-usd')?.classList.toggle('active', cur === 'USD');
  document.getElementById('btn-eur')?.classList.toggle('active', cur === 'EUR');
}
document.getElementById('btn-usd')?.addEventListener('click', () => { localStorage.setItem('currency', 'USD'); location.reload(); });
document.getElementById('btn-eur')?.addEventListener('click', () => { localStorage.setItem('currency', 'EUR'); location.reload(); });
document.addEventListener('DOMContentLoaded', applyCurrency);
<?php endif; ?>
</script>
