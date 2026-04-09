<?php
/**
 * RSL Engine — Kernlogik für das Frontend
 * Stellt Daten für Dashboard, Ranking und Portfolio bereit
 */

require_once __DIR__ . '/../config/database.php';

class RSLEngine {

    private PDO $db;

    public function __construct() {
        $this->db = getDB();
    }

    /** Aktuellstes Ranking-Datum (letzter Sonntag mit Daten) */
    public function getLatestRankingDate(): ?string {
        return $this->db->query(
            'SELECT MAX(ranking_date) FROM rsl_rankings'
        )->fetchColumn() ?: null;
    }

    /** Aktuelles Top-5 Portfolio-Vorschlag */
    public function getCurrentTop5(string $date = null): array {
        $date = $date ?? $this->getLatestRankingDate();
        if (!$date) return [];

        $stmt = $this->db->prepare(
            'SELECT r.ticker, s.name, r.sector, r.current_price, r.sma_26w,
                    r.rsl, r.rank_overall
             FROM rsl_rankings r
             LEFT JOIN stocks s ON s.ticker = r.ticker
             WHERE r.ranking_date = ? AND r.is_selected = 1
             ORDER BY r.rsl DESC'
        );
        $stmt->execute([$date]);
        return $stmt->fetchAll();
    }

    /** Vollständiges Ranking für ein Datum */
    public function getFullRanking(string $date = null, int $limit = 50): array {
        $date = $date ?? $this->getLatestRankingDate();
        if (!$date) return [];

        $stmt = $this->db->prepare(
            'SELECT r.ticker, s.name, r.sector, r.current_price, r.sma_26w,
                    r.rsl, r.rank_overall, r.rank_in_sector, r.is_selected
             FROM rsl_rankings r
             LEFT JOIN stocks s ON s.ticker = r.ticker
             WHERE r.ranking_date = ?
             ORDER BY r.rsl DESC
             LIMIT ?'
        );
        $stmt->execute([$date, $limit]);
        return $stmt->fetchAll();
    }

    /** Historisches Top-5 für ein Ticker (Verlauf des RSL) */
    public function getRSLHistory(string $ticker, int $weeks = 52): array {
        $stmt = $this->db->prepare(
            'SELECT ranking_date, current_price, sma_26w, rsl, rank_overall, is_selected
             FROM rsl_rankings
             WHERE ticker = ?
             ORDER BY ranking_date DESC
             LIMIT ?'
        );
        $stmt->execute([$ticker, $weeks]);
        return array_reverse($stmt->fetchAll());
    }

    /** Neueste Backtest-Config-ID */
    private function getLatestConfigId(): int {
        return (int)($this->db->query(
            'SELECT MAX(id) FROM backtest_configs'
        )->fetchColumn() ?: 1);
    }

    /** Backtest-Performance-Daten für Chart */
    public function getBacktestChartData(int $configId = 0): array {
        if ($configId === 0) $configId = $this->getLatestConfigId();
        $stmt = $this->db->prepare(
            'SELECT value_date, portfolio_value, sp500_indexed, cash, invested, num_trades
             FROM backtest_portfolio_values
             WHERE config_id = ?
             ORDER BY value_date'
        );
        $stmt->execute([$configId]);
        return $stmt->fetchAll();
    }

    /** Backtest-Ergebnisse */
    public function getBacktestResults(int $configId = 0): ?array {
        if ($configId === 0) $configId = $this->getLatestConfigId();
        $stmt = $this->db->prepare(
            'SELECT r.*, c.initial_capital, c.start_date, c.end_date, c.transaction_cost
             FROM backtest_results r
             JOIN backtest_configs c ON c.id = r.config_id
             WHERE r.config_id = ?'
        );
        $stmt->execute([$configId]);
        return $stmt->fetch() ?: null;
    }

    /** Backtest-Trades für Detail-Ansicht */
    public function getBacktestTrades(int $configId = 0, int $limit = 100): array {
        if ($configId === 0) $configId = $this->getLatestConfigId();
        $stmt = $this->db->prepare(
            'SELECT t.*, s.name
             FROM backtest_trades t
             LEFT JOIN stocks s ON s.ticker = t.ticker
             WHERE t.config_id = ?
             ORDER BY t.trade_date DESC, t.action DESC
             LIMIT ?'
        );
        $stmt->execute([$configId, $limit]);
        return $stmt->fetchAll();
    }

    /** Offene Portfolio-Positionen mit aktuellem P&L */
    public function getOpenPositions(): array {
        $latestDate = $this->getLatestRankingDate();

        $stmt = $this->db->prepare(
            'SELECT p.*,
                    s.name, s.sector,
                    r.current_price as current_price_rsl,
                    r.rsl, r.rank_overall, r.is_selected,
                    ROUND((COALESCE(r.current_price, p.buy_price) - p.buy_price)
                          / p.buy_price * 100, 2) as return_pct,
                    ROUND((COALESCE(r.current_price, p.buy_price) * p.shares) - p.investment, 2) as unrealized_pnl,
                    ROUND(COALESCE(r.current_price, p.buy_price) * p.shares, 2) as current_value
             FROM portfolio_positions p
             LEFT JOIN stocks s ON s.ticker = p.ticker
             LEFT JOIN rsl_rankings r ON r.ticker = p.ticker AND r.ranking_date = ?
             WHERE p.status = "open"
             ORDER BY p.buy_date DESC'
        );
        $stmt->execute([$latestDate]);
        return $stmt->fetchAll();
    }

