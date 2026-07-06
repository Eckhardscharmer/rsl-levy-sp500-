#!/bin/bash
# RSL nach Levy — Wöchentliches Update
# Läuft jeden Samstag (Freitagskurse vollständig verfügbar)
# Cron: 0 6 * * 6 (Samstags 06:00 UTC = 08:00 MESZ)
# Ziel: max. 10 Minuten Laufzeit — nur inkrementelle Updates, keine Neuberechnungen

cd /var/www/rsl

LOG=/tmp/rsl_weekly_$(date +%Y%m%d).log
echo "=== RSL Weekly Update: $(date) ===" | tee $LOG

# ── 1. Preise aktualisieren (Aktien + Benchmark-Ticker SPY/^GDAXI/ACWI/EURUSD=X) ─
echo "[1/3] Download: S&P 500 + DAX + Benchmark-Preise..." | tee -a $LOG
python3 scripts/07_download_yfinance.py --update >> $LOG 2>&1
echo "Download fertig: $(date)" | tee -a $LOG

# ── 2. ETF-Preise aktualisieren ──────────────────────────────────────────────
echo "[2/3] Download: ETF-Preise..." | tee -a $LOG
python3 scripts/10_download_etf_prices.py >> $LOG 2>&1
echo "ETF-Preise fertig: $(date)" | tee -a $LOG

# ── 3. RSL berechnen (nur neuester Freitag) + Backtest (nur neue Wochen) ──────
echo "[3/3] RSL-Berechnung + Backtest..." | tee -a $LOG
php scripts/03_calculate_rsl.php --latest >> $LOG 2>&1
php scripts/03_calculate_rsl.php --latest --universe=dax >> $LOG 2>&1
echo "RSL fertig: $(date)" | tee -a $LOG
php scripts/04_run_backtest.php --latest >> $LOG 2>&1
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
