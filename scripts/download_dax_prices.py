#!/usr/bin/env python3
"""
DAX-Kurse via yfinance downloaden und in MariaDB speichern.
Lädt alle Aktien aus der stocks-Tabelle mit universe='dax'.

Aufruf:
  python3 scripts/download_dax_prices.py
  python3 scripts/download_dax_prices.py --update   # nur fehlende Tage
"""

import sys
import time
import argparse
import datetime
import mysql.connector
import yfinance as yf
import warnings
warnings.filterwarnings('ignore')

# ── Konfiguration ──────────────────────────────────────────────────────────
DB_CONFIG = {
    'host':    'localhost',
    'user':    'root',
    'password':'',
    'database':'rsl_system',
    'charset': 'utf8mb4',
}

DATA_START = '2009-06-01'   # Warmup für 26W-SMA vor Backtest-Start 2010-01
DATA_END   = datetime.date.today().isoformat()
BATCH_SIZE = 5              # Ticker pro yfinance-Batch-Download
DELAY_SEC  = 1.0            # Pause zwischen Batches

def get_db():
    return mysql.connector.connect(**DB_CONFIG)

def get_dax_tickers(cur):
    cur.execute("SELECT ticker FROM stocks WHERE universe='dax' ORDER BY ticker")
    return [r[0] for r in cur.fetchall()]

def get_last_date(cur, ticker):
    cur.execute("SELECT MAX(price_date) FROM prices WHERE ticker=%s", (ticker,))
    row = cur.fetchone()
    return str(row[0]) if row and row[0] else None

def download_and_insert(cur, tickers, from_date, to_date):
    """Lädt Kurse für mehrere Ticker in einem Batch."""
    tickers_str = ' '.join(tickers)
    try:
        data = yf.download(
            tickers_str,
            start=from_date,
            end=to_date,
            auto_adjust=True,
            progress=False,
            group_by='ticker' if len(tickers) > 1 else None,
        )
    except Exception as e:
        print(f"  [FEHLER] Batch download: {e}")
        return {}

    inserted_counts = {t: 0 for t in tickers}

    for ticker in tickers:
        try:
            if len(tickers) == 1:
                df = data
            else:
                if ticker not in data.columns.get_level_values(0):
                    continue
                df = data[ticker]

            if df is None or df.empty:
                continue

            for date_idx, row in df.iterrows():
                price_date = date_idx.strftime('%Y-%m-%d')
                close_val  = float(row['Close'])  if 'Close'  in row and row['Close']  == row['Close']  else None
                open_val   = float(row['Open'])   if 'Open'   in row and row['Open']   == row['Open']   else None
                high_val   = float(row['High'])   if 'High'   in row and row['High']   == row['High']   else None
                low_val    = float(row['Low'])    if 'Low'    in row and row['Low']    == row['Low']    else None
                volume_val = int(row['Volume'])   if 'Volume' in row and row['Volume'] == row['Volume'] else None

                if close_val is None or close_val <= 0:
                    continue

                cur.execute("""
                    INSERT INTO prices
                        (ticker, price_date, open, high, low, close, adj_close, volume)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        open=VALUES(open), high=VALUES(high), low=VALUES(low),
                        close=VALUES(close), adj_close=VALUES(adj_close),
                        volume=VALUES(volume)
                """, (ticker, price_date, open_val, high_val, low_val,
                      close_val, close_val, volume_val))
                inserted_counts[ticker] += 1

        except Exception as e:
            print(f"  [FEHLER] {ticker}: {e}")

    return inserted_counts

def log_status(cur, ticker, from_date, to_date, rows, status, err=None):
    cur.execute("""
        INSERT INTO download_log
            (ticker, last_download, from_date, to_date, rows_inserted, status, error_msg)
        VALUES (%s, NOW(), %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            last_download=NOW(), from_date=%s, to_date=%s,
            rows_inserted=%s, status=%s, error_msg=%s
    """, (ticker, from_date, to_date, rows, status, err,
          from_date, to_date, rows, status, err))

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--update', action='store_true', help='Nur fehlende Tage nachtragen')
    args = parser.parse_args()

    print("=== DAX Preise downloaden (yfinance) ===")
    print(f"Zeitraum: {DATA_START} bis {DATA_END}")
    print(f"Modus: {'Update (nur fehlende Tage)' if args.update else 'Vollständig'}\n")

    db  = get_db()
    cur = db.cursor()

    tickers = get_dax_tickers(cur)
    # DAX-Benchmark hinzufügen
    if '^GDAXI' not in tickers:
        tickers.append('^GDAXI')

    print(f"Ticker zu verarbeiten: {len(tickers)}\n")

    total    = len(tickers)
    success  = 0
    errors   = 0
    skipped  = 0

    # Verarbeite in Batches
    i = 0
    while i < total:
        batch = tickers[i:i+BATCH_SIZE]
        i += BATCH_SIZE

        # Im Update-Modus: Datumsbereiche pro Ticker bestimmen
        if args.update:
            batch_filtered = []
            for t in batch:
                last = get_last_date(cur, t)
                if last and last >= DATA_END:
                    skipped += 1
                    continue
                batch_filtered.append(t)
            if not batch_filtered:
                continue
            # Ältestes from_date im Batch (damit alle nötigen Daten kommen)
            from_dates = []
            for t in batch_filtered:
                last = get_last_date(cur, t)
                from_dates.append((datetime.datetime.strptime(last, '%Y-%m-%d') + datetime.timedelta(days=1)).strftime('%Y-%m-%d') if last else DATA_START)
            from_date = min(from_dates)
            batch = batch_filtered
        else:
            from_date = DATA_START

        pct = round((i - BATCH_SIZE) / total * 100, 1)
        print(f"[{min(i, total)}/{total} | {pct}%] Batch: {', '.join(batch)} ({from_date} → {DATA_END})")

        counts = download_and_insert(cur, batch, from_date, DATA_END)
        db.commit()

        for t in batch:
            rows = counts.get(t, 0)
            if rows > 0:
                log_status(cur, t, from_date, DATA_END, rows, 'ok')
                print(f"  [OK] {t}: {rows} Datenpunkte")
                success += 1
            else:
                log_status(cur, t, from_date, DATA_END, 0, 'error', 'Keine Daten')
                print(f"  [FEHLER] {t}: Keine Daten erhalten")
                errors += 1
        db.commit()

        if i < total:
            time.sleep(DELAY_SEC)

    cur.close()
    db.close()

    print(f"\n=== Download abgeschlossen ===")
    print(f"Erfolgreich: {success}")
    print(f"Fehler:      {errors}")
    print(f"Übersprungen:{skipped}")
    print(f"\nNächster Schritt: php scripts/03_calculate_rsl.php --universe=dax")

if __name__ == '__main__':
    main()