    /** Geschlossene Positionen mit realisiertem P&L */
    public function getClosedPositions(): array {
        $stmt = $this->db->prepare(
            'SELECT p.*, s.name, s.sector,
                    ROUND((p.sell_price - p.buy_price) / p.buy_price * 100, 2) as return_pct,
                    ROUND(p.proceeds - p.investment, 2) as realized_pnl,
                    DATEDIFF(p.sell_date, p.buy_date) as holding_days
             FROM portfolio_positions p
             LEFT JOIN stocks s ON s.ticker = p.ticker
             WHERE p.status = "closed"
             ORDER BY p.sell_date DESC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Portfolio-Zusammenfassung */
    public function getPortfolioSummary(): array {
        $open   = $this->getOpenPositions();
        $closed = $this->getClosedPositions();

        $totalInvested     = array_sum(array_column($open, 'investment'));
        $totalCurrentValue = array_sum(array_column($open, 'current_value'));
        $totalRealizedPnL  = array_sum(array_column($closed, 'realized_pnl'));
        $totalUnrealizedPnL = array_sum(array_column($open, 'unrealized_pnl'));

        return [
            'num_open'           => count($open),
            'num_closed'         => count($closed),
            'total_invested'     => $totalInvested,
            'total_current_value'=> $totalCurrentValue,
            'total_realized_pnl' => $totalRealizedPnL,
            'total_unrealized_pnl' => $totalUnrealizedPnL,
            'total_pnl'          => $totalRealizedPnL + $totalUnrealizedPnL,
            'return_pct'         => $totalInvested > 0
                ? round(($totalCurrentValue - $totalInvested) / $totalInvested * 100, 2)
                : 0,
        ];
    }

    /** Position kaufen */
    public function buyPosition(
        string $ticker, string $buyDate, float $buyPrice,
        float $shares, string $buyReason = ''
    ): int {
        $investment = $buyPrice * $shares;
        $ticker = strtoupper(trim($ticker));

        $this->db->prepare(
            'INSERT INTO portfolio_positions
               (ticker, buy_date, buy_price, shares, investment, buy_reason)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$ticker, $buyDate, $buyPrice, $shares, $investment, $buyReason]);
        $posId = (int)$this->db->lastInsertId();

        $this->db->prepare(
            'INSERT INTO portfolio_transactions
               (position_id, transaction_date, ticker, action, price, shares, amount)
             VALUES (?, ?, ?, "BUY", ?, ?, ?)'
        )->execute([$posId, $buyDate, $ticker, $buyPrice, $shares, $investment]);

        return $posId;
    }

    /** Position verkaufen */
    public function sellPosition(
        int $positionId, string $sellDate, float $sellPrice, string $sellReason = ''
    ): bool {
        $pos = $this->db->prepare(
            'SELECT * FROM portfolio_positions WHERE id = ? AND status = "open"'
        );
        $pos->execute([$positionId]);
        $position = $pos->fetch();
        if (!$position) return false;

        $proceeds = $sellPrice * $position['shares'];

        $this->db->prepare(
            'UPDATE portfolio_positions
             SET sell_date=?, sell_price=?, proceeds=?, sell_reason=?, status="closed",
                 updated_at=NOW()
             WHERE id=?'
        )->execute([$sellDate, $sellPrice, $proceeds, $sellReason, $positionId]);

        $this->db->prepare(
            'INSERT INTO portfolio_transactions
               (position_id, transaction_date, ticker, action, price, shares, amount)
             VALUES (?, ?, ?, "SELL", ?, ?, ?)'
        )->execute([
            $positionId, $sellDate, $position['ticker'],
            $sellPrice, $position['shares'], $proceeds
        ]);

        return true;
    }

    /** Download-Status aller Ticker */
    public function getDownloadStatus(): array {
        return $this->db->query(
            'SELECT s.ticker, s.sector,
                    dl.last_download, dl.rows_inserted, dl.status,
                    (SELECT COUNT(*) FROM prices p WHERE p.ticker = s.ticker) as price_rows,
                    (SELECT MIN(price_date) FROM prices p WHERE p.ticker = s.ticker) as first_date,
                    (SELECT MAX(price_date) FROM prices p WHERE p.ticker = s.ticker) as last_date
             FROM stocks s
             LEFT JOIN download_log dl ON dl.ticker = s.ticker
             ORDER BY dl.status ASC, s.ticker ASC'
        )->fetchAll();
    }
}
