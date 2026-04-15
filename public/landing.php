<?php
require_once __DIR__ . '/../src/RSLEngine.php';
$rsl = new RSLEngine();
$db  = getDB();

$universe = $_GET['universe'] ?? 'sp500';
if (!in_array($universe, ['sp500', 'dax'])) $universe = 'sp500';
$isDax    = ($universe === 'dax');

$top5 = $rsl->getCurrentTop5(null, $universe);

// ── Start-Datum (via GET, gesetzt vom JS-Redirect) ─────────────────────────
$minDate   = '2010-01-04';
$maxDate   = $db->query("SELECT MAX(ranking_date) FROM rsl_rankings WHERE universe='$universe'")->fetchColumn() ?: date('Y-m-d');
$startDate = $_GET['start_date'] ?? $minDate;
if ($startDate < $minDate) $startDate = $minDate;
if ($startDate > $maxDate) $startDate = $maxDate;

$hasData  = (bool)$db->query('SELECT COUNT(*) FROM rsl_rankings LIMIT 1')->fetchColumn();
$endDate  = date('Y-m-d');
$chartDates = $chartPortfolio = $chartBenchmark = 'null';

if ($hasData) {
    // ── M&A-Filter ──────────────────────────────────────────────────────────
    $maFlagged = [];
    foreach ($db->query('SELECT ticker FROM m_and_a_flags WHERE is_active = 1')
                 ->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $maFlagged[$t] = true;
    }

    // ── Rankings ab Startdatum laden ─────────────────────────────────────────
    $stmt = $db->prepare(
        'SELECT r.ranking_date, r.ticker, r.sector, r.current_price, r.rsl, r.rank_overall
         FROM rsl_rankings r
         WHERE r.ranking_date >= ?
         ORDER BY r.ranking_date ASC, r.rank_overall ASC'
    );
    $stmt->execute([$startDate]);
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDate[$row['ranking_date']][] = $row;
    }
    $simSundays = array_keys($byDate);

    // ── Frische Simulation ───────────────────────────────────────────────────
    $startCapital = 100000.0;
    $cash         = $startCapital;
    $holdings     = [];
    $weeklyPortfolio = [];

    foreach ($simSundays as $i => $sunday) {
        $weekRankings = $byDate[$sunday];
        $rankByTicker = array_column($weekRankings, null, 'ticker');
        $isLast       = ($i === count($simSundays) - 1);
        $saleProceeds = [];

        foreach (array_keys($holdings) as $ticker) {
            $rank = isset($rankByTicker[$ticker])
                ? (int)$rankByTicker[$ticker]['rank_overall'] : PHP_INT_MAX;
            if ($rank > 125) {
                $price = (float)($rankByTicker[$ticker]['current_price'] ?? $holdings[$ticker]['buy_price']);
                $cash += $holdings[$ticker]['shares'] * $price;
                $saleProceeds[] = $holdings[$ticker]['shares'] * $price;
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
            $holdings[$stock['ticker']] = [
                'shares'    => $budget / $price,
                'buy_price' => $price,
                'sector'    => $sector,
            ];
            $heldSectors[] = $sector;
            $vacancies--;
        }

        $invested = 0;
        foreach ($holdings as $ticker => $h) {
            $price     = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            $invested += $h['shares'] * $price;
        }
        $weeklyPortfolio[$sunday] = $cash + $invested;
    }

    // ── SPY Benchmark ─────────────────────────────────────────────────────
    $spyStmt = $db->prepare(
        'SELECT price_date, adj_close FROM prices
         WHERE ticker = "SPY" AND price_date >= ? ORDER BY price_date ASC'
    );
    $spyStmt->execute([$startDate]);
    $spyByDate = [];
    foreach ($spyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $spyByDate[$row['price_date']] = (float)$row['adj_close'];
    }
    $spyDates  = array_keys($spyByDate);
    $spyStartP = null;
    $spyIdx    = 0;
    $nSpy      = count($spyDates);
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

    $endDate        = end($simSundays) ?: date('Y-m-d');
    $chartDates     = json_encode(array_keys($weeklyPortfolio));
    $chartPortfolio = json_encode(array_map('round', array_values($weeklyPortfolio)));
    $chartBenchmark = json_encode(array_map(
        fn($d) => $weeklyBench[$d] ?? null,
        array_keys($weeklyPortfolio)
    ));

    // ── EUR/USD historische Kurse (identisch zu backtest.php) ──────────────
    $eurRatesRaw   = $db->query("SELECT price_date, adj_close FROM prices WHERE ticker='EURUSD=X' ORDER BY price_date")->fetchAll(PDO::FETCH_KEY_PAIR);
    $currentEurUsd = $eurRatesRaw ? (float)end($eurRatesRaw) : 1.10;
    $eurDates      = array_keys($eurRatesRaw);
    $nEur          = count($eurDates);
    $eurIdx        = 0;
    $eurRatesBySunday = [];
    foreach ($simSundays as $sunday) {
        while ($eurIdx < $nEur - 1 && $eurDates[$eurIdx + 1] <= $sunday) $eurIdx++;
        $eurRatesBySunday[$sunday] = ($nEur > 0 && $eurDates[$eurIdx] <= $sunday)
            ? (float)$eurRatesRaw[$eurDates[$eurIdx]] : $currentEurUsd;
    }
    $chartEurRates = json_encode(array_values($eurRatesBySunday));

    $stmtEur = $db->prepare("SELECT adj_close FROM prices WHERE ticker='EURUSD=X' AND price_date <= ? ORDER BY price_date DESC LIMIT 1");
    $stmtEur->execute([$simSundays[0]]);
    $startEurUsd = (float)($stmtEur->fetchColumn() ?: $currentEurUsd);
    $stmtEur->execute([end($simSundays)]);
    $endEurUsd   = (float)($stmtEur->fetchColumn() ?: $currentEurUsd);
} else {
    $currentEurUsd = 1.10; $startEurUsd = 1.10; $endEurUsd = 1.10;
    $chartEurRates = 'null';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RSL nach Levy — Momentum-Strategie</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  * { box-sizing: border-box; }

  /* ── Hero ──────────────────────────────────────────────────── */
  .hero {
    min-height: 100vh;
    background:
      linear-gradient(135deg, rgba(10,14,23,.55) 0%, rgba(10,20,40,.45) 60%, rgba(5,30,60,.60) 100%),
      url('https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?auto=format&fit=crop&w=1920&q=80')
      center center / cover no-repeat;
    display: flex;
    flex-direction: column;
    justify-content: center;
    color: #fff;
    position: relative;
    overflow: hidden;
  }
  .hero::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse at 70% 50%, rgba(37,99,235,.18) 0%, transparent 60%);
    pointer-events: none;
  }
  .hero-badge {
    display: inline-flex; align-items: center; gap: .5rem;
    background: rgba(37,99,235,.25);
    border: 1px solid rgba(99,160,255,.35);
    color: #93c5fd;
    font-size: .78rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
    padding: .45em 1.1em; border-radius: 20px; margin-bottom: 1.5rem;
  }
  .hero h1 {
    font-size: clamp(2.4rem, 5vw, 4rem);
    font-weight: 800; letter-spacing: -.02em;
    line-height: 1.1; margin-bottom: 1.1rem;
    background: linear-gradient(135deg, #fff 40%, #93c5fd 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  }
  .hero-sub {
    font-size: 1.12rem; color: rgba(255,255,255,.7);
    max-width: 540px; line-height: 1.7; margin-bottom: 2.5rem;
  }
  .btn-enter {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff; border: none; border-radius: 10px;
    padding: .85rem 2.2rem; font-size: 1rem; font-weight: 700;
    letter-spacing: .02em; transition: all .25s;
    box-shadow: 0 4px 24px rgba(37,99,235,.45);
  }
  .btn-enter:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(37,99,235,.6);
    color: #fff;
  }
  .btn-outline-light-custom {
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.25);
    color: #fff; border-radius: 10px;
    padding: .85rem 2rem; font-size: 1rem; font-weight: 600;
    transition: all .25s;
  }
  .btn-outline-light-custom:hover {
    background: rgba(255,255,255,.16); color: #fff;
  }

  /* ── KPI-Karten im Hero ─────────────────────────────────────── */
  .hero-kpis { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 3rem; }
  .hero-kpi {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.13);
    border-radius: 14px; padding: 1.1rem 1.5rem;
    min-width: 150px; flex: 1;
  }
  .hero-kpi-val {
    font-size: 1.7rem; font-weight: 800; line-height: 1;
    margin-bottom: .25rem;
  }
  .hero-kpi-label {
    font-size: .72rem; text-transform: uppercase;
    letter-spacing: .06em; color: rgba(255,255,255,.55);
  }
  .kv-green { color: #4ade80; }
  .kv-blue  { color: #60a5fa; }
  .kv-amber { color: #fbbf24; }
  .kv-white { color: #fff; }

  /* ── Strategie-Section ──────────────────────────────────────── */
  .strategy-section { background: #f5f7fa; padding: 5rem 0; }
  .section-eyebrow {
    font-size: .78rem; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: #2563eb; margin-bottom: .6rem;
  }
  .section-title {
    font-size: clamp(1.6rem, 3vw, 2.4rem);
    font-weight: 800; color: #0f172a; line-height: 1.2;
  }
  .section-sub { color: #6b7280; font-size: 1.05rem; max-width: 620px; }

  .step-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 1.75rem;
    height: 100%;
    transition: all .25s;
    position: relative;
    overflow: hidden;
  }
  .step-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: 16px 16px 0 0;
  }
  .step-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,.09); }
  .step-card.blue::before  { background: linear-gradient(90deg, #2563eb, #60a5fa); }
  .step-card.green::before { background: linear-gradient(90deg, #16a34a, #4ade80); }
  .step-card.amber::before { background: linear-gradient(90deg, #d97706, #fbbf24); }
  .step-card.violet::before { background: linear-gradient(90deg, #7c3aed, #a78bfa); }
  .step-card.red::before   { background: linear-gradient(90deg, #dc2626, #f87171); }
  .step-card.teal::before  { background: linear-gradient(90deg, #0891b2, #22d3ee); }

  .step-num {
    width: 42px; height: 42px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; font-weight: 800; margin-bottom: 1rem;
  }
  .step-num.blue   { background: rgba(37,99,235,.1);  color: #2563eb; }
  .step-num.green  { background: rgba(22,163,74,.1);  color: #16a34a; }
  .step-num.amber  { background: rgba(217,119,6,.1);  color: #d97706; }
  .step-num.violet { background: rgba(124,58,237,.1); color: #7c3aed; }
  .step-num.red    { background: rgba(220,38,38,.1);  color: #dc2626; }
  .step-num.teal   { background: rgba(8,145,178,.1);  color: #0891b2; }

  .step-title { font-size: 1rem; font-weight: 700; color: #111827; margin-bottom: .5rem; }
  .step-body  { font-size: .88rem; color: #6b7280; line-height: 1.65; }
  .step-formula {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: .6rem 1rem; margin-top: .85rem;
    font-family: 'Courier New', monospace; font-size: .84rem; color: #334155;
    font-weight: 600;
  }

  /* ── Annahmen-Section ───────────────────────────────────────── */
  .assumptions-section {
    background: #0f172a;
    padding: 4.5rem 0;
    color: #fff;
  }
  .assumption-item {
    display: flex; gap: 1rem; align-items: flex-start;
    padding: 1.1rem 0; border-bottom: 1px solid rgba(255,255,255,.08);
  }
  .assumption-item:last-child { border-bottom: none; }
  .assumption-icon {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; background: rgba(37,99,235,.2); color: #60a5fa;
  }
  .assumption-title { font-size: .9rem; font-weight: 700; color: #f1f5f9; margin-bottom: .2rem; }
  .assumption-body  { font-size: .83rem; color: rgba(255,255,255,.55); line-height: 1.6; }

  /* ── Current Portfolio Strip ────────────────────────────────── */
  .portfolio-strip {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
    padding: 3.5rem 0; color: #fff;
  }
  .ticker-pill {
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 10px; padding: .7rem 1.2rem;
    text-align: center; flex: 1; min-width: 140px;
  }
  .ticker-pill .tp-ticker { font-size: 1.1rem; font-weight: 800; letter-spacing: .04em; }
  .ticker-pill .tp-name   { font-size: .7rem; color: rgba(255,255,255,.6); margin-top: .15rem; }
  .ticker-pill .tp-rsl    { font-size: .8rem; color: #4ade80; font-weight: 700; margin-top: .3rem; }

  /* ── Robustheit-Section ─────────────────────────────────────── */
  .robustness-section {
    background: #f8fafc;
    padding: 4.5rem 0;
  }
  .robustness-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 2rem;
    height: 100%;
    transition: box-shadow .2s;
  }
  .robustness-card:hover { box-shadow: 0 8px 32px rgba(15,23,42,.08); }
  .robustness-card .rc-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; margin-bottom: 1rem;
  }
  .robustness-card .rc-title { font-size: 1rem; font-weight: 700; color: #0f172a; margin-bottom: .6rem; }
  .robustness-card .rc-body  { font-size: .875rem; color: #475569; line-height: 1.7; }
  .robustness-highlight {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
    border-radius: 16px; padding: 2rem 2.5rem;
    color: #fff; margin-top: 2.5rem;
  }
  .robustness-highlight .rh-label { font-size: .75rem; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .08em; margin-bottom: .25rem; }
  .robustness-highlight .rh-value { font-size: 1.6rem; font-weight: 800; color: #4ade80; }
  .robustness-highlight .rh-sub   { font-size: .82rem; color: rgba(255,255,255,.55); margin-top: .2rem; }

  /* ── Navbar ─────────────────────────────────────────────────── */
  .landing-nav { background: rgba(6,13,26,.85) !important; backdrop-filter: blur(8px);
    border-bottom: 1px solid rgba(255,255,255,.08); position: fixed; top: 0; left: 0; right: 0; z-index: 100; }
  .landing-nav .container-fluid { min-height: 56px; height: auto; }
  .landing-nav .navbar-brand { color: #fff !important; font-weight: 700; }
  .landing-nav .nav-link { color: rgba(255,255,255,.6) !important; font-size: .875rem; padding: .375rem .65rem !important; }
  .landing-nav .nav-link:hover, .landing-nav .nav-link.active { color: #fff !important; }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }
  .hero { padding-top: 56px; }

  /* ── Footer ─────────────────────────────────────────────────── */
  .landing-footer {
    background: #060d1a; color: rgba(255,255,255,.35);
    font-size: .8rem; padding: 1.5rem 0; text-align: center;
  }

  /* ── Scroll-Animation ───────────────────────────────────────── */
  .fade-up { opacity: 0; transform: translateY(28px); transition: opacity .6s ease, transform .6s ease; }
  .fade-up.visible { opacity: 1; transform: translateY(0); }
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════════════════ -->
<?php $activePage = 'landing'; include __DIR__ . '/inc_navbar.php'; ?>

<section class="hero">
  <div class="container px-4 py-5">
    <div class="row align-items-center g-5">

      <!-- Left: Text & CTAs -->
      <div class="col-lg-6">
        <div class="hero-badge">
          <i class="bi bi-graph-up-arrow"></i>
          Quantitative Momentum-Strategie
        </div>
        <h1>RSL nach Levy<br>S&amp;P 500</h1>
        <p class="hero-sub">
          Systematisches Aktien-Screening auf Basis der <strong style="color:#fff;">Relativen Stärke nach Levy</strong> —
          wöchentliches Rebalancing, strikte Sektor-Diversifikation, vollständig regelbasiert.
        </p>
        <div class="d-flex gap-3 flex-wrap">
          <a href="index.php" class="btn btn-enter">
            <i class="bi bi-speedometer2 me-2"></i>Dashboard öffnen
          </a>
        </div>

        <!-- KPI-Streifen (per JS auf sim_start_date skaliert) -->
        <?php if ($hasData): ?>
        <div class="hero-kpis">
          <div class="hero-kpi">
            <div class="hero-kpi-val kv-green" id="lkpi-return">—</div>
            <div class="hero-kpi-label">Gesamt-Rendite <span id="lkpi-curr" style="opacity:.55;font-size:.85em;"></span></div>
          </div>
          <div class="hero-kpi">
            <div class="hero-kpi-val kv-blue" id="lkpi-outperf">—</div>
            <div class="hero-kpi-label">vs. S&amp;P 500</div>
          </div>
          <div class="hero-kpi">
            <div class="hero-kpi-val kv-amber" id="lkpi-cagr">—</div>
            <div class="hero-kpi-label">CAGR p.a. <span style="opacity:.55;font-size:.85em;" class="lkpi-curr-ref"></span></div>
          </div>
          <div class="hero-kpi">
            <div class="hero-kpi-val kv-white" id="lkpi-zeitraum">—</div>
            <div class="hero-kpi-label">Backtest-Zeitraum</div>
          </div>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     STRATEGIE-SCHRITTE
════════════════════════════════════════════════════════════════ -->
<section class="strategy-section">
  <div class="container px-4">
    <div class="text-center mb-5 fade-up">
      <div class="section-eyebrow">Methodik</div>
      <h2 class="section-title">Die Strategie in 6 Schritten</h2>
      <p class="section-sub mx-auto mt-3">
        Ein vollständig regelbasierter Prozess — von der Datenbasis bis zum Handelssignal.
        Keine Prognosen, keine Diskretionsentscheidungen.
      </p>
    </div>

    <div class="row g-4">

      <div class="col-md-6 col-xl-4 fade-up">
        <div class="step-card blue">
          <div class="step-num blue">1</div>
          <div class="step-title">Universum: S&amp;P 500</div>
          <div class="step-body">
            Das Anlageuniversum umfasst alle Mitglieder des S&amp;P 500
            auf Basis der <strong>historischen Index-Zusammensetzung</strong>.
            Ehemalige Mitglieder werden berücksichtigt, um Survivorship Bias zu vermeiden.
            Datenbasis: ~580 Aktien mit Kurshistorie ab 2019.
          </div>
        </div>
      </div>

      <div class="col-md-6 col-xl-4 fade-up">
        <div class="step-card green">
          <div class="step-num green">2</div>
          <div class="step-title">RSL-Berechnung</div>
          <div class="step-body">
            Für jede Aktie wird wöchentlich der <strong>Relative Stärke Index nach Levy</strong>
            berechnet: der aktuelle Kurs dividiert durch den gleitenden Durchschnitt
            der letzten 26 Wochen (130 Handelstage).
            Ein RSL &gt; 1 signalisiert überdurchschnittliche relative Stärke.
          </div>
          <div class="step-formula">RSL = Kurs<sub>aktuell</sub> / SMA<sub>26W</sub></div>
        </div>
      </div>

      <div class="col-md-6 col-xl-4 fade-up">
        <div class="step-card amber">
          <div class="step-num amber">3</div>
          <div class="step-title">Ranking &amp; Selektion</div>
          <div class="step-body">
            Alle S&amp;P 500-Aktien werden nach RSL absteigend sortiert.
            Aus den <strong>Top-Aktien</strong> werden exakt <strong>5 Positionen</strong> ausgewählt —
            mit der Bedingung, dass je Sektor (GICS) maximal eine Aktie ins Portfolio aufgenommen wird
            (Greedy-Algorithmus, höchster RSL gewinnt).
          </div>
        </div>
      </div>

      <div class="col-md-6 col-xl-4 fade-up">
        <div class="step-card violet">
          <div class="step-num violet">4</div>
          <div class="step-title">Halte-Regel</div>
          <div class="step-body">
            Eine Position wird <strong>gehalten</strong>, solange ihre Aktie unter den
            <strong>Top 125</strong> des Rankings bleibt (Rang ≤ 125).
            Erst wenn sie aus diesem Bereich herausfällt, wird sie verkauft und durch
            die beste verfügbare Aktie aus einem noch nicht vertretenen Sektor ersetzt.
            Dies verhindert unnötiges Churning.
          </div>
        </div>
      </div>

      <div class="col-md-6 col-xl-4 fade-up">
        <div class="step-card teal">
          <div class="step-num teal">5</div>
          <div class="step-title">Wöchentliches Rebalancing</div>
          <div class="step-body">
            Jeden <strong>Sonntag</strong> wird das Portfolio überprüft.
            Verkaufserlöse werden 1:1 in die Nachfolge-Position reinvestiert
            (kein Kapital-Nachschuss, kein Umverteilen auf andere Positionen).
            Erstkäufe bei leerem Slot: gleichmäßige Aufteilung des verfügbaren Kapitals.
          </div>
        </div>
      </div>

      <div class="col-md-6 col-xl-4 fade-up">
        <div class="step-card red">
          <div class="step-num red">6</div>
          <div class="step-title">Positionsgröße</div>
          <div class="step-body">
            <strong>Equal Weight</strong>: Jede der 5 Positionen erhält beim Kauf
            exakt 20% des verfügbaren Kapitals.
            Durch unterschiedliche Kursverläufe können die Gewichte im Zeitverlauf
            leicht abweichen — kein kontinuierliches Rebalancing zwischen Positionen.
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     ANNAHMEN & RAHMENBEDINGUNGEN
════════════════════════════════════════════════════════════════ -->
<section class="assumptions-section">
  <div class="container px-4">
    <div class="row g-5 align-items-start">
      <div class="col-lg-5 fade-up">
        <div class="section-eyebrow" style="color:#60a5fa;">Rahmenbedingungen</div>
        <h2 class="section-title" style="color:#f1f5f9;">Annahmen &amp;<br>Methodische Grenzen</h2>
        <p style="color:rgba(255,255,255,.5); font-size:.95rem; margin-top:1rem; line-height:1.7;">
          Jedes quantitative Modell basiert auf Annahmen. Hier sind die wichtigsten
          transparent dargestellt — für eine realistische Einschätzung der Ergebnisse.
        </p>
      </div>
      <div class="col-lg-7 fade-up">
        <div class="assumption-item">
          <div class="assumption-icon"><i class="bi bi-database-fill"></i></div>
          <div>
            <div class="assumption-title">Datenquelle: Yahoo Finance</div>
            <div class="assumption-body">Wochenschlusskurse (adjusted close) via Yahoo Finance API. Kurslücken bei delisteten Titeln werden toleriert — betroffene Aktien werden aus dem Ranking ausgeschlossen.</div>
          </div>
        </div>
        <div class="assumption-item">
          <div class="assumption-icon"><i class="bi bi-shuffle"></i></div>
          <div>
            <div class="assumption-title">Kein Survivorship Bias</div>
            <div class="assumption-body">Die historische S&amp;P 500-Zusammensetzung wird wochengenau berücksichtigt. Aktien, die nach 2020 aus dem Index entfernt wurden, sind im Backtest enthalten.</div>
          </div>
        </div>
        <div class="assumption-item">
          <div class="assumption-icon"><i class="bi bi-arrow-left-right"></i></div>
          <div>
            <div class="assumption-title">Keine Transaktionskosten</div>
            <div class="assumption-body">Der Backtest enthält keine Transaktionskosten, Spreads oder Slippage. Bei modernen Online-Brokern sind Ordergebühren gering, sollten aber bei der Bewertung der Ergebnisse berücksichtigt werden.</div>
          </div>
        </div>
        <div class="assumption-item">
          <div class="assumption-icon"><i class="bi bi-cash-stack"></i></div>
          <div>
            <div class="assumption-title">Teilaktien &amp; Liquidität</div>
            <div class="assumption-body">Die Simulation rechnet mit Bruchteilsaktien (keine Rundung auf ganze Stücke). In der Realität sind Mindestordergrößen und Liquidität zu beachten.</div>
          </div>
        </div>
        <div class="assumption-item">
          <div class="assumption-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
          <div>
            <div class="assumption-title">Kein Anlageberater</div>
            <div class="assumption-body">Dieses System dient ausschließlich zur Information und Forschung. Vergangene Performance ist kein verlässlicher Indikator für zukünftige Ergebnisse.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     AKTUELLES PORTFOLIO
════════════════════════════════════════════════════════════════ -->
<?php if (!empty($top5)): ?>
<section class="portfolio-strip">
  <div class="container px-4">
    <div class="text-center mb-4 fade-up">
      <div class="section-eyebrow" style="color:#93c5fd;">Aktuell</div>
      <h2 class="section-title" style="color:#fff;font-size:1.6rem;">Aktuelles RSL Top-5 Portfolio</h2>
    </div>
    <div class="d-flex gap-3 flex-wrap justify-content-center fade-up">
      <?php foreach ($top5 as $s): ?>
      <div class="ticker-pill">
        <div class="tp-ticker"><?= htmlspecialchars($s['ticker']) ?></div>
        <div class="tp-name"><?= htmlspecialchars(mb_substr($s['name'] ?? $s['ticker'], 0, 22)) ?></div>
        <div class="tp-rsl">RSL <?= number_format($s['rsl'], 4, ',', '.') ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     ROBUSTHEIT & INTERPRETATION
════════════════════════════════════════════════════════════════ -->
<section class="robustness-section">
  <div class="container">
    <div class="text-center mb-5 fade-up">
      <div class="section-eyebrow">Hintergrundinformation</div>
      <h2 class="section-title">Warum die Strategie mit der Zeit robuster wird</h2>
      <p class="section-sub mx-auto mt-3">
        Je länger das System läuft, desto weniger hängt das Ergebnis vom zufälligen Startmoment ab —
        und desto zuverlässiger spiegelt das Portfolio das eigentliche Momentum-Regime wider.
      </p>
    </div>

    <div class="row g-4">
      <div class="col-md-4 fade-up">
        <div class="robustness-card">
          <div class="rc-icon" style="background:#dcfce7; color:#16a34a;">&#x21BA;</div>
          <div class="rc-title">Sukzessive Rotation als Qualitätsfilter</div>
          <div class="rc-body">
            Das Portfolio wird durch wöchentliche Rotation schrittweise aufgebaut. Jeder Zyklus ersetzt
            schwächer werdende Titel durch stärkere — über viele Marktphasen hinweg. Schlechte Aktien
            werden herausrotiert, starke bleiben oder kehren zurück. Nach hunderten von Rotationen
            hat jede Position ihre Zugehörigkeit im Portfolio aktiv „verdient".
          </div>
        </div>
      </div>
      <div class="col-md-4 fade-up">
        <div class="robustness-card">
          <div class="rc-icon" style="background:#dbeafe; color:#2563eb;">&#x1F4C9;</div>
          <div class="rc-title">Abnehmende Pfadabhängigkeit</div>
          <div class="rc-body">
            Zu Beginn ist die Zusammensetzung stark durch den Startzeitpunkt geprägt: welche fünf Titel
            gerade zufällig die Sektordiversifikation erfüllen. Mit wachsender Laufzeit konvergiert das
            Portfolio gegen eine Zusammensetzung, die dem echten Momentum-Regime entspricht — unabhängig
            davon, wann der erste Kauf stattfand. Längere Laufzeit bedeutet also: weniger Zufall,
            mehr Signal.
          </div>
        </div>
      </div>
      <div class="col-md-4 fade-up">
        <div class="robustness-card">
          <div class="rc-icon" style="background:#fef3c7; color:#d97706;">&#x26A0;</div>
          <div class="rc-title">Dynamik ist kein Fehler — sie ist das Ziel</div>
          <div class="rc-body">
            „Robust" bedeutet hier nicht unveränderlich. Eine Momentum-Strategie muss rotieren —
            das ist ihr Kern. Was mit der Zeit stabiler wird, ist die <em>Qualität der Selektionsbasis</em>:
            ein gereiftes System reagiert auf Marktveränderungen aus einer breiten Erfahrungsbasis heraus,
            nicht aus dem Zufall eines einzelnen Startdatums.
          </div>
        </div>
      </div>
    </div>

    <div class="robustness-highlight fade-up">
      <div class="row align-items-center g-4">
        <div class="col-md-8">
          <h5 style="color:#fff; font-weight:700; margin-bottom:.5rem;">Backtest-Ergebnis: 16 Jahre (2010–2026)</h5>
          <p style="color:rgba(255,255,255,.65); font-size:.9rem; margin:0; line-height:1.7;">
            Über den gesamten Zeitraum von Januar 2010 bis heute — mit wöchentlichem Rebalancing
            und Sektordiversifikation — lieferte die RSL-Strategie eine annualisierte Rendite
            von rund <strong style="color:#4ade80;">23,6% p.a.</strong> bei 473 Trades. Das entspricht einer Gesamtrendite von knapp <strong style="color:#4ade80;">3.000%</strong>
            gegenüber dem S&amp;P 500 als Vergleichsmaßstab. Der maximale Drawdown betrug
            <strong style="color:#fbbf24;">–45,5%</strong> (u.a. COVID-Crash 2020).
          </p>
          <p style="color:rgba(255,255,255,.45); font-size:.78rem; margin-top:.8rem;">
            Hinweis: Vergangenheitsergebnisse sind kein Indikator für zukünftige Entwicklungen.
            Diese Auswertung dient ausschließlich zu Informationszwecken und stellt keine Anlageberatung dar.
          </p>
        </div>
        <div class="col-md-4">
          <div class="row g-3 text-center">
            <div class="col-6">
              <div class="rh-label">CAGR p.a.</div>
              <div class="rh-value">23,6%</div>
              <div class="rh-sub">annualisiert</div>
            </div>
            <div class="col-6">
              <div class="rh-label">Gesamt-Rendite</div>
              <div class="rh-value">+2.997%</div>
              <div class="rh-sub">2010–2026</div>
            </div>
            <div class="col-6">
              <div class="rh-label">Max. Drawdown</div>
              <div class="rh-value" style="color:#fbbf24;">–45,5%</div>
              <div class="rh-sub">Worst case</div>
            </div>
            <div class="col-6">
              <div class="rh-label">Anzahl Trades</div>
              <div class="rh-value" style="color:#93c5fd;">473</div>
              <div class="rh-sub">in 16 Jahren</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     FOOTER
════════════════════════════════════════════════════════════════ -->
<div class="landing-footer">
  RSL nach Levy — S&amp;P 500 Momentum-System &nbsp;|&nbsp;
  Apache · MariaDB · PHP 8.2 &nbsp;|&nbsp;
  Daten: Yahoo Finance &nbsp;|&nbsp;
  Kein Anlageberater — nur zu Informationszwecken
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Fade-up on scroll ────────────────────────────────────────────
const obs = new IntersectionObserver(entries => {
  entries.forEach((e, i) => {
    if (e.isIntersecting) {
      setTimeout(() => e.target.classList.add('visible'), i * 80);
      obs.unobserve(e.target);
    }
  });
}, { threshold: 0.12 });
document.querySelectorAll('.fade-up').forEach(el => obs.observe(el));

// ── Currency toggle ──────────────────────────────────────────────────
const _currency    = localStorage.getItem('currency') || 'USD';
document.getElementById('btn-usd')?.classList.toggle('active', _currency === 'USD');
document.getElementById('btn-eur')?.classList.toggle('active', _currency === 'EUR');
document.getElementById('btn-usd')?.addEventListener('click', () => { localStorage.setItem('currency', 'USD'); location.reload(); });
document.getElementById('btn-eur')?.addEventListener('click', () => { localStorage.setItem('currency', 'EUR'); location.reload(); });

const currentEurUsd = <?= round($currentEurUsd, 6) ?>;
const startEurUsd   = <?= round($startEurUsd, 6) ?>;
const endEurUsd     = <?= round($endEurUsd, 6) ?>;
const allEurRates   = <?= $chartEurRates ?>;

// Währungshinweis in KPI-Labels setzen
const currLabel = _currency === 'EUR' ? '(EUR)' : '(USD)';
document.getElementById('lkpi-curr')?.textContent && (document.getElementById('lkpi-curr').textContent = currLabel);
document.querySelectorAll('.lkpi-curr-ref').forEach(el => el.textContent = currLabel);
if (document.getElementById('lkpi-curr')) document.getElementById('lkpi-curr').textContent = currLabel;

// ── Startdatum aus localStorage mit URL-Param synchronisieren ───────
(function () {
  const _defaultStart = '2024-01-01';
  const simStart = localStorage.getItem('sim_start_date') || _defaultStart;
  const urlStart = new URLSearchParams(window.location.search).get('start_date');
  if (!localStorage.getItem('sim_start_date')) localStorage.setItem('sim_start_date', _defaultStart);
  if (simStart !== urlStart) {
    window.location.replace('landing.php?start_date=' + encodeURIComponent(simStart));
    return;
  }

  // ── KPI-Badges aus frischer Simulation ─────────────────────────────
  const allLabels    = <?= $chartDates ?>;
  const allPortfolio = <?= $chartPortfolio ?>;
  const allBenchmark = <?= $chartBenchmark ?>;
  if (!allLabels || !allLabels.length) return;

  const base      = allPortfolio.find(v => v !== null) || 1;
  const baseBench = allBenchmark.find(v => v !== null) || 1;
  const endPort   = [...allPortfolio].reverse().find(v => v !== null);
  const endBench  = [...allBenchmark].reverse().find(v => v !== null);

  // FX-Faktor für EUR (erster/letzter Simulations-Sonntag — identisch zu backtest.php)
  const eurStart  = allEurRates ? (allEurRates.find(v => v > 0) || currentEurUsd) : currentEurUsd;
  const eurEnd    = allEurRates ? ([...allEurRates].reverse().find(v => v > 0) || currentEurUsd) : currentEurUsd;
  const fxFactor  = _currency === 'EUR' ? (eurStart / eurEnd) : 1;

  const totalReturn = base      > 0 ? ((endPort  / base)      * fxFactor - 1) * 100 : 0;
  const benchReturn = baseBench > 0 ? ((endBench / baseBench) * fxFactor - 1) * 100 : 0;
  const outperf     = totalReturn - benchReturn;

  // CAGR (mit FX-Faktor)
  const startDateStr = allLabels[0];
  const endDateStr   = '<?= $endDate ?>';
  const years   = (new Date(endDateStr) - new Date(startDateStr)) / (365.25 * 24 * 3600 * 1000);
  const cagr    = years > 0 ? (Math.pow((endPort / base) * fxFactor, 1 / years) - 1) * 100 : 0;

  // Zeitraum in Jahren/Monaten
  const totalMonths = Math.round(years * 12);
  const yrs  = Math.floor(totalMonths / 12);
  const mths = totalMonths % 12;
  const zeitraumStr = yrs > 0 ? yrs + 'J' + (mths > 0 ? ' ' + mths + 'M' : '') : totalMonths + 'M';

  function fmt(v) { return (v >= 0 ? '+' : '') + v.toFixed(1).replace('.', ',') + '%'; }

  const r = document.getElementById('lkpi-return');   if (r) r.textContent = fmt(totalReturn);
  const o = document.getElementById('lkpi-outperf');  if (o) o.textContent = fmt(outperf);
  const c = document.getElementById('lkpi-cagr');     if (c) c.textContent = fmt(cagr);
  const z = document.getElementById('lkpi-zeitraum'); if (z) z.textContent = zeitraumStr;
})();
</script>
</body>
</html>
