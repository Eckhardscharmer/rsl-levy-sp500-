-- RSL nach Levy - Datenbankschema
-- MariaDB 10.4+

CREATE DATABASE IF NOT EXISTS rsl_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rsl_system;

-- ============================================================
-- STAMMDATEN
-- ============================================================

-- Aktien-Stammdaten (alle S&P 500 / DAX-Mitglieder, historisch + aktuell)
CREATE TABLE IF NOT EXISTS stocks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ticker        VARCHAR(20)  NOT NULL UNIQUE,
    name          VARCHAR(255),
    sector        VARCHAR(100) COMMENT 'GICS Sektor',
    industry      VARCHAR(150) COMMENT 'GICS Industrie',
    cik           VARCHAR(20)  COMMENT 'SEC CIK-Nummer',
    universe      VARCHAR(10)  NOT NULL DEFAULT 'sp500' COMMENT 'sp500 oder dax',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sector (sector),
    INDEX idx_universe (universe)
) ENGINE=InnoDB;

-- Historische S&P 500-Zusammensetzung (kein Survivorship Bias)
CREATE TABLE IF NOT EXISTS sp500_membership (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ticker        VARCHAR(20) NOT NULL,
    date_added    DATE        NOT NULL,
    date_removed  DATE        NULL COMMENT 'NULL = aktuell Mitglied',
    reason_added  VARCHAR(255),
    reason_removed VARCHAR(255),
    INDEX idx_ticker      (ticker),
    INDEX idx_date_added  (date_added),
    INDEX idx_date_removed (date_removed)
) ENGINE=InnoDB;

-- Historische DAX-Zusammensetzung (kein Survivorship Bias)
CREATE TABLE IF NOT EXISTS dax_membership (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ticker        VARCHAR(20) NOT NULL,
    date_added    DATE        NOT NULL,
    date_removed  DATE        NULL COMMENT 'NULL = aktuell Mitglied',
    reason_added  VARCHAR(255),
    reason_removed VARCHAR(255),
    INDEX idx_ticker      (ticker),
    INDEX idx_date_added  (date_added),
    INDEX idx_date_removed (date_removed)
) ENGINE=InnoDB;

-- ============================================================
-- KURSDATEN
-- ============================================================

-- Tagespreise (OHLCV + adjusted close)
CREATE TABLE IF NOT EXISTS prices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ticker        VARCHAR(20)     NOT NULL,
    price_date    DATE            NOT NULL,
    open          DECIMAL(14,4),
    high          DECIMAL(14,4),
    low           DECIMAL(14,4),
    close         DECIMAL(14,4)   NOT NULL,
    adj_close     DECIMAL(14,4)   NOT NULL COMMENT 'Dividenden-/Split-bereinigt',
    volume        BIGINT UNSIGNED,
    UNIQUE KEY uk_ticker_date (ticker, price_date),
    INDEX idx_ticker    (ticker),
    INDEX idx_date      (price_date)
) ENGINE=InnoDB;

-- Download-Status pro Ticker (für Batch-Steuerung)
CREATE TABLE IF NOT EXISTS download_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ticker        VARCHAR(20) NOT NULL,
    last_download TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    from_date     DATE,
    to_date       DATE,
    rows_inserted INT UNSIGNED DEFAULT 0,
    status        ENUM('ok','error','partial') DEFAULT 'ok',
    error_msg     TEXT,
    UNIQUE KEY uk_ticker (ticker)
) ENGINE=InnoDB;

-- ============================================================
-- RSL ENGINE
-- ============================================================

