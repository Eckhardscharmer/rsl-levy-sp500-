#!/usr/bin/env python3
"""Download SPY und EURUSD=X Benchmark-Daten in die Datenbank."""
import sys, subprocess, shutil

MYSQL = shutil.which('mysql') or '/usr/bin/mysql'
MYSQL_ARGS = [MYSQL, '-u', 'root', '-prsl2024', 'rsl_system']

def mysql_exec(sql):
    subprocess.run(MYSQL_ARGS + ['-e', sql], capture_output=True)

try:
    import yfinance as yf
except ImportError:
    subprocess.run([sys.executable, '-m', 'pip', 'install', 'yfinance', '-q'])
    import yfinance as yf

for ticker in ['SPY', 'EURUSD=X']:
    print(f"Lade {ticker}...")
    df = yf.download(ticker, start='2009-06-01', auto_adjust=False, progress=False)
    df = df.reset_index()
    count = 0
    for _, r in df.iterrows():
        try:
            d    = str(r['Date'].date())
            o    = float(r['Open'].iloc[0])   if hasattr(r['Open'], 'iloc') else float(r['Open'])
            h    = float(r['High'].iloc[0])   if hasattr(r['High'], 'iloc') else float(r['High'])
            lo   = float(r['Low'].iloc[0])    if hasattr(r['Low'],  'iloc') else float(r['Low'])
            c    = float(r['Close'].iloc[0])  if hasattr(r['Close'],'iloc') else float(r['Close'])
            ac   = float(r['Adj Close'].iloc[0]) if hasattr(r['Adj Close'],'iloc') else float(r['Adj Close'])
            vol  = int(r['Volume'].iloc[0])   if hasattr(r['Volume'],'iloc') else int(r['Volume'])
            sql  = f"INSERT IGNORE INTO prices (ticker,price_date,open,high,low,close,adj_close,volume) VALUES ('{ticker}','{d}',{o:.4f},{h:.4f},{lo:.4f},{c:.4f},{ac:.4f},{vol})"
            mysql_exec(sql)
            count += 1
        except Exception as e:
            pass
    print(f"  {count} Zeilen importiert.")
print("Fertig.")
