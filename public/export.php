<?php
/**
 * CSV-Export für Portfolio-Positionen (Steuerzwecke)
 */
require_once __DIR__ . '/../src/RSLEngine.php';
$rsl  = new RSLEngine();
$db   = getDB();
$type = $_GET['type'] ?? 'all';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rsl_portfolio_' . date('Y-m-d') . '_' . $type . '.csv"');

$out = fopen('php://output', 'w');
// BOM für Excel (UTF-8)
fwrite($out, "\xEF\xBB\xBF");

if ($type === 'transactions') {
    fputcsv($out, ['Datum', 'Ticker', 'Aktion', 'Kurs (USD)', 'Stück', 'Betrag (USD)', 'Notiz'], ';');
    $rows = $db->query(
        'SELECT transaction_date, ticker, action, price, shares,
                ROUND(price * shares, 4) as amount, notes
         FROM portfolio_transactions
         ORDER BY transaction_date DESC, id DESC'
    )->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [
            date('d.m.Y', strtotime($r['transaction_date'])),
            $r['ticker'],
            $r['action'] === 'BUY' ? 'KAUF' : 'VERKAUF',
            number_format($r['price'], 4, ',', ''),
            number_format($r['shares'], 6, ',', ''),
            number_format($r['amount'], 2, ',', ''),
            $r['notes'] ?? '',
        ], ';');
    }
} elseif ($type === 'closed') {
    fputcsv($out, ['Ticker', 'Name', 'Sektor', 'Kauf-Datum', 'Kaufkurs', 'Stück', 'Investment', 'Verkauf-Datum', 'Verkaufskurs', 'Erlös', 'P&L', 'Rendite %', 'Haltedauer (Tage)'], ';');
    $rows = $rsl->getClosedPositions();
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['ticker'],
            $r['name'] ?? '',
            $r['sector'] ?? '',
            date('d.m.Y', strtotime($r['buy_date'])),
            number_format($r['buy_price'], 4, ',', ''),
            number_format($r['shares'], 6, ',', ''),
            number_format($r['investment'], 2, ',', ''),
            date('d.m.Y', strtotime($r['sell_date'])),
            number_format($r['sell_price'], 4, ',', ''),
            number_format($r['proceeds'], 2, ',', ''),
            number_format($r['realized_pnl'], 2, ',', ''),
            number_format($r['return_pct'], 2, ',', ''),
            $r['holding_days'],
        ], ';');
    }
} else {
    // Alle Positionen
    fputcsv($out, ['Status', 'Ticker', 'Name', 'Sektor', 'Kauf-Datum', 'Kaufkurs (USD)', 'Stück', 'Investment (USD)', 'Akt. Kurs', 'Akt. Wert', 'P&L (unreal.)', 'Rendite %'], ';');
    $open   = $rsl->getOpenPositions();
    $closed = $rsl->getClosedPositions();
    foreach ($open as $r) {
        fputcsv($out, [
            'Offen',
            $r['ticker'], $r['name'] ?? '', $r['sector'] ?? '',
            date('d.m.Y', strtotime($r['buy_date'])),
            number_format($r['buy_price'], 4, ',', ''),
            number_format($r['shares'], 6, ',', ''),
            number_format($r['investment'], 2, ',', ''),
            number_format($r['current_price_rsl'] ?? $r['buy_price'], 4, ',', ''),
            number_format($r['current_value'], 2, ',', ''),
            number_format($r['unrealized_pnl'], 2, ',', ''),
            number_format($r['return_pct'], 2, ',', ''),
        ], ';');
    }
    foreach ($closed as $r) {
        fputcsv($out, [
            'Geschlossen',
            $r['ticker'], $r['name'] ?? '', $r['sector'] ?? '',
            date('d.m.Y', strtotime($r['buy_date'])),
            number_format($r['buy_price'], 4, ',', ''),
            number_format($r['shares'], 6, ',', ''),
            number_format($r['investment'], 2, ',', ''),
            number_format($r['sell_price'], 4, ',', ''),
            number_format($r['proceeds'], 2, ',', ''),
            number_format($r['realized_pnl'], 2, ',', ''),
            number_format($r['return_pct'], 2, ',', ''),
        ], ';');
    }
}

fclose($out);
