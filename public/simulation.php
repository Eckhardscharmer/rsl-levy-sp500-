<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

// ── Universe ───────────────────────────────────────────────────────────────
$universe = $_GET['universe'] ?? 'sp500';
if (!in_array($universe, ['sp500', 'dax'])) $universe = 'sp500';
$isDax    = ($universe === 'dax');

// ── M&A-Flags (aktuell aktive Übernahme-Kandidaten) ────────────────────────
$maFlagged = [];
foreach ($db->query('SELECT ticker, headline FROM m_and_a_flags WHERE is_active = 1')
             ->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $maFlagged[$row['ticker']] = $row['headline'];
}

// ── Parameter ──────────────────────────────────────────────────────────────
$startCapital = max(1000, (float)($_GET['capital'] ?? 50000));
$startDate    = $_GET['start_date'] ?? '2024-01-01';
$minDate      = '2010-01-04';
$maxDate      = $db->query("SELECT MAX(ranking_date) FROM rsl_rankings WHERE universe='$universe'")->fetchColumn() ?: date('Y-m-d');
if ($startDate < $minDate) $startDate = $minDate;
if ($startDate > $maxDate) $startDate = $maxDate;

// ── Datumsformatierung (Deutsch) ───────────────────────────────────────────
function dateDe(string $date): string {
    static $months = ['Januar','Februar','März','April','Mai','Juni',
                      'Juli','August','September','Oktober','November','Dezember'];
    $d = new DateTime($date);
    return $d->format('j') . '. ' . $months[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}

// ── Slider-Werte für JavaScript ────────────────────────────────────────────
$minTs       = strtotime($minDate);
$maxTs       = strtotime($maxDate);
$currentTs   = strtotime($startDate);
$sliderMaxDays  = (int)(($maxTs - $minTs) / 86400);
$sliderCurDays  = (int)(($currentTs - $minTs) / 86400);

// ── Alle Rankings ab Startdatum laden (eine Query) ──────────────────────────
$stmt = $db->prepare(
    'SELECT r.ranking_date, r.ticker, r.sector, r.current_price, r.rsl, r.rank_overall,
            COALESCE(s.name, r.ticker) AS company
     FROM rsl_rankings r
     LEFT JOIN stocks s ON s.ticker = r.ticker
     WHERE r.ranking_date >= ? AND r.universe = ?
     ORDER BY r.ranking_date ASC, r.rank_overall ASC'
);
$stmt->execute([$startDate, $universe]);
$allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nach Datum gruppieren
$byDate  = [];
foreach ($allRows as $row) {
    $byDate[$row['ranking_date']][] = $row;
}
$sundays = array_keys($byDate);

// ── Simulation (identische Logik wie Backtest) ─────────────────────────────
// HOLD_RANK: dynamisch je nach Universe und Datum (wird im Loop neu berechnet)
const TOP_N_SIM = 5;

$cash                = $startCapital;
$holdings            = [];   // ticker → [shares, buy_price, sector, rsl_buy, company]
$snapshots           = [];
$lastSnapshotMktVals = [];   // ticker → mkt_val aus letztem Snapshot (für realisierten GuV)

foreach ($sundays as $i => $sunday) {
    $weekRankings = $byDate[$sunday];
    $rankByTicker = array_column($weekRankings, null, 'ticker');

    $saleProceeds   = [];
    $exitedThisWeek = [];  // ticker → ['net_proceeds', 'realized_pnl']

    // VERKAUF: Rang > Schwelle oder nicht mehr im Index
    $holdRank = $isDax ? ($sunday >= '2021-09-20' ? 10 : 7) : 125;
    foreach (array_keys($holdings) as $ticker) {
        $rank = isset($rankByTicker[$ticker])
            ? (int)$rankByTicker[$ticker]['rank_overall']
            : PHP_INT_MAX;
        if ($rank > $holdRank) {
            $price  = (float)($rankByTicker[$ticker]['current_price'] ?? $holdings[$ticker]['buy_price']);
            $shares = $holdings[$ticker]['shares'];
            $gross  = $shares * $price;
            $net    = $gross;
            $cash  += $net;
            $saleProceeds[] = $net;

            // Realisierter GuV = Verkaufserlös minus Wert im letzten Snapshot
            $prevVal = $lastSnapshotMktVals[$ticker] ?? ($holdings[$ticker]['buy_price'] * $shares);
            $exitedThisWeek[$ticker] = [
                'net_proceeds'  => $net,
                'realized_pnl'  => $net - $prevVal,
            ];
            unset($holdings[$ticker]);
        }
    }

    // KAUF: Slot-Kapital (kein Nachschuss, Erlös wird 1:1 reinvestiert)
    $vacancies   = TOP_N_SIM - count($holdings);
    $heldSectors = array_column(array_values($holdings), 'sector');
    $cashPerSlot = $vacancies > 0 ? $cash / $vacancies : 0;
    $newThisWeek = [];

    $isCurrentWeek = ($sunday === end($sundays));
    foreach ($weekRankings as $stock) {
        if ($vacancies <= 0) break;
        if (isset($holdings[$stock['ticker']])) continue;
        // M&A-Filter: nur für die aktuelle (letzte) Woche anwenden
        if ($isCurrentWeek && isset($maFlagged[$stock['ticker']])) continue;
        $sector = $stock['sector'] ?? 'Unknown';
        if (in_array($sector, $heldSectors)) continue;
        $price = (float)$stock['current_price'];
        if ($price <= 0) continue;

        $slotBudget = !empty($saleProceeds) ? array_shift($saleProceeds) : $cashPerSlot;
        if ($slotBudget < 1) continue;

        $gross  = $slotBudget;
        $shares = $gross / $price;
        $cash  -= $slotBudget;

        $holdings[$stock['ticker']] = [
            'shares'    => $shares,
            'buy_price' => $price,
            'sector'    => $sector,
            'rsl_buy'   => (float)$stock['rsl'],
            'company'   => $stock['company'],
        ];
        $newThisWeek[] = $stock['ticker'];
        $heldSectors[] = $sector;
        $vacancies--;
    }

    // Portfolio-Wert & angereicherte Holdings für Snapshot
    $invested = 0;
    $snap     = [];
    foreach ($holdings as $ticker => $h) {
        $price   = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
        $mktVal  = $h['shares'] * $price;
        $invested += $mktVal;
        $snap[$ticker] = [
            'ticker'  => $ticker,
            'company' => $rankByTicker[$ticker]['company'] ?? $h['company'],
            'sector'  => $h['sector'],
            'mkt_val' => $mktVal,
            'rsl'     => (float)($rankByTicker[$ticker]['rsl'] ?? $h['rsl_buy']),
            'rank'    => (int)($rankByTicker[$ticker]['rank_overall'] ?? 999),
        ];
    }
    $portfolioValue = $cash + $invested;

    foreach ($snap as $t => &$s) {
        $s['weight'] = $portfolioValue > 0 ? $s['mkt_val'] / $portfolioValue * 100 : 0;
    }
    unset($s);
    uasort($snap, fn($a, $b) => $b['rsl'] <=> $a['rsl']);

    // lastSnapshotMktVals für nächste Woche aktualisieren
    foreach ($snap as $ticker => $s) {
        $lastSnapshotMktVals[$ticker] = $s['mkt_val'];
    }

    $isLast = ($i === count($sundays) - 1);
    if (!empty($newThisWeek) || !empty($exitedThisWeek) || $isLast) {
        $snapshots[] = [
            'date'      => $sunday,
            'holdings'  => $snap,
            'new'       => $newThisWeek,
            'exited'    => $exitedThisWeek,   // jetzt dict mit realized_pnl
            'pv'        => $portfolioValue,
            'no_change' => empty($newThisWeek) && empty($exitedThisWeek),
        ];
    }
}

$simLastPv = !empty($snapshots) ? (float)end($snapshots)['pv'] : $startCapital;
$snapshots = array_reverse($snapshots);   // neueste zuerst

// Rendite und Kapitalstand direkt aus der Simulation (frischer Start ab $startDate)
$changePct    = $startCapital > 0 ? ($simLastPv - $startCapital) / $startCapital * 100 : 0;
$finalCapital = $simLastPv;

// EUR/USD: aktuell (für Kapitalstand-Anzeige), erster und letzter Simulations-Sonntag (für %-Rendite)
// → identische Referenzdaten wie Backtest (dort: eurSlice[0] und eurSlice[last])
$currentEurUsd  = (float)($db->query("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date DESC LIMIT 1")->fetchColumn() ?: 1.10);
$firstSimSunday = $sundays[0] ?? $startDate;
$lastSimSunday  = end($sundays) ?: $startDate;
$stmtEur = $db->prepare("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' AND price_date <= ? ORDER BY price_date DESC LIMIT 1");
$stmtEur->execute([$firstSimSunday]);
$startEurUsd = (float)($stmtEur->fetchColumn() ?: $currentEurUsd);
$stmtEur->execute([$lastSimSunday]);
$endEurUsd   = (float)($stmtEur->fetchColumn() ?: $currentEurUsd);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Simulation — RSL nach Levy</title>
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
  .card-header { background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
  .nav-link.active { color: #fff !important; font-weight: 600; }
  html { overflow-y: scroll; }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }

  /* Sidebar */
  .sidebar-sticky { position: sticky; top: 1.5rem; }
  .kpi-label { font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .04em; margin-bottom: .15rem; }
  .kpi-value { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
  .kpi-box { padding: 1rem 1.1rem; border-radius: 10px; background: #f8f9fa; border: 1px solid #dee2e6; }

  /* Portfolio-Karten */
  .portfolio-card { margin-bottom: 1rem; }
  .portfolio-header { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; padding: .65rem 1rem; border-bottom: 1px solid #dee2e6; }
  .portfolio-date { font-weight: 700; font-size: .92rem; color: #212529; }
  .badge-positions { background: #e9ecef; color: #495057; font-size: .72rem; font-weight: 600; padding: .28em .65em; border-radius: 20px; }
  .badge-new-exit { background: #d1fae5; color: #065f46; font-size: .72rem; font-weight: 600; padding: .28em .65em; border-radius: 20px; }
  .badge-exit-only { background: #fee2e2; color: #991b1b; font-size: .72rem; font-weight: 600; padding: .28em .65em; border-radius: 20px; }
  .badge-nochange { background: #f0f2f5; color: #9ca3af; font-size: .72rem; font-weight: 600; padding: .28em .65em; border-radius: 20px; }
  .change-badges { margin-left: auto; display: flex; gap: .35rem; }

  .ptable { font-size: .84rem; margin-bottom: 0; }
  .ptable th { font-size: .70rem; text-transform: uppercase; color: #6c757d; font-weight: 600; letter-spacing: .04em; border-color: #dee2e6 !important; padding: .45rem .75rem; background: #fafafa; }
  .ptable td { border-color: #dee2e6 !important; vertical-align: middle; padding: .45rem .75rem; }

  .row-normal { background: #fff; }
  .row-new    { background: rgba(22,163,74,.06); }
  .row-exited { background: rgba(239,68,68,.04); color: #9ca3af; }

  .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
  .dot-new   { background: #16a34a; }
  .dot-exit  { background: #dc2626; }
  .dot-hold  { background: #d1d5db; }

  .ticker-badge { font-weight: 700; font-size: .82rem; letter-spacing: .02em; }
  .rsl-pill { background: #f0f2f5; border-radius: 6px; padding: .15em .5em; font-size: .8rem; font-weight: 600; color: #374151; }

  /* Portfolio-Banner */
  .portfolio-banner {
    border-radius: 10px; padding: .75rem 1.1rem;
  }
  .portfolio-banner.current { background: linear-gradient(135deg, #052e16, #14532d); }
  .portfolio-banner.start   { background: linear-gradient(135deg, #0f172a, #1e3a5f); }
  .banner-title { font-size: 1.1rem; font-weight: 800; letter-spacing: -.01em; color: #fff; }

  /* Schieberegler */
  .sim-slider { -webkit-appearance:none; appearance:none; width:100%; height:5px;
    border-radius:3px; background:#dbeafe; outline:none; cursor:pointer; margin:6px 0 2px; }
  .sim-slider::-webkit-slider-thumb { -webkit-appearance:none; appearance:none;
    width:16px; height:16px; border-radius:50%; background:#2563eb;
    border:2px solid #fff; box-shadow:0 1px 4px rgba(37,99,235,.4); cursor:pointer; }
  .sim-slider::-moz-range-thumb { width:16px; height:16px; border-radius:50%;
    background:#2563eb; border:2px solid #fff; box-shadow:0 1px 4px rgba(37,99,235,.4);
    cursor:pointer; }
  .slider-limits { display:flex; justify-content:space-between;
    font-size:.67rem; color:#93c5fd; margin-top:1px; }
</style>
</head>
<body>
<?php $activePage = 'simulation'; include __DIR__ . '/inc_navbar.php'; ?>

<div class="container-fluid px-4 py-4">
  <div class="row g-4 align-items-start">

    <!-- ── Linke Spalte: Eingabe + Ergebnis ─────────────────────────────── -->
    <div class="col-lg-3">
      <div class="sidebar-sticky">
        <div class="card" style="border:1px solid #bfdbfe; box-shadow:0 4px 20px rgba(37,99,235,.12);">
          <div class="card-header px-3 py-2" style="background:linear-gradient(135deg,#eff6ff,#e0e7ff);border-bottom:1px solid #bfdbfe;">
            <i class="bi bi-sliders me-1" style="color:#2563eb;"></i>
            <span style="font-size:.82rem;font-weight:700;color:#1e40af;">Simulation konfigurieren</span>
          </div>
          <div class="card-body p-3">
            <form method="get" action="simulation.php">
              <input type="hidden" name="universe" value="<?= htmlspecialchars($universe) ?>">
              <input type="hidden" name="capital" id="inputCapital"
                     value="<?= number_format($startCapital, 0, '.', '') ?>">

              <!-- Startkapital -->
              <div class="mb-3">
                <label class="form-label" style="font-size:.78rem;font-weight:700;color:#1e40af;letter-spacing:.03em;text-transform:uppercase;">
                  <i class="bi bi-cash-coin me-1"></i>Startkapital
                </label>
                <div class="input-group">
                  <span class="input-group-text" id="capital-currency-label"
                        style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;font-weight:700;font-size:.9rem;"><?= $isDax ? '€ EUR' : 'USD' ?></span>
                  <input type="text" class="form-control" id="inputCapitalDisplay"
                         value="<?= number_format($startCapital, 0, ',', '.') ?>"
                         style="font-size:1.15rem;font-weight:700;text-align:right;border-color:#bfdbfe;color:#1e3a8a;letter-spacing:.01em;"
                         autocomplete="off" inputmode="numeric">
                </div>
                <input type="range" id="sliderCapital" class="sim-slider"
                       min="10000" max="250000" step="10000"
                       value="<?= (int)$startCapital ?>">
                <div class="slider-limits"><span>10.000</span><span>250.000</span></div>
              </div>

              <!-- Start Strategie -->
              <div class="mb-3">
                <label class="form-label" style="font-size:.78rem;font-weight:700;color:#1e40af;letter-spacing:.03em;text-transform:uppercase;">
                  <i class="bi bi-calendar3 me-1"></i>Start Strategie
                </label>
                <input type="date" class="form-control" name="start_date" id="inputStartDate"
                       value="<?= htmlspecialchars($startDate) ?>"
                       min="<?= $minDate ?>" max="<?= $maxDate ?>"
                       style="border-color:#bfdbfe;font-size:.9rem;">
                <input type="range" id="sliderDate" class="sim-slider"
                       min="0" max="<?= $sliderMaxDays ?>" step="7"
                       value="<?= $sliderCurDays ?>">
                <div class="slider-limits">
                  <span><?= date('d.m.Y', strtotime($minDate)) ?></span>
                  <span><?= date('d.m.Y', strtotime($maxDate)) ?></span>
                </div>
              </div>

            </form>

            <hr style="border-color:#e0e7ff;margin:1rem 0;">

            <!-- Ergebnis -->
            <div class="kpi-box mb-2" style="background:#f0fdf4;border-color:#bbf7d0;">
              <div class="kpi-label">Kapitalstand</div>
              <div class="kpi-value <?= $finalCapital >= $startCapital ? 'text-success' : 'text-danger' ?>" id="kpi-kapital-val" data-usd="<?= round($finalCapital) ?>">
                <?= number_format($finalCapital, 0, ',', '.') ?>
                <small style="font-size:.8rem;" id="kpi-kapital-sym"><?= $isDax ? 'EUR' : 'USD' ?></small>
              </div>
            </div>

            <div class="kpi-box" style="background:#f0fdf4;border-color:#bbf7d0;">
              <div class="kpi-label">Veränderung <span id="kpi-change-curr" style="font-size:.65rem;text-transform:none;letter-spacing:0;opacity:.75;"></span></div>
              <div class="kpi-value" id="kpi-change-val"
                   data-pct-usd="<?= round($changePct, 4) ?>"
                   data-final-usd="<?= round($finalCapital) ?>"
                   data-start-usd="<?= round($startCapital) ?>">
              </div>
              <div style="font-size:.72rem;color:#6c757d;margin-top:.25rem;">
                seit <?= date('d.m.Y', strtotime($startDate)) ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Rechte Spalte: Portfolio-Historie ─────────────────────────────── -->
    <div class="col-lg-9">

      <?php if (empty($snapshots)): ?>
      <div class="alert alert-info">
        Keine Daten für den gewählten Zeitraum.
      </div>
      <?php endif; ?>

      <?php
        $totalSnaps = count($snapshots);
        foreach ($snapshots as $snapIdx => $snap):
        $newSet    = array_flip($snap['new']);
        $nNew      = count($snap['new']);
        $nExited   = count($snap['exited']);
        $nPos      = count($snap['holdings']);
        $isFirst   = ($snapIdx === 0);
        $isLast    = ($snapIdx === $totalSnaps - 1);
      ?>

      <?php if ($isFirst): ?>
      <div style="border:2px solid #16a34a; border-radius:16px; padding:10px; margin-bottom:1.5rem;">
        <div class="portfolio-banner current mb-2">
          <div class="banner-title">Aktuelles Portfolio</div>
        </div>
      <?php elseif ($isLast && $totalSnaps > 1): ?>
      <div style="border:2px solid #2563eb; border-radius:16px; padding:10px; margin-top:2rem;">
        <div class="portfolio-banner start mb-2">
          <div class="banner-title">Start-Portfolio</div>
        </div>
      <?php else: ?>
      <div>
      <?php endif; ?>

      <div class="card portfolio-card" style="margin-bottom:0;">

        <!-- Header -->
        <div class="portfolio-header">
          <i class="bi bi-calendar3 text-muted" style="font-size:.85rem;"></i>
          <span class="portfolio-date"><?= dateDe($snap['date']) ?></span>

          <div class="change-badges">
            <?php if ($snap['no_change']): ?>
              <span class="badge-nochange">keine Änderung</span>
            <?php else: ?>
              <?php if ($nNew > 0): ?>
                <span class="badge-new-exit">+<?= $nNew ?> neu</span>
              <?php endif; ?>
              <?php if ($nExited > 0): ?>
                <span class="badge-exit-only"><?= $nExited ?> ausgeschieden</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Tabelle -->
        <div class="table-responsive">
          <table class="table ptable">
            <thead>
              <tr>
                <th style="width:18px;"></th>
                <th>Ticker</th>
                <th>Unternehmen</th>
                <th>Sektor</th>
                <th class="text-end">Gewicht</th>
                <th class="text-end sim-th-betrag">Betrag in <?= $isDax ? 'EUR' : 'USD' ?></th>
                <th class="text-end">RSL Score</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($snap['holdings'] as $h):
                $isNew    = isset($newSet[$h['ticker']]);
                $rowClass = $isNew ? 'row-new' : 'row-normal';
              ?>
              <tr class="<?= $rowClass ?>">
                <td class="text-center ps-3">
                  <?php if ($isNew): ?>
                    <span class="dot dot-new"></span>
                  <?php else: ?>
                    <span class="dot dot-hold"></span>
                  <?php endif; ?>
                </td>
                <td><span class="ticker-badge"><?= htmlspecialchars($h['ticker']) ?></span></td>
                <td style="color:#374151;"><?= htmlspecialchars($h['company']) ?></td>
                <td style="color:#6c757d;font-size:.80rem;"><?= htmlspecialchars($h['sector']) ?></td>
                <td class="text-end" style="color:#374151;"><?= number_format($h['weight'], 1, ',', '.') ?>%</td>
                <td class="text-end sim-mkt-val" style="color:#374151;" data-usd="<?= round($h['mkt_val']) ?>"><?= number_format($h['mkt_val'], 0, ',', '.') ?></td>
                <td class="text-end">
                  <span class="rsl-pill"><?= number_format($h['rsl'], 4, ',', '.') ?></span>
                </td>
              </tr>
              <?php endforeach; ?>

              <!-- Ausgeschiedene Positionen -->
              <?php foreach ($snap['exited'] as $exitTicker => $exitData): ?>
              <tr class="row-exited">
                <td class="text-center ps-3">
                  <span class="dot dot-exit"></span>
                </td>
                <td><span class="ticker-badge" style="color:#9ca3af;"><?= htmlspecialchars($exitTicker) ?></span></td>
                <td colspan="5" style="font-size:.80rem;color:#9ca3af;">Position geschlossen</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="font-weight:600; border-top:2px solid #dee2e6;">
                <td colspan="4" class="text-end text-muted" style="font-size:.85rem;">Summe</td>
                <td class="text-end">100,0%</td>
                <td class="text-end sim-pv" data-usd="<?= round($snap['pv']) ?>"><?= number_format($snap['pv'], 0, ',', '.') ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

      </div><!-- card -->
      </div><!-- wrapper -->
      <?php endforeach; ?>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Currency toggle — DAX ist immer EUR, keine Konvertierung
const _isDax        = <?= $isDax ? 'true' : 'false' ?>;
const _currency     = _isDax ? 'EUR' : (localStorage.getItem('currency') || 'USD');
const currentEurUsd = <?= round($currentEurUsd, 6) ?>;
const startEurUsd   = <?= round($startEurUsd, 6) ?>;
const endEurUsd     = <?= round($endEurUsd, 6) ?>;
document.getElementById('btn-usd')?.classList.toggle('active', _currency === 'USD');
document.getElementById('btn-eur')?.classList.toggle('active', _currency === 'EUR');
document.getElementById('btn-usd')?.addEventListener('click', () => { localStorage.setItem('currency', 'USD'); location.reload(); });
document.getElementById('btn-eur')?.addEventListener('click', () => { localStorage.setItem('currency', 'EUR'); location.reload(); });

// Currency label on KPI boxes
const _currLabelSim = _currency === 'EUR' ? '(EUR)' : '(USD)';
const kpiChangeCurr = document.getElementById('kpi-change-curr');
if (kpiChangeCurr) kpiChangeCurr.textContent = _currLabelSim;

// Veränderungs-KPI rendern (USD oder EUR inkl. Währungseffekt)
(function() {
  const el = document.getElementById('kpi-change-val');
  if (!el) return;
  let pct;
  if (!_isDax && _currency === 'EUR') {
    // S&P 500 EUR-Rendite: USD-Werte mit Währungseffekt umrechnen
    const finalUsd = parseFloat(el.dataset.finalUsd);
    const startUsd = parseFloat(el.dataset.startUsd);
    const finalEur = finalUsd / endEurUsd;
    const startEur = startUsd / startEurUsd;
    pct = (finalEur / startEur - 1) * 100;
  } else {
    // DAX oder USD: Rendite direkt aus Simulation (bereits in Heimwährung)
    pct = parseFloat(el.dataset.pctUsd);
  }
  el.textContent = (pct >= 0 ? '+' : '') + pct.toLocaleString('de-DE', {minimumFractionDigits:1, maximumFractionDigits:1}) + '%';
  el.className = 'kpi-value ' + (pct >= 0 ? 'text-success' : 'text-danger');
})();

if (!_isDax && _currency === 'EUR') {
  // S&P 500: USD-Werte in EUR umrechnen
  const kpiVal = document.getElementById('kpi-kapital-val');
  const kpiSym = document.getElementById('kpi-kapital-sym');
  if (kpiVal && kpiVal.dataset.usd) {
    const eur = Math.round(parseFloat(kpiVal.dataset.usd) / endEurUsd);
    kpiVal.childNodes[0].textContent = eur.toLocaleString('de-DE') + ' ';
    if (kpiSym) kpiSym.textContent = 'EUR';
  }
  document.querySelectorAll('.sim-th-betrag').forEach(el => el.textContent = 'Betrag in EUR');
  document.querySelectorAll('.sim-mkt-val, .sim-pv').forEach(el => {
    const usd = parseFloat(el.dataset.usd);
    if (!isNaN(usd)) el.textContent = Math.round(usd / endEurUsd).toLocaleString('de-DE');
  });
}
// DAX: Werte sind bereits in EUR — nur Zahlen formatieren, kein Umrechnen nötig

(function () {
  const displayInput   = document.getElementById('inputCapitalDisplay');
  const hiddenCapital  = document.getElementById('inputCapital');
  const startDateInput = document.getElementById('inputStartDate');
  const sliderCapital  = document.getElementById('sliderCapital');
  const sliderDate     = document.getElementById('sliderDate');
  const form           = document.querySelector('form');
  const params         = new URLSearchParams(window.location.search);

  // Währungslabel am Input aktualisieren (DAX: fix EUR, S&P 500: je nach Toggle)
  const currLabel = document.getElementById('capital-currency-label');
  if (currLabel && !_isDax) currLabel.textContent = _currency === 'EUR' ? '€ EUR' : '$ USD';

  // EUR: angezeigte USD-Werte in EUR umrechnen (nur S&P 500)
  if (!_isDax && _currency === 'EUR') {
    const usdVal = parseInt(hiddenCapital.value, 10) || 50000;
    displayInput.value = formatNum(Math.round(usdVal / currentEurUsd));
  }

  // Datums-Hilfsfunktionen (Slider-Wert = Tage seit minDate)
  const minTs = new Date('<?= $minDate ?>').getTime();
  function daysToDate(days) {
    const d = new Date(minTs + days * 86400000);
    return d.toISOString().slice(0, 10);
  }
  function dateToDays(dateStr) {
    return Math.round((new Date(dateStr).getTime() - minTs) / 86400000);
  }

  // Hilfsfunktionen: Zahl ↔ formatierter String (Tausenderpunkt, kein Komma)
  function parseRaw(str) {
    return parseInt(str.replace(/\./g, '').replace(/[^\d]/g, ''), 10) || 0;
  }
  function formatNum(n) {
    return n.toLocaleString('de-DE', { maximumFractionDigits: 0 });
  }

  // Display formatieren und Hidden-Field synchronisieren
  // DAX: intern EUR, keine Konvertierung; S&P 500: intern USD
  function syncCapital() {
    const raw = Math.max(1000, parseRaw(displayInput.value));
    displayInput.value  = formatNum(raw);
    hiddenCapital.value = (!_isDax && _currency === 'EUR') ? Math.round(raw * currentEurUsd) : raw;
  }

  // Wenn keine GET-Parameter vorhanden: gespeicherte Werte aus localStorage laden
  if (!params.has('capital') && !params.has('start_date')) {
    const savedCapitalUsd = localStorage.getItem('sim_capital');
    const savedStartDate  = localStorage.getItem('sim_start_date');
    if (savedCapitalUsd) {
      const usdVal     = Math.max(1000, parseInt(savedCapitalUsd, 10) || 50000);
      const displayVal = (!_isDax && _currency === 'EUR') ? Math.round(usdVal / currentEurUsd) : usdVal;
      displayInput.value  = formatNum(displayVal);
      hiddenCapital.value = usdVal;
    }
    if (savedStartDate && startDateInput) startDateInput.value = savedStartDate;
    // Automatisch neu laden wenn gespeicherte Werte vom Default abweichen
    const defaultCapital = parseInt(hiddenCapital.defaultValue, 10);
    if ((savedCapitalUsd && parseInt(savedCapitalUsd, 10) !== defaultCapital) ||
        (savedStartDate && startDateInput && savedStartDate !== startDateInput.defaultValue)) {
      if (savedCapitalUsd) hiddenCapital.value = parseInt(savedCapitalUsd, 10);
      form.submit();
      return;
    }
  }

  function saveAndSubmit() {
    syncCapital();
    localStorage.setItem('sim_capital',    hiddenCapital.value);
    localStorage.setItem('sim_start_date', startDateInput.value);
    form.submit();
  }

  // ── Kapital: Text-Input → Slider ────────────────────────────────────────
  let debounceTimer;
  displayInput.addEventListener('input', () => {
    const sel    = displayInput.selectionStart;
    const oldLen = displayInput.value.length;
    const raw    = parseRaw(displayInput.value);
    if (raw > 0) {
      displayInput.value = formatNum(raw);
      const newLen = displayInput.value.length;
      displayInput.setSelectionRange(sel + (newLen - oldLen), sel + (newLen - oldLen));
    }
    const usdVal = (!_isDax && _currency === 'EUR') ? Math.round(raw * currentEurUsd) : raw;
    hiddenCapital.value  = usdVal || '';
    sliderCapital.value  = Math.min(Math.max(usdVal, 10000), 250000);
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(saveAndSubmit, 800);
  });

  displayInput.addEventListener('blur', syncCapital);

  // ── Kapital: Slider → Text-Input ─────────────────────────────────────────
  sliderCapital.addEventListener('input', () => {
    const usdVal     = parseInt(sliderCapital.value, 10);
    const displayVal = (!_isDax && _currency === 'EUR') ? Math.round(usdVal / currentEurUsd) : usdVal;
    displayInput.value  = formatNum(displayVal);
    hiddenCapital.value = usdVal;
  });
  sliderCapital.addEventListener('change', saveAndSubmit);

  // ── Datum: Date-Input → Slider ────────────────────────────────────────────
  startDateInput.addEventListener('change', () => {
    sliderDate.value = dateToDays(startDateInput.value);
    saveAndSubmit();
  });

  // ── Datum: Slider → Date-Input ────────────────────────────────────────────
  sliderDate.addEventListener('input', () => {
    startDateInput.value = daysToDate(parseInt(sliderDate.value, 10));
  });
  sliderDate.addEventListener('change', saveAndSubmit);

  // Aktuelle Portfolio-Ticker in localStorage speichern (für Ranking-Seite)
  localStorage.setItem('sim_portfolio_tickers_<?= $universe ?>', JSON.stringify(<?= json_encode(array_keys($holdings)) ?>));
})();
</script>
</body>
</html>
