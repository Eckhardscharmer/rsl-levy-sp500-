#!/usr/bin/env python3
"""
scripts/check_index_composition.py
Quartalsweiser Check der Indexzusammensetzungen gegen Wikipedia.

Vergleicht die aktuellen Mitglieder von S&P 500, DAX 40 und MDAX
mit der lokalen DB-Tabelle `stocks` und gibt einen Diff-Report aus.

Aufruf:
  python3 scripts/check_index_composition.py [--send-mail]

Cron (quartalsweise, 1. März/Juni/Sept/Dez, 07:00 UTC):
  0 7 1 3,6,9,12 * python3 /var/www/rsl/scripts/check_index_composition.py \
      >> /tmp/rsl_index_check.log 2>&1
"""

import sys, subprocess, re, smtplib, socket
from datetime import date
from email.mime.text import MIMEText

try:
    import requests
    from bs4 import BeautifulSoup
except ImportError:
    print("FEHLER: pip3 install requests beautifulsoup4")
    sys.exit(1)

# ── Konfiguration ────────────────────────────────────────────────────────────
MAIL_TO      = 'eckhard.scharmer@web.de'
MAIL_FROM    = 'rsl-check@investsignal.de'
SEND_MAIL    = '--send-mail' in sys.argv
DB_NAME      = 'rsl_system'
DB_USER      = 'root'
DB_PASS      = 'rsl2024'
HEADERS      = {'User-Agent': 'Mozilla/5.0 (compatible; RSL-IndexCheck/1.0)'}
TODAY        = date.today().isoformat()

def mysql(sql):
    """Führt SQL aus und gibt Zeilen als Liste von Dicts zurück."""
    r = subprocess.run(
        ['mysql', '-u', DB_USER, f'-p{DB_PASS}', DB_NAME,
         '-N', '--batch', '-e', sql],
        capture_output=True, text=True
    )
    if r.returncode != 0:
        raise RuntimeError(f"MySQL-Fehler: {r.stderr}")
    rows = []
    for line in r.stdout.strip().split('\n'):
        if line:
            rows.append(line.strip())
    return rows

def get_db_tickers(universe):
    rows = mysql(f"SELECT ticker FROM stocks WHERE universe='{universe}' ORDER BY ticker")
    return set(rows)

def make_mdax_diff(wiki_names, db_mdax):
    """Namen-basierter Vergleich für MDAX (Wikipedia hat keine Ticker-Spalte)."""
    db_names = {normalize_name(n): t for t, n in db_mdax.items()}
    wiki_normalized = {normalize_name(n): n for n in wiki_names}

    in_wiki_not_db  = [(orig, n) for n, orig in wiki_normalized.items() if n not in db_names]
    in_db_not_wiki  = [(t, nm) for t, nm in db_mdax.items()
                       if normalize_name(nm) not in wiki_normalized]

    lines = []
    lines.append(f"\n{'='*60}")
    lines.append(f"  MDAX (HDAX-Ergänzung)  (DB: {len(db_mdax)} | Wikipedia: {len(wiki_names)})")
    lines.append(f"  Hinweis: Wikipedia-MDAX hat keine Ticker-Spalte → Namen-Vergleich")
    lines.append(f"{'='*60}")

    changed = False

    if in_wiki_not_db:
        changed = True
        lines.append(f"\n  NEU in Wikipedia (nicht in DB per Name) — {len(in_wiki_not_db)} Unternehmen:")
        for orig, _ in sorted(in_wiki_not_db, key=lambda x: x[0]):
            lines.append(f"    + {orig}  (Ticker manuell auf Yahoo Finance suchen, dann in DB eintragen)")

    if in_db_not_wiki:
        changed = True
        lines.append(f"\n  IN DB aber nicht mehr in Wikipedia — {len(in_db_not_wiki)} Unternehmen:")
        for t, nm in sorted(in_db_not_wiki, key=lambda x: x[1]):
            lines.append(f"    - {t}  {nm}")
        lines.append(f"\n  SQL zum Entfernen (vorher prüfen ob wirklich ausgeschieden!):")
        for t, nm in sorted(in_db_not_wiki, key=lambda x: x[1]):
            lines.append(f"    -- UPDATE stocks SET universe='delisted' WHERE ticker='{t}'; -- {nm}")

    if not changed:
        lines.append("  ✓ Alle DB-Unternehmen in Wikipedia gefunden (Namen-basiert).")

    return '\n'.join(lines), changed

# ── Ticker-Normalisierung: Wikipedia → Yahoo Finance Format ──────────────────
# Wikipedia S&P 500 liefert "BRK.B" und "BF.B" — unsere DB speichert diese
# ebenfalls mit Punkt, also keine Konvertierung nötig.
SP500_TICKER_OVERRIDES = {}   # z.B. {'GOOG': 'GOOGL'} falls nötig

