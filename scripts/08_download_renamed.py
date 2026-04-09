#!/usr/bin/env python3
"""
Download historische Preisdaten für umbenannte Ticker.
Lädt Daten unter dem NEUEN Yahoo-Symbol, speichert sie unter dem ALTEN Ticker.
"""

import sys, os, subprocess, time, warnings
from datetime import date
warnings.filterwarnings('ignore')

ROOT      = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_START = '2019-06-01'
DATA_END   = date.today().isoformat()
MYSQL_ARGS = ['/Applications/XAMPP/xamppfiles/bin/mysql', '-u', 'root', 'rsl_system']

# Alter Ticker → Neuer Yahoo-Ticker
# Nur für Unternehmen, die umbenannt wurden (nicht übernommen/delisted)
RENAME_MAP = {
    'ABC':   'COR',   # AmerisourceBergen → Cencora (2023)
    'ANTM':  'ELV',   # Anthem → Elevance Health (2022)
    'FLT':   'CPAY',  # FleetCor → Corpay (2024)
    'PEAK':  'DOC',   # Healthpeak Properties → DOC (2024)
    'PKI':   'RVTY',  # PerkinElmer → Revvity (2023)
    'DISCA': 'WBD',   # Discovery → Warner Bros. Discovery (2022)
    'DISCK': 'WBD',   # Discovery Class C → Warner Bros. Discovery
    'CTL':   'LUMN',  # CenturyLink → Lumen Technologies (2020)
    'HCP':   'DOC',   # HCP → Healthpeak → DOC
    'MXIM':  'ADI',   # Maxim Integrated → Analog Devices (merged 2021)
}

def mysql_exec_file(sql: str) -> bool:
    tmp = '/tmp/rsl_rename_import.sql'
    with open(tmp, 'w') as f:
        f.write(sql)
    r = subprocess.run(
        f"{MYSQL_ARGS[0]} -u root rsl_system < {tmp}",
        shell=True, capture_output=True, text=True
    )
    os.remove(tmp)
    return r.returncode == 0

def mysql_query(sql: str) -> str:
    r = subprocess.run(MYSQL_ARGS + ['-e', sql], capture_output=True, text=True)
    return r.stdout

def download_and_store(old_ticker: str, yahoo_ticker: str):
    import yfinance as yf
    print(f"  {old_ticker} (via {yahoo_ticker})... ", end='', flush=True)

    t = yf.Ticker(yahoo_ticker)
    df = t.history(start=DATA_START, end=DATA_END, auto_adjust=False)

    if df is None or df.empty:
        print("KEINE DATEN")
        return 0

    lines = []
    for dt_idx, row in df.iterrows():
        dt_str = dt_idx.strftime('%Y-%m-%d')
        close = row.get('Close')
        if close is None:
            continue
        o = float(row.get('Open', close))
        h = float(row.get('High', close))
        l = float(row.get('Low', close))
        c = float(close)
        a = float(row.get('Adj Close', close))
        v = int(row.get('Volume', 0) or 0)
        lines.append(
            f"INSERT INTO prices (ticker,price_date,open,high,low,close,adj_close,volume) "
            f"VALUES ('{old_ticker}','{dt_str}',{o:.4f},{h:.4f},{l:.4f},{c:.4f},{a:.4f},{v}) "
            f"ON DUPLICATE KEY UPDATE open=VALUES(open),high=VALUES(high),low=VALUES(low),"
            f"close=VALUES(close),adj_close=VALUES(adj_close),volume=VALUES(volume);"
        )

    if not lines:
        print("KEINE ZEILEN")
        return 0

    sql = '\n'.join(lines)
    ok = mysql_exec_file(sql)

    n = len(lines)
    if ok:
        mysql_query(
            f"INSERT INTO download_log (ticker,last_download,from_date,to_date,rows_inserted,status,error_msg) "
            f"VALUES ('{old_ticker}',NOW(),'{DATA_START}','{DATA_END}',{n},'ok',NULL) "
            f"ON DUPLICATE KEY UPDATE last_download=NOW(),rows_inserted={n},status='ok',error_msg=NULL"
        )
        print(f"OK ({n} Zeilen via {yahoo_ticker})")
    else:
        print("DB-FEHLER")
        n = 0

    return n

def main():
    try:
        import yfinance
    except ImportError:
        print("ERROR: pip3 install yfinance")
        sys.exit(1)

    # Welche alten Ticker noch keine Daten haben?
    out = mysql_query(
        "SELECT ticker FROM download_log WHERE status='error' OR ticker NOT IN "
        "(SELECT DISTINCT ticker FROM prices)"
    )
    error_tickers = set(l.strip() for l in out.strip().split('\n') if l.strip() and l.strip() != 'ticker')

    to_process = {
        old: new for old, new in RENAME_MAP.items()
        if old in error_tickers
    }

    if not to_process:
        print("Alle Rename-Ticker bereits heruntergeladen.")
        return

    print(f"=== Renamed Ticker Downloader ({len(to_process)} Ticker) ===\n")
    total = 0
    for old_ticker, yahoo_ticker in sorted(to_process.items()):
        n = download_and_store(old_ticker, yahoo_ticker)
        total += n
        time.sleep(2)

    print(f"\n=== Fertig: {total} Zeilen gesamt ===")
    out = mysql_query("SELECT COUNT(DISTINCT ticker) as t, COUNT(*) as r FROM prices")
    lines = [l for l in out.strip().split('\n') if l.strip()]
    if len(lines) >= 2:
        print(f"DB: {lines[1]}")

if __name__ == '__main__':
    main()
