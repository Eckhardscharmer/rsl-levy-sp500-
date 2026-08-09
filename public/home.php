<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>investsignal.de — Finanztools &amp; Analysen</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      background: #0a0f1e;
      font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
      color: #f1f5f9;
    }

    /* ── Hero ── */
    .hero {
      position: relative;
      padding: 4rem 1.5rem 3rem;
      text-align: center;
      overflow: hidden;
    }
    .hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(ellipse 80% 60% at 50% -10%, rgba(16,185,129,.18) 0%, transparent 70%),
        radial-gradient(ellipse 50% 40% at 20% 80%, rgba(37,99,235,.12) 0%, transparent 60%),
        radial-gradient(ellipse 50% 40% at 80% 80%, rgba(139,92,246,.10) 0%, transparent 60%);
      pointer-events: none;
    }
    .hero-logo {
      display: inline-flex;
      align-items: center;
      gap: .65rem;
      margin-bottom: 1.5rem;
    }
    .hero-logo svg { filter: drop-shadow(0 0 12px rgba(16,185,129,.5)); }
    .hero-logo-text {
      font-size: 1.6rem;
      font-weight: 800;
      letter-spacing: -.02em;
      color: #fff;
    }
    .hero-logo-text span { color: #10b981; }
    .hero h1 {
      font-size: clamp(2rem, 5vw, 3.2rem);
      font-weight: 800;
      letter-spacing: -.03em;
      line-height: 1.15;
      margin: 0 0 1rem;
      background: linear-gradient(135deg, #fff 30%, #94a3b8);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .hero-sub {
      font-size: 1.1rem;
      color: #94a3b8;
      max-width: 520px;
      margin: 0 auto 2.5rem;
      line-height: 1.6;
    }

    /* ── Cards ── */
    .cards-section {
      padding: 0 1.5rem 4rem;
      max-width: 1100px;
      margin: 0 auto;
    }
    .tool-card {
      position: relative;
      border-radius: 20px;
      padding: 2.2rem 2rem;
      height: 100%;
      display: flex;
      flex-direction: column;
      transition: transform .2s, box-shadow .2s;
      text-decoration: none;
      color: inherit;
      overflow: hidden;
    }
    .tool-card:hover {
      transform: translateY(-6px);
      color: inherit;
      text-decoration: none;
    }
    .tool-card::before {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: 20px;
      padding: 1.5px;
      -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
      -webkit-mask-composite: xor;
      mask-composite: exclude;
      pointer-events: none;
    }

    /* Karte 1: Relative Stärke */
    .card-rs {
      background: linear-gradient(145deg, #0d2d1f, #0f1f35);
      box-shadow: 0 4px 32px rgba(16,185,129,.15), 0 1px 0 rgba(16,185,129,.2) inset;
    }
    .card-rs::before { background: linear-gradient(135deg, rgba(16,185,129,.4), rgba(16,185,129,.05)); }
    .card-rs:hover { box-shadow: 0 16px 48px rgba(16,185,129,.25), 0 1px 0 rgba(16,185,129,.3) inset; }

    /* Karte 2: Optionspreisrechner */
    .card-options {
      background: linear-gradient(145deg, #1a1030, #0f1520);
      box-shadow: 0 4px 32px rgba(139,92,246,.12), 0 1px 0 rgba(139,92,246,.15) inset;
      cursor: default;
    }
    .card-options::before { background: linear-gradient(135deg, rgba(139,92,246,.3), rgba(139,92,246,.05)); }

    /* Karte: ETF-Rechner */
    .card-etf {
      background: linear-gradient(145deg, #0d1a30, #0f1520);
      box-shadow: 0 4px 32px rgba(59,130,246,.12), 0 1px 0 rgba(59,130,246,.15) inset;
    }
    .card-etf::before { background: linear-gradient(135deg, rgba(59,130,246,.3), rgba(59,130,246,.05)); }
    .card-etf:hover { box-shadow: 0 16px 48px rgba(59,130,246,.2), 0 1px 0 rgba(59,130,246,.25) inset; }

    /* Karte 3: WM 2026 */
    .card-wm {
      background: linear-gradient(145deg, #1a0f0f, #1a1020);
      box-shadow: 0 4px 32px rgba(245,158,11,.12), 0 1px 0 rgba(245,158,11,.15) inset;
    }
    .card-wm::before { background: linear-gradient(135deg, rgba(245,158,11,.3), rgba(245,158,11,.05)); }
    .card-wm:hover { box-shadow: 0 16px 48px rgba(245,158,11,.2), 0 1px 0 rgba(245,158,11,.25) inset; }

    .card-icon {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1.4rem;
      font-size: 1.6rem;
      flex-shrink: 0;
    }
    .icon-rs      { background: rgba(16,185,129,.15); }
    .icon-options { background: rgba(139,92,246,.15); }
    .icon-etf     { background: rgba(59,130,246,.15); }
    .icon-wm      { background: rgba(245,158,11,.15); }

    .card-label {
      font-size: .7rem;
      font-weight: 700;
      letter-spacing: .12em;
      text-transform: uppercase;
      margin-bottom: .5rem;
    }
    .label-rs      { color: #34d399; }
    .label-options { color: #a78bfa; }
    .label-etf     { color: #60a5fa; }
    .label-wm      { color: #fbbf24; }

    .card-title {
      font-size: 1.45rem;
      font-weight: 800;
      letter-spacing: -.02em;
      margin: 0 0 1rem;
      color: #fff;
      line-height: 1.2;
    }
    .card-desc {
      font-size: .9rem;
      color: #94a3b8;
      line-height: 1.7;
      flex-grow: 1;
    }
    .card-desc strong { color: #cbd5e1; }

    .card-cta {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      margin-top: 1.8rem;
      padding: .55rem 1.2rem;
      border-radius: 10px;
      font-size: .85rem;
      font-weight: 700;
      text-decoration: none;
      transition: all .15s;
      align-self: flex-start;
    }
    .cta-rs {
      background: rgba(16,185,129,.15);
      color: #34d399;
      border: 1px solid rgba(16,185,129,.3);
    }
    .cta-rs:hover { background: rgba(16,185,129,.25); color: #6ee7b7; }
    .cta-options {
      background: rgba(139,92,246,.15);
      color: #a78bfa;
      border: 1px solid rgba(139,92,246,.3);
    }
    .cta-options:hover { background: rgba(139,92,246,.25); color: #c4b5fd; }
    .cta-etf {
      background: rgba(59,130,246,.15);
      color: #60a5fa;
      border: 1px solid rgba(59,130,246,.3);
    }
    .cta-etf:hover { background: rgba(59,130,246,.25); color: #93c5fd; }
    .cta-wm {
      background: rgba(245,158,11,.15);
      color: #fbbf24;
      border: 1px solid rgba(245,158,11,.3);
    }
    .cta-wm:hover { background: rgba(245,158,11,.25); color: #fde68a; }

    /* Baustellen-Badge */
    .coming-soon {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      flex-grow: 1;
      padding: 1.5rem 0 .5rem;
      gap: .75rem;
    }
    .construction-icon { font-size: 3rem; line-height: 1; }
    .coming-soon-badge {
      display: inline-block;
      background: rgba(139,92,246,.2);
      border: 1px solid rgba(139,92,246,.35);
      color: #c4b5fd;
      font-size: .78rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      padding: .35rem .9rem;
      border-radius: 20px;
    }
    .coming-soon-text {
      font-size: .85rem;
      color: #64748b;
      text-align: center;
      max-width: 200px;
      line-height: 1.5;
    }

    /* WM Flaggen */
    .wm-flags {
      display: flex;
      gap: .35rem;
      flex-wrap: wrap;
      margin-bottom: 1rem;
    }
    .wm-flag { font-size: 1.4rem; }

    /* ── Disclaimer ── */
    .disclaimer-section {
      max-width: 1100px;
      margin: 0 auto;
      padding: 0 1.5rem 4rem;
    }
    .disclaimer-box {
      background: linear-gradient(135deg, #1a0505, #1a0a0a);
      border: 1.5px solid rgba(220,38,38,.35);
      border-radius: 16px;
      padding: 2rem 2rem 1.75rem;
    }
    .disclaimer-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .disc-icon {
      background: #dc2626;
      border-radius: 10px;
      width: 44px; height: 44px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; flex-shrink: 0;
    }
    .disc-label { font-size: .65rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #fca5a5; margin-bottom: .15rem; }
    .disc-title { font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0; }
    .disc-lead { font-size: .88rem; color: rgba(255,255,255,.8); line-height: 1.75; margin-bottom: 1.25rem; }
    .disc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem; }
    @media (max-width: 600px) { .disc-grid { grid-template-columns: 1fr; } }
    .disc-item {
      background: rgba(0,0,0,.25);
      border-radius: 10px;
      padding: 1rem 1.1rem;
    }
    .disc-item-label { color: #fca5a5; font-weight: 700; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .5rem; }
    .disc-item p { margin: 0; font-size: .82rem; color: rgba(255,255,255,.7); line-height: 1.6; }
    .disc-item p strong { color: #fff; }
    .disc-haftung {
      background: rgba(220,38,38,.2);
      border: 1px solid rgba(252,165,165,.2);
      border-radius: 10px;
      padding: 1rem 1.1rem;
      font-size: .83rem;
      color: rgba(255,255,255,.8);
      line-height: 1.65;
      margin-bottom: 1rem;
    }
    .disc-haftung strong { color: #fff; }
    .disc-footer { font-size: .8rem; color: rgba(255,255,255,.45); line-height: 1.6; }
    .disc-footer strong { color: rgba(255,255,255,.6); }

    /* ── Footer ── */
    .site-footer {
      text-align: center;
      padding: 1.5rem;
      font-size: .78rem;
      color: #475569;
      border-top: 1px solid rgba(255,255,255,.06);
    }

    /* ── Animationen ── */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(28px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to   { opacity: 1; }
    }
    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50%       { transform: translateY(-5px); }
    }
    @keyframes glowPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
      50%       { box-shadow: 0 0 18px 4px rgba(16,185,129,.18); }
    }

    .hero-logo  { opacity: 0; animation: fadeIn  .6s ease forwards; }
    .hero h1    { opacity: 0; animation: fadeUp   .6s ease .15s forwards; }
    .hero-sub   { opacity: 0; animation: fadeUp   .6s ease .28s forwards; }

    .anim-card  { opacity: 0; animation: fadeUp   .55s cubic-bezier(.22,.68,0,1.2) forwards; }
    .anim-card:nth-child(1) { animation-delay: .35s; }
    .anim-card:nth-child(2) { animation-delay: .50s; }
    .anim-card:nth-child(3) { animation-delay: .65s; }
    .anim-card:nth-child(4) { animation-delay: .80s; }

    .anim-card:nth-child(1) .card-icon { animation: float 3.8s ease-in-out 1.2s infinite; }
    .anim-card:nth-child(2) .card-icon { animation: float 3.8s ease-in-out 1.7s infinite; }
    .anim-card:nth-child(3) .card-icon { animation: float 3.8s ease-in-out 2.2s infinite; }
    .anim-card:nth-child(4) .card-icon { animation: float 3.8s ease-in-out 2.7s infinite; }

    .card-rs:hover     { box-shadow: 0 16px 48px rgba(16,185,129,.28), 0 1px 0 rgba(16,185,129,.3) inset; }
    .card-options:hover { box-shadow: 0 16px 48px rgba(139,92,246,.22), 0 1px 0 rgba(139,92,246,.25) inset; }
  </style>
</head>
<body>

<!-- ── Hero ─────────────────────────────────────────────────────────────────── -->
<div class="hero">
  <div class="hero-logo">
    <svg width="36" height="28" viewBox="0 0 28 22" fill="none">
      <polyline points="2,18 8,12 14,15 22,5" stroke="#10b981" stroke-width="2.2"
        stroke-linecap="round" stroke-linejoin="round"/>
      <polyline points="18,4 23,4 23,9" stroke="#10b981" stroke-width="2.2"
        stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <span class="hero-logo-text">invest<span>signal</span>.de</span>
  </div>
  <h1>Willkommen bei investsignal.de</h1>
  <p class="hero-sub">Quantitative Finanztools, Strategieanalysen und interaktive Auswertungen — kostenlos und datengetrieben.</p>
</div>

<!-- ── Tool-Karten ───────────────────────────────────────────────────────────── -->
<div class="cards-section">
  <div class="row g-4">

    <!-- Karte 1: Relative Stärke -->
    <div class="col-lg-4 anim-card">
      <a class="tool-card card-rs"
         href="https://investsignal.de/landing.php?start_date=2024-01-01&universe=sp500">
        <div class="card-icon icon-rs">
          <svg width="28" height="22" viewBox="0 0 28 22" fill="none">
            <polyline points="2,18 8,12 14,15 22,5" stroke="#10b981" stroke-width="2.2"
              stroke-linecap="round" stroke-linejoin="round"/>
            <polyline points="18,4 23,4 23,9" stroke="#10b981" stroke-width="2.2"
              stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="card-label label-rs">Momentum-Strategie</div>
        <div class="card-title">Relative Stärke</div>
        <div class="card-desc">
          Regelbasierte Investmentstrategie nach dem Prinzip der <strong>Relativen Stärke nach Levy (RSL)</strong>:
          Die Aktien mit der stärksten Kursdynamik werden bevorzugt — monatlich neu bewertet für
          <strong>S&amp;P 500</strong>, <strong>DAX</strong>, <strong>HDAX</strong> und <strong>ETF Multi-Asset</strong>.
          <br><br>
          Inkl. interaktivem <strong>Backtest</strong> seit 2010, Portfolio-Simulation, RSL-Ranking
          und EUR/USD-Währungsansicht.
        </div>
        <span class="card-cta cta-rs">
          Zum Tool <i class="bi bi-arrow-right"></i>
        </span>
      </a>
    </div>

    <!-- Karte 2: Optionsrechner -->
    <div class="col-lg-4 anim-card">
      <a class="tool-card card-options" href="https://investsignal.de/Optionen/">
        <div class="card-icon icon-options">
          <svg viewBox="0 0 36 36" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <!-- x-axis -->
            <line x1="4" y1="26" x2="32" y2="26" stroke="#a78bfa" stroke-width="1.2" stroke-linecap="round" opacity=".35"/>
            <!-- hockey stick: flat then rising -->
            <polyline points="4,22 18,22 30,8" stroke="#a78bfa" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <!-- shaded profit area -->
            <polygon points="18,22 30,8 30,26 18,26" fill="#a78bfa" opacity=".18"/>
            <!-- strike dot -->
            <circle cx="18" cy="22" r="2" fill="#a78bfa"/>
          </svg>
        </div>
        <div class="card-label label-options">Neu</div>
        <div class="card-title">Optionsrechner</div>
        <p class="card-desc">
          Vier Strategien für aktive Optionshändler: <strong>Wheel-Strategie</strong> (Short Put → Zuteilung → Short Call) für systematische Prämieneinnahmen,
          <strong>PMCC</strong> (Poor Man's Covered Call) als kapitaleffiziente Covered-Call-Alternative mit LEAPS,
          <strong>Vol Crush</strong> für Earnings-Trades und <strong>LEAPS</strong> für langfristige Hebelrenditen.
        </p>
        <span class="card-cta cta-options">
          Zum Tool <i class="bi bi-arrow-right"></i>
        </span>
      </a>
    </div>

    <!-- Karte 3: ETF-Rechner -->
    <div class="col-lg-4 anim-card">
      <a class="tool-card card-etf" href="https://investsignal.de/ETF-Rechner/">
        <div class="card-icon icon-etf">
          <svg viewBox="0 0 36 36" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <!-- ascending cashflow bars -->
            <rect x="4"  y="22" width="5" height="8"  rx="1.5" fill="#60a5fa" opacity=".55"/>
            <rect x="11" y="17" width="5" height="13" rx="1.5" fill="#60a5fa" opacity=".7"/>
            <rect x="18" y="12" width="5" height="18" rx="1.5" fill="#60a5fa" opacity=".85"/>
            <rect x="25" y="7"  width="5" height="23" rx="1.5" fill="#60a5fa"/>
            <!-- trend arrow -->
            <polyline points="5,20 12,15 19,10 27,5" stroke="#60a5fa" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <polyline points="24,4 28,4 28,8" stroke="#60a5fa" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="card-label label-etf">Neu</div>
        <div class="card-title">ETF-Rechner</div>
        <p class="card-desc">
          Prognostiziert die <strong>Ausschüttungen mehrerer ETFs</strong> für die kommenden 12 Monate
          auf Basis echter Marktdaten — Kapital gleichmäßig verteilt, Cashflows monatlich im Überblick.
        </p>
        <span class="card-cta cta-etf">
          Zum Tool <i class="bi bi-arrow-right"></i>
        </span>
      </a>
    </div>

    <!-- Karte 4: WM 2026 Tippspiel -->
    <div class="col-lg-4 anim-card">
      <a class="tool-card card-wm"
         href="https://investsignal.de/wm2026/wm2026-tippspiel.html">
        <div class="card-icon icon-wm">
          <span style="font-size:1.6rem;">⚽</span>
        </div>
        <div class="card-label label-wm">Fußball &amp; Spaß</div>
        <div class="card-title">WM 2026 Tippspiel</div>
        <div class="wm-flags">
          <span class="wm-flag">🇺🇸</span>
          <span class="wm-flag">🇨🇦</span>
          <span class="wm-flag">🇲🇽</span>
          <span class="wm-flag">🇩🇪</span>
          <span class="wm-flag">🇧🇷</span>
          <span class="wm-flag">🏆</span>
        </div>
        <div class="card-desc">
          Interaktives <strong>Tippspiel zur FIFA Weltmeisterschaft 2026</strong> in den USA, Kanada
          und Mexiko. Alle 48 Gruppenspiele, K.o.-Runden und das Finale — tippe deine Favoriten
          und verfolge den Turnierverlauf live im Bracket.
        </div>
        <span class="card-cta cta-wm">
          Zum Tippspiel <i class="bi bi-arrow-right"></i>
        </span>
      </a>
    </div>

  </div>
</div>

<!-- ── Disclaimer ─────────────────────────────────────────────────────────────── -->
<div class="disclaimer-section">
  <div class="disclaimer-box">
    <div class="disclaimer-header">
      <div class="disc-icon">⚠️</div>
      <div>
        <div class="disc-label">Rechtlicher Hinweis</div>
        <div class="disc-title">Haftungsausschluss &amp; Risikohinweis</div>
      </div>
    </div>

    <p class="disc-lead">
      <strong>Diese Website und alle darin enthaltenen Informationen, Analysen, Berechnungen, Backtests und
      Darstellungen dienen ausschließlich zu Schulungs-, Illustrations- und Informationszwecken.</strong>
      Sie stellen in keiner Weise eine Anlageberatung, Anlageempfehlung, Aufforderung zum Kauf oder Verkauf
      von Wertpapieren, Finanzinstrumenten oder sonstigen Kapitalanlagen dar und ersetzen nicht die
      individuelle, professionelle Beratung durch einen zugelassenen Finanzberater oder eine regulierte Wertpapierfirma.
    </p>

    <div class="disc-grid">
      <div class="disc-item">
        <div class="disc-item-label">📉 Keine Garantie für Ergebnisse</div>
        <p>Vergangene Wertentwicklungen — einschließlich aller auf dieser Website dargestellten Backtests —
        sind <strong>kein verlässlicher Indikator für zukünftige Ergebnisse</strong>. Backtests bilden
        historische Daten nach und sind grundsätzlich mit Unsicherheiten behaftet. Reale Handelsergebnisse
        können erheblich von simulierten Ergebnissen abweichen.</p>
      </div>
      <div class="disc-item">
        <div class="disc-item-label">💸 Verlustrisiko</div>
        <p>Investitionen in Wertpapiere und Finanzinstrumente sind mit erheblichen Risiken verbunden,
        einschließlich des <strong>vollständigen Verlusts des eingesetzten Kapitals</strong>. Kursschwankungen,
        Marktrisiken, Währungsrisiken und politische Risiken können zu erheblichen Verlusten führen.</p>
      </div>
      <div class="disc-item">
        <div class="disc-item-label">🔬 Modellbeschränkungen</div>
        <p>Die dargestellten Strategien enthalten vereinfachende Modellannahmen (u.&nbsp;a. keine
        Transaktionskosten, keine Steuern, Bruchteilsaktien, idealisierte Ausführungspreise).
        <strong>In der Praxis führen diese Faktoren zu abweichenden Ergebnissen.</strong></p>
      </div>
      <div class="disc-item">
        <div class="disc-item-label">⚖️ Keine Anlageberatung</div>
        <p>Diese Website ist <strong>nicht als Finanzdienstleistung im Sinne des WpHG oder der
        EU-Richtlinie MiFID&nbsp;II</strong> zu verstehen. Der Betreiber ist kein zugelassener
        Anlageberater. Es besteht keine Erlaubnis der BaFin oder einer anderen Aufsichtsbehörde.</p>
      </div>
    </div>

    <div class="disc-haftung">
      <strong>Haftungsausschluss:</strong> Der Betreiber dieser Website übernimmt <strong>keinerlei Haftung</strong>
      für Verluste oder Schäden — gleich welcher Art —, die direkt oder indirekt aus der Nutzung der auf dieser
      Website bereitgestellten Informationen entstehen. Sämtliche Entscheidungen, die auf Basis der hier
      dargestellten Informationen getroffen werden, liegen ausschließlich in der Verantwortung des Nutzers.
    </div>

    <p class="disc-footer">
      <strong>Datenqualität:</strong> Alle Kursdaten werden automatisiert über die Yahoo Finance API bezogen.
      Trotz sorgfältiger Verarbeitung können Datenausfälle, Kurslücken oder fehlerhafte Splits auftreten.
      Der Betreiber übernimmt keine Gewähr für die Vollständigkeit, Richtigkeit oder Aktualität der Daten.
      — <strong>Vor jeder Anlageentscheidung</strong> sollten Sie Ihre persönliche finanzielle Situation,
      Ihre Risikobereitschaft und Ihre Anlageziele sorgfältig prüfen und ggf. einen unabhängigen,
      zugelassenen Finanzberater konsultieren.
    </p>
  </div>
</div>

<!-- ── Footer ─────────────────────────────────────────────────────────────────── -->
<footer class="site-footer">
  &copy; <?= date('Y') ?> investsignal.de — Alle Angaben ohne Gewähr.
</footer>

</body>
</html>