# DAX: Wikipedia Xetra-Ticker vs. Yahoo Finance Ticker-Mapping
# (falls abweichend, z.B. Airbus: Xetra AIR.DE → Yahoo Finance AIR.PA)
DAX_TICKER_OVERRIDES = {
    'AIR.DE': 'AIR.PA',   # Airbus: Hauptlisting Paris (Yahoo Finance)
}

# ── S&P 500 via Wikipedia ────────────────────────────────────────────────────
def fetch_sp500_wikipedia():
    url = 'https://en.wikipedia.org/wiki/List_of_S%26P_500_companies'
    soup = BeautifulSoup(requests.get(url, headers=HEADERS, timeout=30).text, 'html.parser')
    table = soup.find('table', {'id': 'constituents'})
    if not table:
        raise RuntimeError("S&P 500 Wikipedia-Tabelle nicht gefunden")
    tickers = set()
    for row in table.find_all('tr')[1:]:
        cells = row.find_all('td')
        if cells:
            t = cells[0].get_text(strip=True)
            t = SP500_TICKER_OVERRIDES.get(t, t)
            tickers.add(t)
    return tickers

# ── DAX 40 via Wikipedia ─────────────────────────────────────────────────────
def fetch_dax_wikipedia():
    url = 'https://de.wikipedia.org/wiki/DAX'
    soup = BeautifulSoup(requests.get(url, headers=HEADERS, timeout=30).text, 'html.parser')
    tickers = set()
    for table in soup.find_all('table', {'class': 'wikitable'}):
        headers_row = table.find('tr')
        if not headers_row:
            continue
        hdrs = [th.get_text(strip=True).lower() for th in headers_row.find_all(['th', 'td'])]
        ticker_col = None
        for i, h in enumerate(hdrs):
            if 'kürzel' in h or 'ticker' in h or 'symbol' in h:
                ticker_col = i
                break
        if ticker_col is None:
            continue
        for row in table.find_all('tr')[1:]:
            cells = row.find_all(['td', 'th'])
            if len(cells) > ticker_col:
                t = cells[ticker_col].get_text(strip=True)
                t = re.sub(r'\[.*?\]', '', t).strip()
                if t and not t.startswith('^') and '.' not in t and len(t) <= 6:
                    t_de = t + '.DE'
                    # Überschreibe mit Yahoo Finance Ticker falls abweichend
                    tickers.add(DAX_TICKER_OVERRIDES.get(t_de, t_de))
    return tickers

# ── MDAX via Wikipedia (Namen-basiert, da keine Ticker-Spalte) ───────────────
def fetch_mdax_wikipedia_names():
    """Gibt bereinigte Firmennamen aus Wikipedia MDAX zurück."""
    url = 'https://de.wikipedia.org/wiki/MDAX'
    soup = BeautifulSoup(requests.get(url, headers=HEADERS, timeout=30).text, 'html.parser')
    names = []
    for table in soup.find_all('table', {'class': 'wikitable'}):
        hdrs = [th.get_text(strip=True).lower() for th in table.find('tr').find_all(['th', 'td'])]
        if 'name' not in hdrs:
            continue
        name_col = hdrs.index('name')
        for row in table.find_all('tr')[1:]:
            cells = row.find_all(['td', 'th'])
            if len(cells) > name_col:
                n = re.sub(r'\[.*?\]', '', cells[name_col].get_text(strip=True)).strip()
                if n:
                    names.append(n)
        break
    return names

def normalize_name(n):
    """Normiert Firmennamen für Vergleich: lower, Sonderzeichen entfernen."""
    n = n.lower()
    for s in [' se', ' ag', ' s.a.', ' s.a', ' plc', ' co. kgaa', ' kgaa',
              ' gmbh', ' & co', '&', '.', ',', '-', 'group']:
        n = n.replace(s, ' ')
    return ' '.join(n.split())

def get_db_mdax_names():
    rows = mysql("SELECT ticker, name FROM stocks WHERE universe='hdax' ORDER BY name")
    result = {}
    for row in rows:
        parts = row.split('\t', 1)
        if len(parts) == 2:
            result[parts[0]] = parts[1]
    return result

# ── Diff-Berechnung ──────────────────────────────────────────────────────────
def make_diff(wiki_tickers, db_tickers, universe, label):
    to_add    = sorted(wiki_tickers - db_tickers)
    to_remove = sorted(db_tickers - wiki_tickers)
    lines = []
    lines.append(f"\n{'='*60}")
    lines.append(f"  {label}  (DB: {len(db_tickers)} | Wikipedia: {len(wiki_tickers)})")
    lines.append(f"{'='*60}")

    if not to_add and not to_remove:
        lines.append("  ✓ Keine Änderungen — DB und Wikipedia stimmen überein.")
        return '\n'.join(lines), False

    if to_add:
        lines.append(f"\n  NEU in Wikipedia (nicht in DB) — {len(to_add)} Ticker:")
        for t in to_add:
            lines.append(f"    + {t}")
        lines.append(f"\n  SQL zum Hinzufügen (Ticker und Name manuell prüfen!):")
        for t in to_add:
            name = t.replace('.DE','').replace('-','.')
            lines.append(f"    INSERT IGNORE INTO stocks (ticker, name, universe, sector) VALUES ('{t}', '{name}', '{universe}', 'Unknown');")

    if to_remove:
        lines.append(f"\n  IN DB aber nicht mehr in Wikipedia — {len(to_remove)} Ticker:")
        for t in to_remove:
            lines.append(f"    - {t}")
        lines.append(f"\n  SQL zum Entfernen (vorher prüfen ob wirklich ausgeschieden!):")
        for t in to_remove:
            lines.append(f"    -- UPDATE stocks SET universe='delisted' WHERE ticker='{t}';")
            lines.append(f"    -- (Alternativ: Ticker belassen für historischen Backtest-Korrektheit)")

    return '\n'.join(lines), True