-- Wöchentliche RSL-Rankings (jeden Sonntag)
CREATE TABLE IF NOT EXISTS rsl_rankings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ranking_date    DATE           NOT NULL COMMENT 'Sonntag der Berechnung',
    ticker          VARCHAR(20)    NOT NULL,
    sector          VARCHAR(100),
    current_price   DECIMAL(14,4)  NOT NULL,
    sma_26w         DECIMAL(14,4)  NOT NULL COMMENT 'SMA über 182 Tage',
    rsl             DECIMAL(10,6)  NOT NULL COMMENT 'current_price / sma_26w',
    rank_overall    SMALLINT UNSIGNED COMMENT 'Rang im gesamten Index',
    rank_in_sector  SMALLINT UNSIGNED COMMENT 'Rang innerhalb des Sektors',
    is_sp500_member TINYINT(1) DEFAULT 1,
    is_selected     TINYINT(1) DEFAULT 0 COMMENT '1 = in den Top-5 ausgewählt',
    universe        VARCHAR(10) NOT NULL DEFAULT 'sp500' COMMENT 'sp500 oder dax',
    UNIQUE KEY uk_date_ticker_universe (ranking_date, ticker, universe),
    INDEX idx_date       (ranking_date),
    INDEX idx_universe   (universe),
    INDEX idx_rsl        (rsl DESC),
    INDEX idx_selected   (ranking_date, is_selected, universe)
) ENGINE=InnoDB;

-- ============================================================
-- BACKTEST ENGINE
-- ============================================================

