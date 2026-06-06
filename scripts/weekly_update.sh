#!/bin/bash
# RSL nach Levy — Wöchentliches Update
# Läuft jeden Montag (Daten vom Wochenende verfügbar)
# Cron: 0 6 * * 1 (Montags 06:00 UTC)

cd /var/www/rsl

LOG=/tmp/rsl_weekly_$(date +%Y%m%d).log
echo "=== RSL Weekly Update: $(date) ===" | tee $LOG

# ── 1. Preise aktualisieren (alle Aktien inkrementell) ──────────────────────
echo "[1/4] Download: S&P 500 + DAX Preise..." | tee -a $LOG
python3 scripts/07_download_yfinance.py --update >> $LOG 2>&1
echo "Download fertig: $(date)" | tee -a $LOG

# ── 2. Benchmark-Ticker separat (SPY, ^GDAXI, ACWI — nicht in stocks-Tabelle) ─
echo "[2/4] Download: Benchmark-Ticker (SPY, ^GDAXI, ACWI, EURUSD=X)..." | tee -a $LOG
python3 - << 'PYEOF' >> $LOG 2>&1
import yfinance as yf, subprocess, warnings, datetime
warnings.filterwarnings('ignore')
MYSQL = ['mysql', '-u', 'root', '-prsl2024', 'rsl_system']

def last_date(ticker):
    out = subprocess.run(MYSQL + ['-e', f"SELECT MAX(price_date) FROM prices WHERE ticker='{ticker}';"],
                         capture_output=True, text=True).stdout.strip().split('\n')
    val = out[-1].strip() if len(out) > 1 else '2020-01-01'
    return val if val and val != 'NULL' else '2020-01-01'

def upsert(ticker, yahoo_ticker=None):
    yt = yahoo_ticker or ticker
    start = last_date(ticker)
    end   = datetime.date.today().isoformat()
    if start >= end:
        print(f"{ticker}: bereits aktuell ({start})")
        return
    df = yf.Ticker(yt).history(start=start, end=end, auto_adjust=False)
    if df is None or df.empty:
        print(f"{ticker}: keine neuen Daten")
        return
    rows = []
    for dt, row in df.iterrows():
        d = dt.strftime('%Y-%m-%d')
        c = float(row['Close']); ac = float(row.get('Adj Close', c))
        o = float(row['Open']); h = float(row['High']); l = float(row['Low'])
        v = int(row.get('Volume', 0) or 0)
        rows.append(f"('{d}','{ticker}',{o},{h},{l},{c},{ac},{v})")
    sql = ("INSERT IGNORE INTO prices (price_date,ticker,open,high,low,close,adj_close,volume) VALUES "
           + ",".join(rows) + ";")
    r = subprocess.run(MYSQL + ['-e', sql], capture_output=True)
    print(f"{ticker}: {len(rows)} neue Zeilen {'OK' if r.returncode==0 else 'FEHLER'}")

upsert('SPY')
upsert('^GDAXI')
upsert('ACWI')
upsert('EURUSD=X')
PYEOF
echo "Benchmarks fertig: $(date)" | tee -a $LOG

# ── 3. ETF-Preise aktualisieren ──────────────────────────────────────────────
echo "[3/4] Download: ETF-Preise..." | tee -a $LOG
python3 scripts/10_download_etf_prices.py >> $LOG 2>&1
echo "ETF-Preise fertig: $(date)" | tee -a $LOG

# ── 4. RSL berechnen + Backtest ──────────────────────────────────────────────
echo "[4/4] RSL-Berechnung + Backtest..." | tee -a $LOG
php scripts/03_calculate_rsl.php >> $LOG 2>&1
echo "RSL fertig: $(date)" | tee -a $LOG
php scripts/04_run_backtest.php >> $LOG 2>&1
echo "Backtest fertig: $(date)" | tee -a $LOG

# ── Verifikation ─────────────────────────────────────────────────────────────
echo "" | tee -a $LOG
echo "=== Verifikation ===" | tee -a $LOG
mysql -u root -prsl2024 rsl_system -e "
SELECT universe, MAX(ranking_date) as latest FROM rsl_rankings GROUP BY universe;
SELECT ticker, MAX(price_date) as latest FROM prices
WHERE ticker IN ('AAPL','SAP.DE','SPY','^GDAXI','ACWI','EURUSD=X') GROUP BY ticker;
" 2>/dev/null | tee -a $LOG

echo "" | tee -a $LOG
echo "=== Update abgeschlossen: $(date) ===" | tee -a $LOG

# Alte Logs löschen (älter als 30 Tage)
find /tmp -name 'rsl_weekly_*.log' -mtime +30 -delete 2>/dev/null

exit 0