# ── Haupt-Logik ──────────────────────────────────────────────────────────────
def main():
    print(f"\n{'#'*60}")
    print(f"  RSL Index-Composition-Check — {TODAY}")
    print(f"  Server: {socket.gethostname()}")
    print(f"{'#'*60}")

    report_parts = []
    has_changes  = False

    # S&P 500
    print("\n[1/3] S&P 500 von Wikipedia laden...", flush=True)
    try:
        wiki_sp500 = fetch_sp500_wikipedia()
        db_sp500   = get_db_tickers('sp500')
        diff, changed = make_diff(wiki_sp500, db_sp500, 'sp500', 'S&P 500')
        report_parts.append(diff)
        if changed: has_changes = True
        print(f"      Wikipedia: {len(wiki_sp500)}, DB: {len(db_sp500)}")
    except Exception as e:
        msg = f"\nFEHLER bei S&P 500: {e}"
        report_parts.append(msg)
        print(msg)

    # DAX 40
    print("\n[2/3] DAX 40 von Wikipedia laden...", flush=True)
    try:
        wiki_dax = fetch_dax_wikipedia()
        db_dax   = get_db_tickers('dax')
        diff, changed = make_diff(wiki_dax, db_dax, 'dax', 'DAX 40')
        report_parts.append(diff)
        if changed: has_changes = True
        print(f"      Wikipedia: {len(wiki_dax)}, DB: {len(db_dax)}")
    except Exception as e:
        msg = f"\nFEHLER bei DAX: {e}"
        report_parts.append(msg)
        print(msg)

    # MDAX (universe='hdax' in unserer DB) — Namen-basierter Vergleich
    print("\n[3/3] MDAX von Wikipedia laden (Namen-Vergleich)...", flush=True)
    try:
        wiki_mdax_names = fetch_mdax_wikipedia_names()
        db_mdax_map     = get_db_mdax_names()
        diff, changed   = make_mdax_diff(wiki_mdax_names, db_mdax_map)
        report_parts.append(diff)
        if changed: has_changes = True
        print(f"      Wikipedia: {len(wiki_mdax_names)} Namen, DB: {len(db_mdax_map)} Ticker")
    except Exception as e:
        msg = f"\nFEHLER bei MDAX: {e}"
        report_parts.append(msg)
        print(msg)

    # Gesamt-Report
    full_report = '\n'.join(report_parts)
    print(full_report)

    footer = f"""
{'='*60}
  HINWEISE:
  • SQL-Statements oben immer manuell prüfen bevor ausführen!
  • Nach DB-Update: python3 scripts/07_download_yfinance.py (neue Ticker)
  • Danach: php scripts/03_calculate_rsl.php (historische RSL neu)
  • Delisted-Ticker: universe='delisted' setzen statt löschen
    (für Backtest-Korrektheit historische Daten behalten)
  • Nächster Check: quartalsweise (März/Juni/Sept/Dez)
{'='*60}
"""
    print(footer)

    # E-Mail senden wenn Änderungen vorhanden oder --send-mail flag
    if SEND_MAIL and (has_changes or '--force-mail' in sys.argv):
        subject = f"[RSL] Index-Änderungen erkannt — {TODAY}" if has_changes \
                  else f"[RSL] Index-Check OK — {TODAY}"
        body = f"RSL Index-Composition-Check {TODAY}\n{full_report}\n{footer}"
        try:
            msg = MIMEText(body, 'plain', 'utf-8')
            msg['Subject'] = subject
            msg['From']    = MAIL_FROM
            msg['To']      = MAIL_TO
            with smtplib.SMTP('localhost', timeout=10) as s:
                s.sendmail(MAIL_FROM, [MAIL_TO], msg.as_string())
            print(f"✓ E-Mail gesendet an {MAIL_TO}")
        except Exception as e:
            print(f"⚠ E-Mail fehlgeschlagen: {e}")
    elif has_changes:
        print(f"⚠ Änderungen gefunden — mit --send-mail Flag E-Mail senden.")

    return 0 if not has_changes else 1

if __name__ == '__main__':
    sys.exit(main())