-- Backtest-Konfigurationen
CREATE TABLE IF NOT EXISTS backtest_configs (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    name               VARCHAR(100) NOT NULL,
    start_date         DATE         NOT NULL,
    end_date           DATE         NOT NULL,
    initial_capital    DECIMAL(15,2) NOT NULL DEFAULT 100000.00,
    num_positions      TINYINT UNSIGNED DEFAULT 5,
    sma_weeks          TINYINT UNSIGNED DEFAULT 26,
    transaction_cost   DECIMAL(6,4) DEFAULT 0.001 COMMENT '0.001 = 0.1%',
    sector_diversify   TINYINT(1) DEFAULT 1 COMMENT 'Max 1 Aktie pro Sektor',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Backtest-Trades
CREATE TABLE IF NOT EXISTS backtest_trades (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    config_id       INT              NOT NULL,
    trade_date      DATE             NOT NULL,
    ticker          VARCHAR(20)      NOT NULL,
    action          ENUM('BUY','SELL') NOT NULL,
    price           DECIMAL(14,4)    NOT NULL,
    shares          DECIMAL(14,6)    NOT NULL,
    gross_amount    DECIMAL(15,4)    NOT NULL,
    transaction_cost DECIMAL(10,4)   NOT NULL,
    net_amount      DECIMAL(15,4)    NOT NULL,
    sector          VARCHAR(100),
    rsl_at_trade    DECIMAL(10,6),
    INDEX idx_config   (config_id),
    INDEX idx_date     (trade_date),
    FOREIGN KEY (config_id) REFERENCES backtest_configs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Backtest-Portfolio-Wert (wöchentlich)
CREATE TABLE IF NOT EXISTS backtest_portfolio_values (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    config_id       INT              NOT NULL,
    value_date      DATE             NOT NULL,
    portfolio_value DECIMAL(15,4)    NOT NULL COMMENT 'Gesamt: Cash + Positionen',
    cash            DECIMAL(15,4)    NOT NULL,
    invested        DECIMAL(15,4)    NOT NULL,
    num_trades      TINYINT UNSIGNED DEFAULT 0 COMMENT 'Trades in dieser Woche',
    sp500_close     DECIMAL(14,4)    COMMENT 'SPY Schlusskurs als Benchmark',
    sp500_indexed   DECIMAL(14,4)    COMMENT 'SPY auf Startkapital indiziert',
    UNIQUE KEY uk_config_date (config_id, value_date),
    INDEX idx_config (config_id),
    FOREIGN KEY (config_id) REFERENCES backtest_configs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Backtest-Ergebnisse (aggregierte Metriken)
CREATE TABLE IF NOT EXISTS backtest_results (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    config_id           INT              NOT NULL UNIQUE,
    total_return_pct    DECIMAL(10,4),
    cagr_pct            DECIMAL(10,4),
    max_drawdown_pct    DECIMAL(10,4),
    sharpe_ratio        DECIMAL(8,4),
    num_total_trades    INT UNSIGNED,
    num_winning_trades  INT UNSIGNED,
    win_rate_pct        DECIMAL(8,4),
    avg_holding_weeks   DECIMAL(8,2),
    benchmark_return_pct DECIMAL(10,4) COMMENT 'S&P 500 (SPY) im gleichen Zeitraum',
    outperformance_pct  DECIMAL(10,4),
    calculated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (config_id) REFERENCES backtest_configs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- PORTFOLIO TRACKER (Live / Manuell)
-- ============================================================

-- Positionen (Käufe und Verkäufe)
CREATE TABLE IF NOT EXISTS portfolio_positions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ticker          VARCHAR(20)    NOT NULL,
    buy_date        DATE           NOT NULL,
    buy_price       DECIMAL(14,4)  NOT NULL COMMENT 'Kaufkurs pro Aktie',
    shares          DECIMAL(14,6)  NOT NULL COMMENT 'Anzahl Aktien',
    investment      DECIMAL(15,4)  NOT NULL COMMENT 'Kaufkurs * Stück',
    buy_reason      TEXT           COMMENT 'z.B. RSL-Rank, Notizen',
    sell_date       DATE,
    sell_price      DECIMAL(14,4),
    proceeds        DECIMAL(15,4),
    sell_reason     TEXT,
    status          ENUM('open','closed') DEFAULT 'open',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticker  (ticker),
    INDEX idx_status  (status),
    INDEX idx_buy_date (buy_date)
) ENGINE=InnoDB;

-- Alle Transaktionen (für lückenlosen Audit-Trail)
CREATE TABLE IF NOT EXISTS portfolio_transactions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    position_id      INT            REFERENCES portfolio_positions(id),
    transaction_date DATE           NOT NULL,
    ticker           VARCHAR(20)    NOT NULL,
    action           ENUM('BUY','SELL') NOT NULL,
    price            DECIMAL(14,4)  NOT NULL,
    shares           DECIMAL(14,6)  NOT NULL,
    amount           DECIMAL(15,4)  NOT NULL COMMENT 'price * shares',
    fees             DECIMAL(10,4)  DEFAULT 0,
    notes            TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticker (ticker),
    INDEX idx_date   (transaction_date)
) ENGINE=InnoDB;

-- Benchmark-Tracking (S&P 500-Stand manuell oder automatisch)
CREATE TABLE IF NOT EXISTS benchmark_values (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    value_date      DATE           NOT NULL UNIQUE,
    sp500_close     DECIMAL(14,4)  NOT NULL COMMENT 'SPY oder ^GSPC Schlusskurs',
    source          VARCHAR(50)    DEFAULT 'yahoo'
) ENGINE=InnoDB;

-- System-Konfiguration (Key-Value)
CREATE TABLE IF NOT EXISTS system_config (
    config_key      VARCHAR(100)   NOT NULL PRIMARY KEY,
    config_value    TEXT,
    description     VARCHAR(255),
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Standardwerte
INSERT IGNORE INTO system_config (config_key, config_value, description) VALUES
    ('rsl_sma_days',        '182',   '26 Wochen in Handelstagen (ca.)'),
    ('rsl_top_n',           '5',     'Anzahl Top-Positionen'),
    ('sector_diversify',    '1',     '1 = max. eine Aktie pro GICS-Sektor'),
    ('transaction_cost',    '0.001', '0.1% Transaktionskosten'),
    ('rebalancing_day',     'Sunday','Wochentag des Rebalancings'),
    ('backtest_start',      '2020-01-05', 'Erster Backtest-Sonntag'),
    ('data_start',          '2019-06-01', 'Datenbeginn (Warmup für SMA)'),
    ('yahoo_delay_ms',      '500',   'Pause zwischen Yahoo-Requests in ms');
