#!/usr/bin/env python3
"""
Script 09 — M&A Activity Check
Prüft die Top-N Kandidaten auf laufende Übernahme-/M&A-Aktivität
via yfinance-News und aktualisiert die m_and_a_flags-Tabelle.

Aufruf:
  python3 scripts/09_check_ma_activity.py            # prüft Top-50 nach RSL
  python3 scripts/09_check_ma_activity.py AAPL MSFT  # prüft spezifische Ticker
"""

import sys
import subprocess
import json
from datetime import date, timedelta

MYSQL   = '/Applications/XAMPP/xamppfiles/bin/mysql'
DB      = 'rsl_system'
TOP_N   = 50   # Anzahl Top-RSL-Aktien, die geprüft werden

# M&A-Keywords (Englisch + Deutsch)
MA_KEYWORDS = [
    # Englisch
    'merger', 'merging', 'merged',
    'acquisition', 'acquired', 'acquires', 'acquirer',
    'takeover', 'take-over', 'take over',
    'buyout', 'buy-out', 'buy out',
    'going private',
    'tender offer',
    'deal to buy', 'agreed to buy', 'agreed to acquire',
    'to be acquired', 'purchased by', 'to acquire',
    'bid for', 'offer for', 'offer to buy',
    'strategic alternatives',
    # Deutsch
    'übernahme', 'übernimmt', 'übernommen',
    'fusion', 'fusioniert',
    'akquisition', 'kaufangebot', 'pflichtangebot',
    'öffentliches angebot',
]

def run_mysql(query):
    result = subprocess.run(
        [MYSQL, '-u', 'root', DB, '-N', '-e', query],
        capture_output=True, text=True
    )
    return result.stdout.strip()

def get_top_tickers():
    """Holt Top-N Ticker nach aktuellem RSL aus der DB."""
    latest = run_mysql("SELECT MAX(ranking_date) FROM rsl_rankings;")
    if not latest:
        return []
    rows = run_mysql(
        f"SELECT ticker FROM rsl_rankings "
        f"WHERE ranking_date = '{latest}' "
        f"ORDER BY rank_overall ASC LIMIT {TOP_N};"
    )
    return [r.strip() for r in rows.split('\n') if r.strip()]

def check_ma_news(ticker):
    """
    Prüft yfinance-News eines Tickers auf M&A-Keywords.
    Gibt (True, headline) oder (False, None) zurück.
    """
    try:
        import yfinance as yf
        yahoo_ticker = ticker.replace('.', '-')
        stock = yf.Ticker(yahoo_ticker)
        news  = stock.news or []

        for article in news[:15]:   # letzte 15 Meldungen
            title   = (article.get('title')   or '').lower()
            summary = (article.get('summary') or article.get('description') or '').lower()
            text    = title + ' ' + summary

            # Ticker muss im Text vorkommen (Kontextfilter)
            ticker_in_text = (
                ticker.lower() in text
                or yahoo_ticker.lower() in text
                or stock.info.get('shortName', '').lower()[:8] in text
            )
            if not ticker_in_text:
                continue

            for kw in MA_KEYWORDS:
                if kw in text:
                    headline = article.get('title', '')[:200]
                    return True, headline

        return False, None

    except Exception as e:
        print(f"  [{ticker}] Warnung: {e}")
        return False, None

def update_db(ticker, headline, today):
    """Setzt M&A-Flag in DB oder deaktiviert es falls keine Treffer."""
    t   = ticker.replace("'", "''")
    h   = (headline or '').replace("'", "''")
    d   = today.isoformat()

    # Alten Eintrag löschen und neu schreiben (einfachste idempotente Lösung)
    run_mysql(f"DELETE FROM m_and_a_flags WHERE ticker='{t}';")

    if headline:
        run_mysql(
            f"INSERT INTO m_and_a_flags "
            f"(ticker, headline, flagged_date, checked_date, is_active) "
            f"VALUES ('{t}', '{h}', '{d}', '{d}', 1);"
        )
    else:
        # Sauberer Eintrag: geprüft, kein Fund
        run_mysql(
            f"INSERT INTO m_and_a_flags "
            f"(ticker, headline, flagged_date, checked_date, is_active) "
            f"VALUES ('{t}', NULL, '{d}', '{d}', 0);"
        )

def main():
    today = date.today()
    print("=== M&A Activity Check ===")
    print(f"Datum: {today}\n")

    # Ticker bestimmen
    if len(sys.argv) > 1:
        tickers = [t.upper() for t in sys.argv[1:]]
        print(f"Prüfe {len(tickers)} angegebene Ticker: {', '.join(tickers)}\n")
    else:
        tickers = get_top_tickers()
        print(f"Prüfe Top-{len(tickers)} RSL-Kandidaten aus der Datenbank\n")

    if not tickers:
        print("Keine Ticker gefunden. RSL-Berechnung (Script 03) zuerst ausführen.")
        return

    flagged  = []
    clean    = []

    for ticker in tickers:
        print(f"  Prüfe {ticker:<8}", end='', flush=True)
        has_ma, headline = check_ma_news(ticker)

        if has_ma:
            print(f"  ⚠️  M&A: {headline[:70]}")
            flagged.append((ticker, headline))
        else:
            print("  ✓")
            clean.append(ticker)

        update_db(ticker, headline, today)

    print(f"\n{'='*50}")
    print(f"Ergebnis: {len(flagged)} M&A-Flag(s), {len(clean)} unauffällig")

    if flagged:
        print("\nGeflaggte Ticker (werden bei Selektion übersprungen):")
        for t, h in flagged:
            print(f"  ⚠️  {t}: {h[:80]}")

    # Alte Flags (> 30 Tage nicht geprüft) automatisch deaktivieren
    cutoff = (today - timedelta(days=30)).isoformat()
    run_mysql(
        f"UPDATE m_and_a_flags SET is_active=0 "
        f"WHERE checked_date < '{cutoff}' AND is_active=1;"
    )
    print(f"\nAlte Flags (> 30 Tage) automatisch deaktiviert.")
    print("=== Fertig ===\n")

if __name__ == '__main__':
    main()
