#!/usr/bin/env python3
"""
ETF-Preise via yfinance downloaden und direkt per MySQL-CLI einfügen.
Aufruf: python3 scripts/10_download_etf_prices.py
"""
import sys, os, subprocess, json, shutil
from datetime import datetime

MYSQL = '/Applications/XAMPP/xamppfiles/bin/mysql'
DB    = 'rsl_system'
USER  = 'root'
PASS  = ''
START = '1998-01-01'
END   = datetime.today().strftime('%Y-%m-%d')

TICKERS = ['^GSPC', '^NDX', '^STOXX', '^N225', 'EEM', 'GC=F', 'AGG', 'SHY']

def mysql_exec(sql):
    cmd = [MYSQL, '-u', USER, DB] if not PASS else [MYSQL, '-u', USER, f'-p{PASS}', DB]
    result = subprocess.run(cmd, input=sql, capture_output=True, text=True)
    return result.returncode == 0

def download_and_insert(ticker):
    import yfinance as yf
    import warnings; warnings.filterwarnings('ignore')

    print(f"  ↓ {ticker} ({START} → {END})...", end='', flush=True)
    df = yf.download(ticker, start=START, end=END, auto_adjust=False, progress=False, timeout=30)
    if df.empty:
        print(" FEHLER (keine Daten)")
        return 0

    # MultiIndex abflachen falls nötig
    if hasattr(df.columns, 'levels'):
        df.columns = df.columns.get_level_values(0)

    lines = []
    for date_idx, row in df.iterrows():
        d     = str(date_idx)[:10]
        o     = float(row.get('Open', row.get('Close', 0)) or 0)
        h     = float(row.get('High', row.get('Close', 0)) or 0)
        lo    = float(row.get('Low',  row.get('Close', 0)) or 0)
        c     = float(row.get('Close', 0) or 0)
        ac    = float(row.get('Adj Close', c) or c)
        vol   = int(row.get('Volume', 0) or 0)
        if c <= 0: continue
        lines.append(
            f"('{ticker}','{d}',{o:.4f},{h:.4f},{lo:.4f},{c:.4f},{ac:.4f},{vol})"
        )

    if not lines:
        print(" FEHLER (alle Kurse null)")
        return 0

    # Batch-Insert in Blöcken von 500
    inserted = 0
    for i in range(0, len(lines), 500):
        chunk = lines[i:i+500]
        sql = (
            "INSERT INTO prices (ticker,price_date,open,high,low,close,adj_close,volume) VALUES "
            + ",".join(chunk)
            + " ON DUPLICATE KEY UPDATE open=VALUES(open),high=VALUES(high),low=VALUES(low),"
              "close=VALUES(close),adj_close=VALUES(adj_close),volume=VALUES(volume);"
        )
        if mysql_exec(sql):
            inserted += len(chunk)

    print(f" {inserted} Zeilen")
    return inserted

def main():
    print(f"=== ETF-Preise downloaden ({START} → {END}) ===\n")
    total = 0
    for t in TICKERS:
        total += download_and_insert(t)
    print(f"\nGesamt: {total} Zeilen eingefügt/aktualisiert")
    print("\nNächster Schritt: php scripts/09_setup_etf.php --calc-only")

if __name__ == '__main__':
    main()
