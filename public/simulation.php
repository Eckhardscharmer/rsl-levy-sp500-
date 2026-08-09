<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

// ── Universe ───────────────────────────────────────────────────────────────
$universe = $_GET['universe'] ?? 'sp500';
if (!in_array($universe, ['sp500', 'dax', 'hdax', 'etf'])) $universe = 'sp500';
$isDax        = ($universe === 'dax');
$isHdax       = ($universe === 'hdax');
$isEtf        = ($universe === 'etf');
$isEurUniverse = ($isDax || $isHdax || $isEtf);

// ── M&A-Flags (aktuell aktive Übernahme-Kandidaten) ────────────────────────
$maFlagged = [];
foreach ($db->query('SELECT ticker, headline FROM m_and_a_flags WHERE is_active = 1 AND (expires_date IS NULL OR expires_date > CURDATE())')
             ->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $maFlagged[$row['ticker']] = $row['headline'];
}

// ── Parameter ──────────────────────────────────────────────────────────────
$startCapital = max(1000, (float)($_GET['capital'] ?? 50000));
// ETF: Standard 50.000 EUR, aber überschreibbar
if ($isEtf && !isset($_GET['capital'])) $startCapital = 50000.0;
// DAX: Kapital auf 10.000er runden
elseif ($isDax || $isHdax) $startCapital = max(10000, round($startCapital / 10000) * 10000);
$minDate      = $isEtf ? '2000-01-31' : '2010-01-04';
$startDate    = $_GET['start_date'] ?? ($isEtf ? '2024-01-01' : '2024-01-01');
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
            r.is_selected, COALESCE(s.name, r.ticker) AS company
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

// ── EUR/USD-Raten für alle Snapshot-Daten (nur S&P 500) ────────────────────
$startEurUsdSim = 1.10; // EUR/USD am ersten Sim-Tag
$eurRateByDate  = [];   // date → EUR/USD (für JS-Anzeige)
if (!$isEurUniverse && !empty($sundays)) {
    $firstDay = $sundays[0];
    $lastDay  = end($sundays);
    $stmtEurRange = $db->prepare(
        "SELECT price_date, adj_close FROM prices
         WHERE ticker='EURUSD=X' AND price_date BETWEEN DATE_SUB(?, INTERVAL 14 DAY) AND ?
         ORDER BY price_date ASC"
    );
    $stmtEurRange->execute([$firstDay, $lastDay]);
    $eurPrices = $stmtEurRange->fetchAll(PDO::FETCH_KEY_PAIR); // date => rate
    $eurDates  = array_keys($eurPrices);
    $eLen      = count($eurDates);
    $eIdx      = 0;
    $lastRate  = 1.10;
    foreach ($sundays as $sunday) {
        while ($eIdx < $eLen && $eurDates[$eIdx] <= $sunday) {
            $lastRate = (float)$eurPrices[$eurDates[$eIdx]];
            $eIdx++;
        }
        $eurRateByDate[$sunday] = $lastRate;
    }
    $startEurUsdSim = reset($eurRateByDate) ?: 1.10;
}

// ── Simulation ─────────────────────────────────────────────────────────────
const TOP_N_SIM = 5;

// S&P 500: Startkapital von EUR in USD umrechnen (50.000 EUR × startEurUsdSim = ~55.000 USD)
// DAX/HDAX/ETF: bereits EUR-nativ → keine Umrechnung
$cash                = $isEurUniverse ? $startCapital : $startCapital * $startEurUsdSim;
$holdings            = [];
$snapshots           = [];
$lastSnapshotMktVals = [];

// WKN-Mapping für ETF-Instrumente (Index-Ticker → WKN des real handelbaren ETF)
$etfWknMap = [
    '^GSPC'  => 'A0YEDG',   // iShares Core S&P 500 UCITS ETF (SXR8)
    '^NDX'   => '801498',   // Invesco EQQQ Nasdaq-100 UCITS ETF
    '^STOXX' => '263530',   // iShares STOXX Europe 600 UCITS ETF (EXSA)
    '^N225'  => 'DBX0NJ',   // Xtrackers Nikkei 225 UCITS ETF
    'EEM'    => 'A12GVR',   // Xtrackers MSCI Emerging Markets UCITS ETF (XMME)
    'GC=F'   => 'A0S9GB',   // Xetra-Gold
    'AGG'    => 'A0RGEM',   // iShares Global Govt Bond UCITS ETF (IGLO)
    'SHY'    => 'DBX0AN',   // Xtrackers EUR Overnight Rate Swap ETF (XEON)
];

