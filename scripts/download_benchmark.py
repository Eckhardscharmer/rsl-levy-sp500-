#!/usr/bin/env python3
"""Download SPY und EURUSD=X Benchmark-Daten."""
import subprocess, shutil, sys

MYSQL = shutil.which('mysql') or '/usr/bin/mysql'
ARGS  = [MYSQL, '-u', 'root', '-prsl2024', 'rsl_system']

def run(sql):
    subprocess.run(ARGS + ['-e', sql], capture_output=True)

try:
    import yfinance as yf
except ImportError:
    subprocess.run([sys.executable,'-m','pip','install','yfinance','-q'])
    import yfinance as yf

for ticker in ['SPY', 'EURUSD=X']:
    print(f"Lade {ticker}...")
    t  = yf.Ticker(ticker)
    df = t.history(start='2009-06-01', auto_adjust=False)
    df.index = df.index.tz_localize(None)
    count = 0
    for date, row in df.iterrows():
        d   = date.strftime('%Y-%m-%d')
        o   = float(row.get('Open',  0) or 0)
        h   = float(row.get('High',  0) or 0)
        lo  = float(row.get('Low',   0) or 0)
        c   = float(row.get('Close', 0) or 0)
        ac  = float(row.get('Adj Close', c) or c)
        vol = int(row.get('Volume', 0) or 0)
        sql = (f"INSERT IGNORE INTO prices "
               f"(ticker,price_date,open,high,low,close,adj_close,volume) VALUES "
               f"('{ticker}','{d}',{o:.4f},{h:.4f},{lo:.4f},{c:.4f},{ac:.4f},{vol})")
        run(sql)
        count += 1
    print(f"  {count} Zeilen importiert.")
print("Fertig.")
