#!/usr/bin/env python3
"""
Sprint 2 Ergänzung — yfinance Downloader für fehlende/fehlerhafte Ticker
Umgeht Yahoo Finance Rate-Limit der curl-Methode

Aufruf:
  python3 scripts/07_download_yfinance.py              # alle fehlenden Ticker
  python3 scripts/07_download_yfinance.py AAPL MSFT    # nur bestimmte Ticker
"""

import sys
import os
import json
import subprocess
import time
import warnings
from datetime import date

warnings.filterwarnings('ignore')

# Projekt-Root
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

DATA_START = '2009-06-01'   # Warmup für 26W-SMA vor Backtest-Start 2010-01
DATA_END   = date.today().isoformat()
DELAY_SEC  = 1.5   # Sekunden zwischen Ticker-Downloads

MYSQL_BIN  = '/Applications/XAMPP/xamppfiles/bin/mysql'
MYSQL_ARGS = [MYSQL_BIN, '-u', 'root', 'rsl_system']

def mysql_query(sql: str) -> str:
    """SQL über mysql-CLI ausführen, stdout zurückgeben."""
    result = subprocess.run(
        MYSQL_ARGS + ['-e', sql],
        capture_output=True, text=True
    )
    return result.stdout

def mysql_exec(sql: str) -> bool:
    """SQL ausführen, True bei Erfolg."""
    result = subprocess.run(
        MYSQL_ARGS + ['-e', sql],
        capture_output=True, text=True
    )
    return result.returncode == 0

def get_missing_tickers():
    """Ticker ohne Preisdaten, mit Fehler, oder mit unvollständiger Historie laden."""
    out = mysql_query(f"""
        SELECT s.ticker
        FROM stocks s
        LEFT JOIN download_log dl ON dl.ticker = s.ticker
        LEFT JOIN (SELECT ticker, MIN(price_date) AS earliest FROM prices GROUP BY ticker) p
          ON p.ticker = s.ticker
        WHERE dl.ticker IS NULL
           OR dl.status = 'error'
           OR p.earliest IS NULL
           OR p.earliest > '{DATA_START}'
        GROUP BY s.ticker
        ORDER BY s.ticker
    """)
    lines = [l.strip() for l in out.strip().split('\n') if l.strip() and l.strip() != 'ticker']
    return lines

def get_backfill_tickers():
    """Ticker zurückgeben, für die historische Lücke (vor erstem vorhandenen Datum) besteht.
    Gibt Liste von (ticker, end_date) zurück, wobei end_date = ältestes vorhandenes Datum - 1 Tag."""
    out = mysql_query(f"""
        SELECT s.ticker, MIN(p.price_date) AS earliest
        FROM stocks s
        JOIN prices p ON p.ticker = s.ticker
        GROUP BY s.ticker
        HAVING earliest > '{DATA_START}'
        ORDER BY s.ticker
    """)
    result = []
    for line in out.strip().split('\n'):
        parts = line.strip().split('\t')
        if len(parts) == 2 and parts[0] != 'ticker':
            from datetime import datetime, timedelta
            earliest = datetime.strptime(parts[1].strip(), '%Y-%m-%d')
            end_date = (earliest - timedelta(days=1)).strftime('%Y-%m-%d')
            result.append((parts[0].strip(), end_date))
    return result

def download_ticker(ticker: str, start: str = None, end: str = None):
    """Daten für einen Ticker via yfinance laden, als Liste von Dicts zurückgeben."""
    import yfinance as yf

    dl_start = start or DATA_START
    dl_end   = end   or DATA_END

    # Yahoo Finance Ticker-Normalisierung (BRK.B → BRK-B)
    yahoo_ticker = ticker.replace('.', '-')

    t = yf.Ticker(yahoo_ticker)
    df = t.history(start=dl_start, end=dl_end, auto_adjust=False)

    if df is None or df.empty:
        return None

    rows = []
    for dt_idx, row in df.iterrows():
        dt_str = dt_idx.strftime('%Y-%m-%d')
        close = row.get('Close')
        if close is None or (hasattr(close, '__bool__') and not close):
            continue
        rows.append({
            'date':      dt_str,
            'open':      float(row.get('Open', close)),
            'high':      float(row.get('High', close)),
            'low':       float(row.get('Low', close)),
            'close':     float(close),
            'adj_close': float(row.get('Adj Close', close)),
            'volume':    int(row.get('Volume', 0) or 0),
        })
    return rows if rows else None

def insert_prices(ticker: str, rows: list) -> int:
    """Preisdaten via LOAD DATA INFILE oder einzelne INSERTs schreiben."""
    if not rows:
        return 0

    # Batch-INSERT über temporäre SQL-Datei
    tmp_file = f'/tmp/rsl_prices_{ticker.replace(".", "_")}.sql'
    lines = ['SET foreign_key_checks=0;']
    for r in rows:
        lines.append(
            f"INSERT INTO prices (ticker, price_date, open, high, low, close, adj_close, volume) "
            f"VALUES ('{ticker}', '{r['date']}', {r['open']:.4f}, {r['high']:.4f}, "
            f"{r['low']:.4f}, {r['close']:.4f}, {r['adj_close']:.4f}, {r['volume']}) "
            f"ON DUPLICATE KEY UPDATE "
            f"open=VALUES(open), high=VALUES(high), low=VALUES(low), "
            f"close=VALUES(close), adj_close=VALUES(adj_close), volume=VALUES(volume);"
        )

    with open(tmp_file, 'w') as f:
        f.write('\n'.join(lines))

    result = subprocess.run(
        MYSQL_ARGS + [f'< {tmp_file}'],
        shell=False, capture_output=True, text=True
    )

    # Alternativ: direkter Import
    result2 = subprocess.run(
        f"{MYSQL_BIN} -u root rsl_system < {tmp_file}",
        shell=True, capture_output=True, text=True
    )

    os.remove(tmp_file)
    return len(rows) if result2.returncode == 0 else 0