if ($isEtf) {
    // ETF: monatliches Full-Rebalancing, Top 3 (is_selected), gleiche Gewichtung
    $etfNameMap = [
        '^GSPC'  => 'USA Large Caps (S&P 500)',  '^NDX'   => 'USA Wachstum (Nasdaq-100)',
        '^STOXX' => 'Europa (STOXX 600)',         '^N225'  => 'Japan (Nikkei 225)',
        'EEM'    => 'Emerging Markets',           'GC=F'   => 'Gold',
        'AGG'    => 'Staatsanleihen',             'SHY'    => 'Cash / Geldmarkt',
    ];
    foreach ($sundays as $i => $monthEnd) {
        $monthRankings = $byDate[$monthEnd];
        $rankByTicker  = array_column($monthRankings, null, 'ticker');

        $targetTickers = [];
        foreach ($monthRankings as $r) {
            if ($r['is_selected']) $targetTickers[$r['ticker']] = $r;
        }

        // Realisierter GuV: Gesamtwert vor Rebalancing
        $totalBefore = $cash;
        $exitedThisMonth = [];
        foreach ($holdings as $ticker => $h) {
            $price = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            $mktVal = $h['shares'] * $price;
            $totalBefore += $mktVal;
            if (!isset($targetTickers[$ticker])) {
                $costBasis = $h['buy_price'] * $h['shares'];
                $exitedThisMonth[$ticker] = ['net_proceeds' => $mktVal, 'realized_pnl' => $mktVal - $costBasis];
            }
        }

        $prevHoldings = array_keys($holdings);
        $cash     = $totalBefore;
        $holdings = [];

        $newThisMonth = [];
        $n = count($targetTickers);
        if ($n > 0) {
            $perSlot = $cash / $n;
            foreach ($targetTickers as $ticker => $r) {
                $price = (float)$r['current_price'];
                if ($price <= 0) continue;
                $cash -= $perSlot;
                $holdings[$ticker] = [
                    'shares'    => $perSlot / $price,
                    'buy_price' => $price,
                    'sector'    => $r['sector'] ?? '',
                    'rsl_buy'   => (float)$r['rsl'],
                    'company'   => $etfNameMap[$ticker] ?? $ticker,
                ];
                if (!in_array($ticker, $prevHoldings)) $newThisMonth[] = $ticker;
            }
        }

        $invested = 0;
        $snap     = [];
        foreach ($holdings as $ticker => $h) {
            $price  = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            $mktVal = $h['shares'] * $price;
            $invested += $mktVal;
            $snap[$ticker] = [
                'ticker'    => $ticker,
                'company'   => $h['company'],
                'sector'    => $h['sector'],
                'mkt_val'   => $mktVal,
                'buy_price' => $h['buy_price'],
                'shares'    => $h['shares'],
                'rsl'       => (float)($rankByTicker[$ticker]['rsl'] ?? $h['rsl_buy']),
                'rank'      => (int)($rankByTicker[$ticker]['rank_overall'] ?? 99),
            ];
        }
        $portfolioValue = $cash + $invested;
        foreach ($snap as &$s) {
            $s['weight'] = $portfolioValue > 0 ? $s['mkt_val'] / $portfolioValue * 100 : 0;
        }
        unset($s);
        foreach ($snap as $ticker => $s) $lastSnapshotMktVals[$ticker] = $s['mkt_val'];

        $isLast = ($i === count($sundays) - 1);
        if (!empty($newThisMonth) || !empty($exitedThisMonth) || $isLast) {
            $snapshots[] = [
                'date'      => $monthEnd,
                'holdings'  => $snap,
                'new'       => $newThisMonth,
                'exited'    => $exitedThisMonth,
                'pv'        => $portfolioValue,
                'no_change' => empty($newThisMonth) && empty($exitedThisMonth),
            ];
        }
    }
} else {
    // S&P 500 / DAX: wöchentlich, Top 5, Sektordiversifikation
    foreach ($sundays as $i => $sunday) {
        $weekRankings = $byDate[$sunday];
        $rankByTicker = array_column($weekRankings, null, 'ticker');

        $saleProceeds   = [];
        $exitedThisWeek = [];

        $holdRank = $isHdax ? 25 : ($isDax ? ($sunday >= '2021-09-20' ? 10 : 7) : 125);
        foreach (array_keys($holdings) as $ticker) {
            $rank = isset($rankByTicker[$ticker])
                ? (int)$rankByTicker[$ticker]['rank_overall']
                : PHP_INT_MAX;
            if ($rank > $holdRank) {
                $price     = (float)($rankByTicker[$ticker]['current_price'] ?? $holdings[$ticker]['buy_price']);
                $shares    = $holdings[$ticker]['shares'];
                $gross     = $shares * $price;
                $net       = $gross;
                $cash     += $net;
                $saleProceeds[] = $net;
                $costBasis = $holdings[$ticker]['buy_price'] * $shares;
                $exitedThisWeek[$ticker] = ['net_proceeds' => $net, 'realized_pnl' => $net - $costBasis];
                unset($holdings[$ticker]);
            }
        }

        $vacancies   = TOP_N_SIM - count($holdings);
        $heldSectors = array_column(array_values($holdings), 'sector');
        $cashPerSlot = $vacancies > 0 ? $cash / $vacancies : 0;
        $newThisWeek = [];

        foreach ($weekRankings as $stock) {
            if ($vacancies <= 0) break;
            if (isset($holdings[$stock['ticker']])) continue;
            if (isset($maFlagged[$stock['ticker']])) continue;
            $sector = $stock['sector'] ?? 'Unknown';
            if (in_array($sector, $heldSectors)) continue;
            $price = (float)$stock['current_price'];
            if ($price <= 0) continue;
            $slotBudget = !empty($saleProceeds) ? array_shift($saleProceeds) : $cashPerSlot;
            if ($slotBudget < 1) continue;
            $shares = $slotBudget / $price;
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

        $invested = 0;
        $snap     = [];
        foreach ($holdings as $ticker => $h) {
            $price   = (float)($rankByTicker[$ticker]['current_price'] ?? $h['buy_price']);
            $mktVal  = $h['shares'] * $price;
            $invested += $mktVal;
            $snap[$ticker] = [
                'ticker'    => $ticker,
                'company'   => $rankByTicker[$ticker]['company'] ?? $h['company'],
                'sector'    => $h['sector'],
                'mkt_val'   => $mktVal,
                'buy_price' => $h['buy_price'],
                'shares'    => $h['shares'],
                'rsl'       => (float)($rankByTicker[$ticker]['rsl'] ?? $h['rsl_buy']),
                'rank'      => (int)($rankByTicker[$ticker]['rank_overall'] ?? 999),
            ];
        }
        $portfolioValue = $cash + $invested;
        foreach ($snap as &$s) {
            $s['weight'] = $portfolioValue > 0 ? $s['mkt_val'] / $portfolioValue * 100 : 0;
        }
        unset($s);
        uasort($snap, fn($a, $b) => $b['rsl'] <=> $a['rsl']);
        foreach ($snap as $ticker => $s) $lastSnapshotMktVals[$ticker] = $s['mkt_val'];

        $isLast = ($i === count($sundays) - 1);
        if (!empty($newThisWeek) || !empty($exitedThisWeek) || $isLast) {
            $snapshots[] = [
                'date'      => $sunday,
                'holdings'  => $snap,
                'new'       => $newThisWeek,
                'exited'    => $exitedThisWeek,
                'pv'        => $portfolioValue,
                'no_change' => empty($newThisWeek) && empty($exitedThisWeek),
            ];
        }
    }
}

$simLastPv = !empty($snapshots) ? (float)end($snapshots)['pv'] : $startCapital;
$snapshots = array_reverse($snapshots);   // neueste zuerst

// ── Excel-Export-Payload (alle Snapshots + Annahmen) ─────────────────────
// $snapshots ist bereits umgekehrt (neueste zuerst) — für Excel drehen wir zurück (älteste zuerst)
$xlSnapshots = [];
foreach (array_reverse($snapshots) as $snap) {
    $newSet = array_flip($snap['new']);
    $rows   = [];
    foreach ($snap['holdings'] as $ticker => $h) {
        $buyP   = isset($h['buy_price']) ? round((float)$h['buy_price'], 4) : null;
        $sharesV= isset($h['shares'])    ? round((float)$h['shares'], 6)    : null;
        $curP   = ($sharesV && $sharesV > 0) ? round($h['mkt_val'] / $sharesV, 4)  : null;
        $pnlAbs = ($buyP !== null && $sharesV !== null) ? round(($curP - $buyP) * $sharesV, 2) : null;
        $pnlPct = ($buyP !== null && $buyP > 0) ? round(($curP / $buyP - 1) * 100, 2) : null;
        $displayId = $isEtf ? ($etfWknMap[$ticker] ?? $ticker) : $ticker;
        $rows[] = [
            'status'   => isset($newSet[$ticker]) ? 'Neu' : 'Gehalten',
            'id'       => $displayId,
            'name'     => $h['company'],
            'sektor'   => $h['sector'],
            'kaufkurs' => $buyP,
            'aktkurs'  => $curP,
            'anteile'  => $sharesV,
            'marktwert'=> round($h['mkt_val'], 2),
            'gewicht'  => round($h['weight'], 2),
            'rsl'      => round($h['rsl'], 4),
            'rang'     => $h['rank'],
            'guv_abs'  => $pnlAbs,
            'guv_pct'  => $pnlPct,
        ];
    }
    foreach ($snap['exited'] as $ticker => $exitData) {
        $displayId = $isEtf ? ($etfWknMap[$ticker] ?? $ticker) : $ticker;
        if ($isEtf) {
            $exitName = $etfNameMap[$ticker] ?? $ticker;
        } else {
            $stmtN = $db->prepare("SELECT name FROM stocks WHERE ticker = ? LIMIT 1");
            $stmtN->execute([$ticker]);
            $exitName = $stmtN->fetchColumn() ?: $ticker;
        }
        $rows[] = [
            'status'   => 'Ausgeschieden',
            'id'       => $displayId,
            'name'     => $exitName, 'sektor' => '', 'kaufkurs' => null, 'aktkurs' => null,
            'anteile'  => null, 'marktwert' => round($exitData['net_proceeds'], 2),
            'gewicht'  => 0,   'rsl' => null, 'rang' => null,
            'guv_abs'  => round($exitData['realized_pnl'], 2), 'guv_pct' => null,
        ];
    }
    $xlSnapshots[] = [
        'date'      => $snap['date'],
        'pv'        => round($snap['pv'], 2),
        'no_change' => $snap['no_change'],
        'rows'      => $rows,
        'eur_rate'  => $eurRateByDate[$snap['date']] ?? $startEurUsdSim,
    ];
}
$latestSnap = !empty($snapshots) ? $snapshots[0] : null;
$xlMeta = [
    'universum'     => $isEtf ? 'ETF Multi-Asset' : ($isHdax ? 'HDAX' : ($isDax ? 'DAX' : 'S&P 500')),
    'startdatum'    => $startDate,
    'startkapital'  => $startCapital,
    'waehrung'      => $isEurUniverse ? 'EUR' : 'USD',
    'portfoliowert' => round($simLastPv, 2),
    'rendite_pct'   => round($changePct, 2),
];
$xlExport = json_encode(['snapshots' => $xlSnapshots, 'meta' => $xlMeta], JSON_UNESCAPED_UNICODE);

// Rendite und Kapitalstand direkt aus der Simulation (frischer Start ab $startDate)
$simStartUsd  = $isEurUniverse ? $startCapital : $startCapital * $startEurUsdSim;
$changePct    = $simStartUsd > 0 ? ($simLastPv - $simStartUsd) / $simStartUsd * 100 : 0;
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
<title>Simulation — Relative Stärke nach Levy</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: #0a0f1e; color: #e2e8f0; font-family: 'Inter', sans-serif; }
  .navbar { background: #0f172a !important; border-bottom: 1px solid #1e2d4a; box-shadow: 0 2px 12px rgba(0,0,0,.3); min-height: 56px; }
  .navbar .container-fluid { min-height: 56px; height: auto; }
  .navbar .navbar-brand { color: #fff !important; font-weight: 700; padding: 0; }
  .navbar .nav-link { color: rgba(255,255,255,.6) !important; padding: .375rem .65rem !important; font-size: .875rem; }
  .navbar .nav-link:hover { color: #fff !important; }
  .card { background: #111827; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; }
  .card-header { background: #0f172a; border-bottom: 1px solid rgba(255,255,255,.08); color: #f1f5f9; }
  .nav-link.active { color: #fff !important; font-weight: 600; }
  html { overflow-y: scroll; }
  .currency-toggle { background: rgba(255,255,255,.1); border-radius: 20px; padding: 2px; display: flex; align-items: center; }
  .cur-btn { background: transparent; border: none; color: rgba(255,255,255,.45); font-size: .75rem; font-weight: 700; padding: .2rem .65rem; border-radius: 18px; cursor: pointer; transition: all .15s; line-height: 1.6; }
  .cur-btn.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.4); }

  /* Sidebar */
  .sidebar-sticky { position: sticky; top: 1.5rem; }
  .kpi-label { font-size: .72rem; text-transform: uppercase; color: rgba(255,255,255,.4); letter-spacing: .04em; margin-bottom: .15rem; }
  .kpi-value { font-size: 1.5rem; font-weight: 700; line-height: 1.1; color: #f1f5f9; }
  .kpi-box { padding: 1rem 1.1rem; border-radius: 10px; background: #1e293b; border: 1px solid rgba(255,255,255,.08); }

  /* Portfolio-Karten */
  .portfolio-card { margin-bottom: 1rem; }
  .portfolio-header { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; padding: .65rem 1rem; border-bottom: 1px solid rgba(255,255,255,.07); background: #0f172a; border-radius: 12px 12px 0 0; }
  .portfolio-date { font-weight: 700; font-size: .92rem; color: #f1f5f9; }
  .badge-positions { background: rgba(255,255,255,.1); color: rgba(255,255,255,.7); font-size: .72rem; font-weight: 600; padding: .28em .65em; border-radius: 20px; }
  .badge-new-exit { background: rgba(74,222,128,.15); color: #4ade80; font-size: .72rem; font-weight: 600; padding: .28em .65em; border-radius: 20px; }
  .badge-exit-only { background: rgba(248,113,113,.15); color: #f87171; font-size: .72rem; font-weight: 600; padding: .28em .65em; border-radius: 20px; }
  .badge-nochange { background: rgba(255,255,255,.07); color: rgba(255,255,255,.4); font-size: .72rem; font-weight: 600; padding: .28em .65em; border-radius: 20px; }
  .change-badges { margin-left: auto; display: flex; gap: .35rem; }

  .ptable { font-size: .84rem; margin-bottom: 0; --bs-table-bg: transparent; --bs-table-color: #e2e8f0; }
  .ptable th { font-size: .70rem; text-transform: uppercase; color: rgba(255,255,255,.4); font-weight: 700; letter-spacing: .05em; border-color: rgba(255,255,255,.06) !important; padding: .45rem .75rem; background: #0f172a; }
  .ptable td { border-color: rgba(255,255,255,.06) !important; vertical-align: middle; padding: .45rem .75rem; color: #e2e8f0; background: inherit; }
  .text-success { color: #4ade80 !important; }
  .text-danger  { color: #f87171 !important; }
  .alert-info   { background: rgba(37,99,235,.15); border-color: rgba(37,99,235,.3); color: #93c5fd; }

  .row-normal { background: #111827; }
  .row-new    { background: rgba(74,222,128,.07); }
  .row-exited { background: rgba(248,113,113,.05); color: rgba(255,255,255,.4); }

  .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
  .dot-new   { background: #4ade80; }
  .dot-exit  { background: #f87171; }
  .dot-hold  { background: rgba(255,255,255,.25); }

  .ticker-badge { font-weight: 700; font-size: .82rem; letter-spacing: .02em; }
  .rsl-pill { background: rgba(255,255,255,.1); border-radius: 6px; padding: .15em .5em; font-size: .8rem; font-weight: 600; color: #e2e8f0; }

  /* Portfolio-Banner */
  .portfolio-banner { border-radius: 10px; padding: .75rem 1.1rem; }
  .portfolio-banner.current { background: linear-gradient(135deg, #052e16, #14532d); }
  .portfolio-banner.start   { background: linear-gradient(135deg, #0f172a, #1e3a5f); }
  .banner-title { font-size: 1.1rem; font-weight: 800; letter-spacing: -.01em; color: #fff; }

  /* ── Mobile ─────────────────────────────────────────────────────────── */
  @media (max-width: 768px) {
    .container-fluid { padding-left: .75rem !important; padding-right: .75rem !important; }
    .ptable { min-width: 560px; font-size: .78rem; }
    .ptable th, .ptable td { padding: .35rem .45rem; }
    .ptable tfoot td { padding: .4rem .45rem; }
    .rsl-pill { font-size: .72rem; padding: .1em .35em; }
    .ticker-badge { font-size: .76rem; }
    .portfolio-header { padding: .5rem .75rem; gap: .35rem; }
    .portfolio-date { font-size: .85rem; }
    .badge-new-exit, .badge-exit-only, .badge-nochange, .badge-positions { font-size: .67rem; padding: .22em .5em; }
    .banner-title { font-size: .95rem; }
    .portfolio-banner { padding: .6rem .85rem; }
    .kpi-value { font-size: 1.2rem; }
    .card-body { padding: .75rem !important; }
  }
  @media (max-width: 480px) {
    .ptable { min-width: 520px; font-size: .74rem; }
  }

  /* Schieberegler */
  .sim-slider { -webkit-appearance:none; appearance:none; width:100%; height:5px;
    border-radius:3px; background:rgba(255,255,255,.1); outline:none; cursor:pointer; margin:6px 0 2px; }
  .sim-slider::-webkit-slider-thumb { -webkit-appearance:none; appearance:none;
    width:16px; height:16px; border-radius:50%; background:#2563eb;
    border:2px solid #0a0f1e; box-shadow:0 1px 4px rgba(37,99,235,.4); cursor:pointer; }
  .sim-slider::-moz-range-thumb { width:16px; height:16px; border-radius:50%;
    background:#2563eb; border:2px solid #0a0f1e; box-shadow:0 1px 4px rgba(37,99,235,.4);
    cursor:pointer; }
  .slider-limits { display:flex; justify-content:space-between;
    font-size:.67rem; color:#93c5fd; margin-top:1px; }
  .form-control, .form-select { background: #1e293b; border-color: rgba(255,255,255,.12); color: #f1f5f9; }
  .form-control:focus, .form-select:focus { background: #1e293b; border-color: #3b82f6; color: #f1f5f9; box-shadow: none; }
  .text-muted { color: rgba(255,255,255,.4) !important; }
  hr { border-color: rgba(255,255,255,.07); }
</style>
</head>
<body>
<?php $activePage = 'simulation'; include __DIR__ . '/inc_navbar.php'; ?>

<div class="container-fluid px-4 py-4">
  <div class="row g-4 align-items-start">

    <!-- ── Linke Spalte: Eingabe + Ergebnis ─────────────────────────────── -->
    <div class="col-lg-3">
      <div class="sidebar-sticky">
        <div class="card" style="border:1px solid rgba(37,99,235,.4); box-shadow:0 4px 20px rgba(37,99,235,.12);">
          <div class="card-header px-3 py-2" style="background:#0f172a;border-bottom:1px solid rgba(37,99,235,.3);">
            <i class="bi bi-sliders me-1" style="color:#60a5fa;"></i>
            <span style="font-size:.82rem;font-weight:700;color:#93c5fd;">Simulation konfigurieren</span>
          </div>
          <div class="card-body p-3">
            <form method="get" action="simulation.php">
              <input type="hidden" name="universe" value="<?= htmlspecialchars($universe) ?>">
              <input type="hidden" name="capital" id="inputCapital"
                     value="<?= number_format($startCapital, 0, '.', '') ?>">

              <!-- Startkapital -->
              <div class="mb-3">
                <label class="form-label" style="font-size:.78rem;font-weight:700;color:#93c5fd;letter-spacing:.03em;text-transform:uppercase;">
                  <i class="bi bi-cash-coin me-1"></i>Startkapital
                </label>
                <div class="input-group">
                  <span class="input-group-text" id="capital-currency-label"
                        style="background:#1e293b;border-color:rgba(255,255,255,.12);color:#93c5fd;font-weight:700;font-size:.9rem;"><?= $isEurUniverse ? '€' : '$' ?></span>
                  <input type="text" class="form-control" id="inputCapitalDisplay"
                         value="<?= number_format($startCapital, 0, ',', '.') ?>"
                         style="font-size:1.15rem;font-weight:700;text-align:right;border-color:rgba(255,255,255,.12);color:#f1f5f9;letter-spacing:.01em;"
                         autocomplete="off" inputmode="numeric">
                </div>
                <input type="range" id="sliderCapital" class="sim-slider"
                       min="10000" max="250000" step="10000"
                       value="<?= (int)$startCapital ?>">
                <div class="slider-limits"><span>10.000</span><span>250.000</span></div>
              </div>

              <!-- Start Strategie -->
              <div class="mb-3">
                <label class="form-label" style="font-size:.78rem;font-weight:700;color:#93c5fd;letter-spacing:.03em;text-transform:uppercase;">
                  <i class="bi bi-calendar3 me-1"></i>Start Strategie
                </label>
                <input type="date" class="form-control" name="start_date" id="inputStartDate"
                       value="<?= htmlspecialchars($startDate) ?>"
                       min="<?= $minDate ?>" max="<?= $maxDate ?>"
                       style="border-color:rgba(255,255,255,.12);font-size:.9rem;">
                <input type="range" id="sliderDate" class="sim-slider"
                       min="0" max="<?= $sliderMaxDays ?>" step="7"
                       value="<?= $sliderCurDays ?>">
                <div class="slider-limits">
                  <span><?= date('d.m.Y', strtotime($minDate)) ?></span>
                  <span><?= date('d.m.Y', strtotime($maxDate)) ?></span>
                </div>
              </div>

            </form>

            <!-- Excel-Download -->
            <?php if (!empty($snapshots)): ?>
            <button onclick="downloadXlsx()"
              style="width:100%;margin-top:.75rem;display:flex;align-items:center;justify-content:center;gap:.45rem;
                     background:#16a34a;color:#fff;font-size:.82rem;font-weight:600;
                     padding:.45rem .9rem;border-radius:8px;border:none;cursor:pointer;
                     box-shadow:0 1px 4px rgba(22,163,74,.25);transition:background .15s;"
              onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
              <i class="bi bi-file-earmark-excel" style="font-size:1rem;"></i>
              Alle Portfolios als Excel
            </button>
            <?php endif; ?>

            <hr style="border-color:rgba(255,255,255,.1);margin:1rem 0;">

            <!-- Ergebnis -->
            <div class="kpi-box mb-2" style="background:rgba(74,222,128,.1);border-color:rgba(74,222,128,.25);">
              <div class="kpi-label">Kapitalstand</div>
              <div class="kpi-value <?= $finalCapital >= $startCapital ? 'text-success' : 'text-danger' ?>" id="kpi-kapital-val" data-usd="<?= round($finalCapital) ?>">
                <?= number_format($finalCapital, 0, ',', '.') ?>
                <small style="font-size:.8rem;" id="kpi-kapital-sym"><?= $isEurUniverse ? 'EUR' : 'USD' ?></small>
              </div>
            </div>

            <div class="kpi-box" style="background:rgba(74,222,128,.1);border-color:rgba(74,222,128,.25);">
              <div class="kpi-label">Veränderung <span id="kpi-change-curr" style="font-size:.65rem;text-transform:none;letter-spacing:0;opacity:.75;"></span></div>
              <div class="kpi-value" id="kpi-change-val"
                   data-pct-usd="<?= round($changePct, 4) ?>"
                   data-final-usd="<?= round($finalCapital) ?>"
                   data-start-usd="<?= round($isEurUniverse ? $startCapital : $startCapital * $startEurUsdSim) ?>">
              </div>
              <div style="font-size:.72rem;color:rgba(255,255,255,.4);margin-top:.25rem;">
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
        // Delta = Veränderung gegenüber vorherigem (älterem) Snapshot; beim Start-Portfolio = 0
        $prevPv    = $isLast ? $snap['pv'] : $snapshots[$snapIdx + 1]['pv'];
        $weekDelta = round($snap['pv'] - $prevPv);
        // Vorherige Holdings als Lookup: ticker → mkt_val
        $prevHoldings = $isLast ? [] : ($snapshots[$snapIdx + 1]['holdings'] ?? []);
      ?>

      <?php if ($isFirst): ?>
      <div style="border:2px solid rgba(74,222,128,.4); border-radius:16px; padding:10px; margin-bottom:1.5rem;">
        <div class="portfolio-banner current mb-2">
          <div class="banner-title">Aktuelles Portfolio</div>
        </div>
      <?php elseif ($isLast && $totalSnaps > 1): ?>
      <div style="border:2px solid rgba(37,99,235,.4); border-radius:16px; padding:10px; margin-top:2rem;">
        <div class="portfolio-banner start mb-2">
          <div class="banner-title">Start-Portfolio</div>
        </div>
      <?php else: ?>
      <div>
      <?php endif; ?>

      <div class="card portfolio-card" data-snap-date="<?= $snap['date'] ?>" style="margin-bottom:0;">

        <!-- Header -->
        <div class="portfolio-header">
          <i class="bi bi-calendar3 text-muted" style="font-size:.85rem;"></i>
          <span class="portfolio-date"><?= dateDe($snap['date']) ?></span>

          <div class="change-badges ms-auto">
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
          <table class="table ptable" style="table-layout:fixed;width:100%;min-width:<?= $isEtf ? '700px' : '580px' ?>;">
            <colgroup>
              <col style="width:28px;">
              <col style="width:9%;">
              <col><!-- flex -->
              <col style="width:<?= $isEtf ? '15%' : '26%' ?>;">
              <col style="width:8%;">
              <col style="width:11%;">
              <col style="width:8%;">
              <?php if ($isEtf): ?><col style="width:12%;"><?php endif; ?>
              <col style="width:8%;">
            </colgroup>
            <thead>
              <tr>
                <th style="width:28px;"></th>
                <th><?= $isEtf ? 'WKN' : 'Ticker' ?></th>
                <th><?= $isEtf ? 'ETF' : 'Unternehmen' ?></th>
                <th>Sektor</th>
                <th class="text-end" style="white-space:nowrap;">Gewicht</th>
                <th class="text-end sim-th-betrag">Betrag in <?= $isEurUniverse ? 'EUR' : 'USD' ?></th>
                <th class="text-end <?= $isFirst ? 'sim-th-tooltip' : '' ?>"
                    <?php if ($isFirst): ?>data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="Kursveränderung dieser Periode: wie viel der Positionswert durch Kursbewegungen gestiegen oder gefallen ist."<?php endif; ?>>GuV<?= $isFirst ? ' <span style="font-size:.7rem;opacity:.5;font-weight:400;">ⓘ</span>' : '' ?></th>
                <?php if ($isEtf): ?><th class="text-end <?= $isFirst ? 'sim-th-tooltip' : '' ?>" style="white-space:nowrap;color:rgba(255,255,255,.5);"
                    <?php if ($isFirst): ?>data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="Kapitalzufluss durch Umschichtung: ein positiver Betrag bedeutet, dass dieser Betrag in die neue Position investiert (gekauft) werden soll."<?php endif; ?>>Rebalancing<?= $isFirst ? ' <span style="font-size:.7rem;opacity:.5;font-weight:400;">ⓘ</span>' : '' ?></th><?php endif; ?>
                <th class="text-end">RSL Score</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $snapGuvSum = 0; $snapRebalSum = 0;
                foreach ($snap['holdings'] as $h):
                $isNew    = isset($newSet[$h['ticker']]);
                $rowClass = $isNew ? 'row-new' : 'row-normal';
                $displayId = $isEtf ? ($etfWknMap[$h['ticker']] ?? $h['ticker']) : $h['ticker'];
                // Perioden-Delta: Vergleich mit vorherigem Snapshot
                $hGuv = null; $hGuvRebal = null;
                if (!$isLast) {
                    if ($isNew && $isEtf) {
                        // Neue ETF-Position: GuV=0, Rebalancing=investierter Betrag
                        $hGuv = 0;
                        $hGuvRebal = round($h['mkt_val'], 0);
                    } elseif ($isNew) {
                        $hGuv = 0;
                    } else {
                        $prevH = $prevHoldings[$h['ticker']] ?? null;
                        if ($prevH !== null) {
                            $hGuv = round($h['mkt_val'] - $prevH['mkt_val'], 0);
                        }
                    }
                }
                if ($hGuv !== null)      $snapGuvSum   += $hGuv;
                if ($hGuvRebal !== null) $snapRebalSum += $hGuvRebal;
              ?>
              <tr class="<?= $rowClass ?>">
                <td class="text-center ps-3">
                  <?php if ($isNew): ?>
                    <span class="dot dot-new"></span>
                  <?php else: ?>
                    <span class="dot dot-hold"></span>
                  <?php endif; ?>
                </td>
                <td><span class="ticker-badge" <?= $isNew ? 'style="color:#4ade80;"' : '' ?>><?= htmlspecialchars($displayId) ?></span></td>
                <td style="color:<?= $isNew ? '#4ade80' : '#e2e8f0' ?>;<?= $isNew ? 'font-weight:600;' : '' ?>"><?= htmlspecialchars($h['company']) ?></td>
                <td style="color:<?= $isNew ? '#4ade80' : 'rgba(255,255,255,.4)' ?>;font-size:.80rem;"><?= htmlspecialchars($h['sector']) ?></td>
                <td class="text-end" style="color:#e2e8f0;"><?= number_format($h['weight'], 1, ',', '.') ?>%</td>
                <td class="text-end sim-mkt-val" style="color:#e2e8f0;" data-usd="<?= round($h['mkt_val']) ?>"><?= number_format($h['mkt_val'], 0, ',', '.') ?></td>
                <td class="text-end sim-guv-val" data-usd="<?= $hGuv ?? '' ?>"
                    style="font-weight:600;color:<?= $hGuv === null ? 'rgba(255,255,255,.4)' : ($hGuv >= 0 ? '#4ade80' : '#f87171') ?>;">
                  <?php if ($hGuv !== null): ?>
                    <?= ($hGuv >= 0 ? '+' : '') . number_format($hGuv, 0, ',', '.') ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <?php if ($isEtf): ?>
                <td class="text-end" style="font-weight:600;color:<?= $hGuvRebal === null ? 'rgba(255,255,255,.4)' : ($hGuvRebal >= 0 ? 'rgba(74,222,128,.6)' : 'rgba(248,113,113,.6)') ?>;">
                  <?php if ($hGuvRebal !== null): ?>
                    <?= ($hGuvRebal >= 0 ? '+' : '') . number_format($hGuvRebal, 0, ',', '.') ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="text-end">
                  <span class="rsl-pill"><?= number_format($h['rsl'], 4, ',', '.') ?></span>
                </td>
              </tr>
              <?php endforeach; ?>

              <!-- Ausgeschiedene Positionen -->
              <?php foreach ($snap['exited'] as $exitTicker => $exitData):
                $exitDisplayId   = $isEtf ? ($etfWknMap[$exitTicker] ?? $exitTicker) : $exitTicker;
                $exitCompanyName = '';
                if ($isEtf) {
                  $exitCompanyName = $etfNameMap[$exitTicker] ?? $exitTicker;
                } else {
                  // S&P 500 / DAX: Company-Name aus holdings-Cache holen
                  $stmtExitName = $db->prepare("SELECT name FROM stocks WHERE ticker = ? LIMIT 1");
                  $stmtExitName->execute([$exitTicker]);
                  $exitCompanyName = $stmtExitName->fetchColumn() ?: $exitTicker;
                }
                // Exit-GuV: Erlös minus Wert der Position in der Vorperiode (rein kursbasiert)
                $prevExitVal = $prevHoldings[$exitTicker]['mkt_val'] ?? null;
                $exitGuv = ($prevExitVal !== null)
                    ? round($exitData['net_proceeds'] - $prevExitVal, 0)
                    : round($exitData['realized_pnl'], 0);
                $snapGuvSum += $exitGuv;
              ?>
              <tr style="background:rgba(248,113,113,.05);">
                <td class="text-center ps-3">
                  <span class="dot dot-exit"></span>
                </td>
                <td>
                  <span class="ticker-badge" style="color:#f87171;text-decoration:line-through;"><?= htmlspecialchars($exitDisplayId) ?></span>
                </td>
                <td style="font-size:.80rem;color:#f87171;text-decoration:line-through;"><?= htmlspecialchars($exitCompanyName) ?></td>
                <td style="font-size:.80rem;color:#f87171;font-style:italic;white-space:nowrap;">Position geschlossen</td>
                <td></td>
                <td></td>
                <td class="text-end sim-guv-val" data-usd="<?= $exitGuv ?>"
                    style="font-weight:600;color:<?= $exitGuv >= 0 ? '#4ade80' : '#f87171' ?>;">
                  <?= ($exitGuv >= 0 ? '+' : '') . number_format($exitGuv, 0, ',', '.') ?>
                </td>
                <?php if ($isEtf): ?><td></td><?php endif; ?>
                <td></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="font-weight:600; border-top:2px solid #dee2e6;">
                <td colspan="4" class="text-end text-muted" style="font-size:.85rem;">Summe</td>
                <td class="text-end">100,0%</td>
                <td class="text-end sim-pv" data-usd="<?= round($snap['pv']) ?>"><?= number_format($snap['pv'], 0, ',', '.') ?></td>
                <td class="text-end sim-guv-val" data-usd="<?= $weekDelta ?>"
                    data-prev-pv-usd="<?= round($prevPv) ?>"
                    data-prev-pv-date="<?= !$isLast ? ($snapshots[$snapIdx + 1]['date'] ?? '') : '' ?>"
                    style="color:<?= $weekDelta >= 0 ? '#16a34a' : '#dc2626' ?>;">
                  <?= $isLast ? '—' : (($weekDelta >= 0 ? '+' : '') . number_format($weekDelta, 0, ',', '.')) ?>
                </td>
                <?php if ($isEtf): ?><td></td><?php endif; ?>
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
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
document.querySelectorAll('.sim-th-tooltip').forEach(el => new bootstrap.Tooltip(el));
// ── Excel-Download (alle Snapshots untereinander) ───────────────────────────
const _xlData = <?= $xlExport ?>;

function downloadXlsx() {
  const meta    = _xlData.meta;
  const cur     = meta.waehrung;
  const isFx    = (!_isEurUni && typeof _currency !== 'undefined' && _currency === 'EUR');
  const fxRate  = (isFx && typeof endEurUsd !== 'undefined') ? endEurUsd : 1;
  const dispCur = isFx ? 'EUR' : cur;
  const isEtf   = meta.universum.includes('ETF');
  const idLabel = isEtf ? 'WKN' : 'Ticker';

  const COL_HDR = [
    'Status', idLabel, 'Name / Unternehmen', 'Sektor',
    'Kaufkurs (' + cur + ')', 'Akt. Kurs (' + cur + ')', 'Anteile',
    'Marktwert (' + dispCur + ')', 'Gewicht (%)', 'RSL Score', 'Rang',
    'GuV abs. (' + dispCur + ')', 'GuV (%)',
  ];
  const NCOLS = COL_HDR.length;
  const COLS  = ['A','B','C','D','E','F','G','H','I','J','K','L','M'];


  const wb  = XLSX.utils.book_new();
  const aoa = [];
  const titleRows   = [];
  const headerRows  = [];
  const dataRowMeta = []; // {rowIdx, status}

  // ── Sheet 1: Alle Portfolios untereinander ────────────────────────────
  _xlData.snapshots.forEach(snap => {
    // Pro Snapshot historischen EUR/USD-Kurs nutzen (wie Website-Anzeige)
    const snapRate = (isFx && snap.eur_rate) ? snap.eur_rate : fxRate;
    const pvDisp = isFx ? Math.round(snap.pv / snapRate) : snap.pv;
    const label  = 'Portfolio  ' + snap.date
      + (snap.no_change ? '  —  keine Änderung' : '')
      + '        Portfoliowert: ' + pvDisp.toLocaleString('de-DE') + ' ' + dispCur;
    titleRows.push(aoa.length);
    aoa.push([label]);

    headerRows.push(aoa.length);
    aoa.push([...COL_HDR]);

    snap.rows.forEach(h => {
      const mktVal = h.marktwert !== null ? (isFx ? Math.round(h.marktwert / snapRate) : h.marktwert) : '';
      const guv    = h.guv_abs   !== null ? (isFx ? Math.round(h.guv_abs   / snapRate) : h.guv_abs)   : '';
      dataRowMeta.push({ rowIdx: aoa.length, status: h.status });
      aoa.push([
        h.status,
        h.id,
        h.name,
        h.sektor,
        h.kaufkurs !== null ? h.kaufkurs : '',
        h.aktkurs  !== null ? h.aktkurs  : '',
        h.anteile  !== null ? h.anteile  : '',
        mktVal,
        h.gewicht  !== null ? h.gewicht  : '',
        h.rsl      !== null ? h.rsl      : '',
        h.rang     !== null ? h.rang      : '',
        guv,
        h.guv_pct  !== null ? h.guv_pct  : '',
      ]);
    });
    aoa.push([]); // Leerzeile
  });

  const ws1 = XLSX.utils.aoa_to_sheet(aoa);

  // Spaltenbreiten
  ws1['!cols'] = [
    {wch:15},{wch:9},{wch:30},{wch:20},{wch:13},{wch:13},
    {wch:11},{wch:16},{wch:10},{wch:10},{wch:6},{wch:15},{wch:9}
  ];

  // Merge-Cells: Titelzeilen über alle Spalten
  ws1['!merges'] = titleRows.map(r => ({ s:{r,c:0}, e:{r,c:NCOLS-1} }));

  // Zahlenformate auf Datenzeilen
  const NUM_CI = new Set([4,5,6,7,8,9,11,12]);
  dataRowMeta.forEach(({rowIdx}) => {
    COLS.forEach((col, ci) => {
      if (!NUM_CI.has(ci)) return;
      const addr = col + (rowIdx+1);
      if (!ws1[addr] || ws1[addr].v === '' || ws1[addr].v === undefined) return;
      ws1[addr].t = 'n';
      ws1[addr].z = ci === 6 ? '#,##0.0000' : (ci === 8 || ci === 12 ? '0.00' : '#,##0.00');
    });
  });

  XLSX.utils.book_append_sheet(wb, ws1, 'Alle Portfolios');

  // ── Sheet 2: Annahmen ──────────────────────────────────────────────────
  // portfoliowert nutzt letzten Snapshot-Kurs (= endEurUsd) → fxRate ist hier korrekt
  const pvTotal    = isFx ? Math.round(meta.portfoliowert / fxRate) : meta.portfoliowert;
  // startkapital kommt von PHP immer in EUR; für USD-Modus in USD umrechnen
  const startKapDisp = isFx ? meta.startkapital : (!_isEurUni ? Math.round(meta.startkapital * (typeof endEurUsd !== 'undefined' ? endEurUsd : 1)) : meta.startkapital);
  const aoa2 = [
    ['Parameter', 'Wert'],
    ['Universum',                              meta.universum],
    ['Startdatum',                             meta.startdatum],
    ['Startkapital (' + dispCur + ')',         startKapDisp],
    ['Aktueller Portfoliowert (' + dispCur + ')', pvTotal],
    ['Gesamtrendite (%)',                      meta.rendite_pct],
    ['Anzeigewährung',                         dispCur],
    ['Exportdatum',                            new Date().toISOString().slice(0,10)],
  ];
  const ws2 = XLSX.utils.aoa_to_sheet(aoa2);
  ws2['!cols'] = [{wch:36},{wch:22}];
  XLSX.utils.book_append_sheet(wb, ws2, 'Annahmen');

  // ── Download ───────────────────────────────────────────────────────────
  const today = new Date().toISOString().slice(0,10);
  const fname = 'RS_Portfolio_' + meta.universum.replace(/\s+/g,'_') + '_' + today + '.xlsx';
  XLSX.writeFile(wb, fname);
}
</script>
<script>
// Currency: DAX, HDAX und ETF immer EUR; S&P 500 per localStorage-Toggle
const _isDax        = <?= $isDax ? 'true' : 'false' ?>;
const _isHdax       = <?= $isHdax ? 'true' : 'false' ?>;
const _isEtf        = <?= $isEtf ? 'true' : 'false' ?>;
const _isEurUni     = (_isDax || _isHdax || _isEtf);
const _currency     = _isEurUni ? 'EUR' : (localStorage.getItem('currency') || 'EUR');
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
  if (_isEtf) {
    // ETF: Simulationswerte sind EUR-Basiseinheiten — keine FX-Umrechnung nötig
    const finalEur = parseFloat(el.dataset.finalUsd); // bereits in EUR-Einheiten
    const startEur = parseFloat(el.dataset.startUsd); // bereits in EUR-Einheiten
    pct = (finalEur / startEur - 1) * 100;
  } else if (!_isEurUni && _currency === 'EUR') {
    // S&P 500 im EUR-Modus: beide Werte in USD, historisch in EUR umrechnen
    const finalEur = parseFloat(el.dataset.finalUsd) / endEurUsd;
    const startEur = parseFloat(el.dataset.startUsd) / startEurUsd;
    pct = (finalEur / startEur - 1) * 100;
  } else {
    // DAX oder S&P 500 in USD: Rendite direkt aus Simulation
    pct = parseFloat(el.dataset.pctUsd);
  }
  el.textContent = (pct >= 0 ? '+' : '') + pct.toLocaleString('de-DE', {minimumFractionDigits:1, maximumFractionDigits:1}) + '%';
  el.className = 'kpi-value ' + (pct >= 0 ? 'text-success' : 'text-danger');
})();

if (!_isEurUni && _currency === 'EUR') {
  // S&P 500 im EUR-Modus: USD-Werte in EUR umrechnen mit EUR/USD-Rate des jeweiligen Snapshot-Datums
  const eurRateByDate = <?= json_encode($eurRateByDate ?: new stdClass()) ?>;

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
    if (!isNaN(usd)) {
      const snapDate = el.closest('.portfolio-card')?.dataset.snapDate;
      const rate = (snapDate && eurRateByDate[snapDate]) ? eurRateByDate[snapDate] : currentEurUsd;
      el.textContent = Math.round(usd / rate).toLocaleString('de-DE');
    }
  });
  document.querySelectorAll('.sim-guv-val').forEach(el => {
    const usd = parseFloat(el.dataset.usd);
    if (!isNaN(usd) && el.dataset.usd !== '') {
      const snapDate = el.closest('.portfolio-card')?.dataset.snapDate;
      const rate = (snapDate && eurRateByDate[snapDate]) ? eurRateByDate[snapDate] : currentEurUsd;

      // Summe-Zeile (tfoot): echtes EUR-Delta = pv_EUR_aktuell − pv_EUR_vorperiode
      // verhindert Verzerrung durch EUR/USD-Schwankungen zwischen den Snapshot-Daten
      if (el.dataset.prevPvUsd !== undefined && el.dataset.prevPvDate !== undefined && el.dataset.usd !== '0') {
        const prevPvUsd  = parseFloat(el.dataset.prevPvUsd);
        const prevDate   = el.dataset.prevPvDate;
        const ratePrev   = (prevDate && eurRateByDate[prevDate]) ? eurRateByDate[prevDate] : currentEurUsd;
        const pvCell     = el.closest('tr')?.closest('tfoot')?.closest('table')?.querySelector('.sim-pv');
        const pvUsd      = pvCell ? parseFloat(pvCell.dataset.usd) : NaN;
        if (!isNaN(pvUsd) && !isNaN(prevPvUsd) && ratePrev > 0) {
          const deltaEur = Math.round(pvUsd / rate) - Math.round(prevPvUsd / ratePrev);
          el.textContent = (deltaEur >= 0 ? '+' : '') + deltaEur.toLocaleString('de-DE');
          el.style.color = deltaEur >= 0 ? '#16a34a' : '#dc2626';
          return;
        }
      }

      const eur = Math.round(usd / rate);
      el.textContent = (eur >= 0 ? '+' : '') + eur.toLocaleString('de-DE');
    }
  });
}
// DAX + ETF: Simulationswerte sind bereits in EUR-Basiseinheiten — keine Umrechnung nötig

(function () {
  const displayInput   = document.getElementById('inputCapitalDisplay');
  const hiddenCapital  = document.getElementById('inputCapital');
  const startDateInput = document.getElementById('inputStartDate');
  const sliderCapital  = document.getElementById('sliderCapital');
  const sliderDate     = document.getElementById('sliderDate');
  const form           = document.querySelector('form');
  const params         = new URLSearchParams(window.location.search);

  // Währungslabel am Input aktualisieren
  const currLabel = document.getElementById('capital-currency-label');
  if (currLabel && !_isEurUni) currLabel.textContent = _currency === 'EUR' ? '€ EUR' : '$ USD';

  // S&P 500 im EUR-Modus: hiddenCapital enthält bereits den EUR-Betrag (Benutzereingabe)
  if (!_isEurUni && _currency === 'EUR') {
    const eurVal = parseInt(hiddenCapital.value, 10) || 50000;
    displayInput.value = formatNum(eurVal);
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
  // hiddenCapital enthält IMMER EUR (PHP erwartet EUR und rechnet intern in USD um).
  // Einzige Ausnahme: S&P 500 USD-Modus — Nutzer tippt USD, wird hier zu EUR konvertiert.
  function syncCapital() {
    const raw = Math.max(1000, parseRaw(displayInput.value));
    displayInput.value  = formatNum(raw);
    hiddenCapital.value = (!_isEurUni && _currency === 'USD') ? Math.round(raw / currentEurUsd) : raw;
  }

  // Wenn keine GET-Parameter vorhanden: gespeicherte Werte aus localStorage laden
  if (!params.has('capital') && !params.has('start_date')) {
    // ETF: immer EUR 50.000 als Standard — localStorage-Kapital ignorieren
    const _capKey = 'sim_capital_' + (<?= json_encode($universe) ?>);
    const savedCapitalRaw = _isEtf ? null : localStorage.getItem(_capKey);
    // Universumsabhängiger Key — verhindert Überschreiben durch andere Universen
    const _startKey      = 'sim_start_date_' + (<?= json_encode($universe) ?>);
    const savedStartDate = localStorage.getItem(_startKey); // nur universumsabhängiger Key
    if (savedCapitalRaw) {
      // sim_capital_* wird immer in EUR gespeichert (auch für S&P 500)
      let capEur = Math.max(10000, parseInt(savedCapitalRaw, 10) || 50000);
      if (_isDax || _isHdax) capEur = Math.round(capEur / 10000) * 10000;
      if (_isEurUni) {
        displayInput.value  = formatNum(capEur);
        hiddenCapital.value = capEur;
      } else {
        // S&P 500: hiddenCapital immer EUR; Anzeige je nach Währung
        displayInput.value  = formatNum(_currency === 'EUR' ? capEur : Math.round(capEur * currentEurUsd));
        hiddenCapital.value = capEur;
      }
    }
    if (savedStartDate && startDateInput) startDateInput.value = savedStartDate;
    // Automatisch neu laden wenn gespeicherte Werte vom Default abweichen
    const defaultCapital = parseInt(hiddenCapital.defaultValue, 10);
    const capRaw = savedCapitalRaw ? Math.max(10000, parseInt(savedCapitalRaw, 10) || 50000) : null;
    // capRaw und hiddenCapital sind jetzt immer EUR — kein USD-Umrechnen mehr nötig
    const capToCheck = capRaw !== null
      ? ((_isDax || _isHdax) ? Math.round(capRaw / 10000) * 10000 : capRaw)
      : null;
    if ((capToCheck && capToCheck !== defaultCapital) ||
        (savedStartDate && startDateInput && savedStartDate !== startDateInput.defaultValue)) {
      if (capToCheck) hiddenCapital.value = capToCheck;
      form.submit();
      return;
    }
    // Effektives Startdatum sofort persistieren (auch ohne Berechnen-Klick),
    // damit Dashboard und andere Seiten den korrekten Key lesen können.
    if (startDateInput && startDateInput.value) {
      localStorage.setItem(_startKey, startDateInput.value);
    }
  }

  function saveAndSubmit() {
    syncCapital();
    // hiddenCapital ist immer EUR — direkt speichern
    const _capEurToSave = parseInt(hiddenCapital.value, 10);
    localStorage.setItem('sim_capital_' + (<?= json_encode($universe) ?>), _capEurToSave);
    // Universumsabhängiger Key, damit S&P 500 / DAX / ETF sich nicht gegenseitig überschreiben
    const _startKey = 'sim_start_date_' + (<?= json_encode($universe) ?>);
    localStorage.setItem(_startKey, startDateInput.value);
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
    // hiddenCapital immer EUR; S&P 500 USD-Modus: Nutzer tippt USD → EUR zurückrechnen
    const internalVal = (!_isEurUni && _currency === 'USD') ? Math.round(raw / currentEurUsd) : raw;
    hiddenCapital.value  = internalVal || '';
    if (sliderCapital) sliderCapital.value = Math.min(Math.max(raw, 10000), 250000);
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(saveAndSubmit, 800);
  });

  displayInput.addEventListener('blur', syncCapital);

  // ── Kapital: Slider → Text-Input (nicht für ETF, da kein Kapital-Slider) ──
  if (sliderCapital) {
    sliderCapital.addEventListener('input', () => {
      const sliderVal  = parseInt(sliderCapital.value, 10);
      displayInput.value  = formatNum(sliderVal);
      hiddenCapital.value = (!_isEurUni && _currency === 'USD') ? Math.round(sliderVal / currentEurUsd) : sliderVal;
    });
    sliderCapital.addEventListener('change', saveAndSubmit);
  }

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