def update_log(ticker: str, n_rows: int, status: str, error: str = None):
    """download_log aktualisieren."""
    today = DATA_END
    err_val = f"'{error[:200]}'" if error else 'NULL'
    sql = f"""
        INSERT INTO download_log (ticker, last_download, from_date, to_date, rows_inserted, status, error_msg)
        VALUES ('{ticker}', NOW(), '{DATA_START}', '{today}', {n_rows}, '{status}', {err_val})
        ON DUPLICATE KEY UPDATE
          last_download=NOW(), from_date='{DATA_START}', to_date='{today}',
          rows_inserted={n_rows}, status='{status}', error_msg={err_val}
    """
    mysql_exec(sql)

def get_update_tickers():
    """Alle Ticker mit ihrem letzten Preisdatum zurückgeben (für --update Modus)."""
    out = mysql_query("""
        SELECT s.ticker, COALESCE(MAX(p.price_date), '2009-01-01') AS last_date
        FROM stocks s
        LEFT JOIN prices p ON p.ticker = s.ticker
        GROUP BY s.ticker
        ORDER BY s.ticker
    """)
    result = []
    for line in out.strip().split('\n'):
        parts = line.strip().split('\t')
        if len(parts) == 2 and parts[0] != 'ticker':
            from datetime import datetime, timedelta
            last = parts[1].strip()
            # Nächsten Tag als Startdatum
            next_day = (datetime.strptime(last, '%Y-%m-%d') + timedelta(days=1)).strftime('%Y-%m-%d')
            if next_day <= DATA_END:
                result.append((parts[0].strip(), next_day, DATA_END))
    return result

def main():
    try:
        import yfinance
    except ImportError:
        print("ERROR: yfinance nicht installiert. Bitte: pip3 install yfinance")
        sys.exit(1)

    backfill_mode = '--backfill' in sys.argv
    update_mode   = '--update'   in sys.argv
    cli_tickers   = [a for a in sys.argv[1:] if not a.startswith('--')]

    if backfill_mode:
        ticker_jobs = [(t, DATA_START, end) for t, end in get_backfill_tickers()]
        print(f"=== yfinance Backfill ({len(ticker_jobs)} Ticker mit Lücke vor {DATA_START}) ===\n")
    elif update_mode:
        ticker_jobs = get_update_tickers()
        print(f"=== yfinance Update ({len(ticker_jobs)} Ticker, nur fehlende Tage) ===")
        print(f"Bis: {DATA_END}\n")
    elif cli_tickers:
        ticker_jobs = [(t, DATA_START, DATA_END) for t in cli_tickers]
        print(f"=== yfinance Downloader ({len(ticker_jobs)} Ticker) ===")
        print(f"Zeitraum: {DATA_START} bis {DATA_END}\n")
    else:
        ticker_jobs = [(t, DATA_START, DATA_END) for t in get_missing_tickers()]
        print(f"=== yfinance Downloader ({len(ticker_jobs)} Ticker) ===")
        print(f"Zeitraum: {DATA_START} bis {DATA_END}\n")

    total = len(ticker_jobs)
    ok = 0; fail = 0; skip = 0

    for idx, job in enumerate(ticker_jobs, 1):
        ticker, start_date, end_date = job
        print(f"[{idx}/{total}] {ticker} ({start_date} → {end_date})... ", end='', flush=True)

        try:
            rows = download_ticker(ticker, start=start_date, end=end_date)
            if rows is not None and not rows:
                print("Keine neuen Daten (bereits aktuell)")
                skip += 1
                continue
        except Exception as e:
            print(f"FEHLER: {e}")
            update_log(ticker, 0, 'error', str(e))
            fail += 1
            time.sleep(DELAY_SEC)
            continue

        if rows is None:
            print("KEINE DATEN (delisted/acquired?)")
            update_log(ticker, 0, 'error', 'no data returned by yfinance')
            fail += 1
            time.sleep(DELAY_SEC)
            continue

        n = insert_prices(ticker, rows)
        if n > 0:
            update_log(ticker, n, 'ok')
            print(f"OK ({n} Zeilen)")
            ok += 1
        else:
            print("DB-FEHLER beim Einfügen")
            update_log(ticker, 0, 'error', 'DB insert failed')
            fail += 1

        time.sleep(DELAY_SEC)

    print(f"\n=== Fertig: {ok} OK, {fail} Fehler, {skip} übersprungen ===")

    # Statistik
    out = mysql_query("SELECT COUNT(DISTINCT ticker) as t, COUNT(*) as r FROM prices")
    lines = [l for l in out.strip().split('\n') if l.strip()]
    if len(lines) >= 2:
        print(f"DB: {lines[1]}")

if __name__ == '__main__':
    main()
