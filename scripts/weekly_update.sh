#!/bin/bash
# Wöchentliches RSL-Update (jeden Sonntag)
# Aufruf: bash scripts/weekly_update.sh
# Cron (jeden Sonntag 08:00): 0 8 * * 0 cd /Applications/XAMPP/htdocs/rsl && bash scripts/weekly_update.sh >> logs/weekly.log 2>&1

PHP=/Applications/XAMPP/xamppfiles/bin/php
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG="$ROOT/logs/weekly.log"

mkdir -p "$ROOT/logs"
echo ""
echo "=== RSL Wöchentliches Update: $(date '+%Y-%m-%d %H:%M') ==="

echo "[1/4] Kurshistorie aktualisieren (yfinance)..."
python3 "$ROOT/scripts/07_download_yfinance.py" --update

echo "[2/4] M&A-Check für Top-50 Kandidaten..."
python3 "$ROOT/scripts/09_check_ma_activity.py"

echo "[3/4] RSL berechnen (nur neueste Woche, M&A-geflaggte Aktien ausgeschlossen)..."
$PHP "$ROOT/scripts/03_calculate_rsl.php" --latest

echo "[4/4] Backtest aktualisieren..."
$PHP "$ROOT/scripts/04_run_backtest.php"

echo ""
echo "=== Update abgeschlossen: $(date '+%H:%M') ==="
echo "Dashboard:  http://localhost/rsl/public/index.php"
echo "Signal:     http://localhost/rsl/public/signal.php"
echo "Simulation: http://localhost/rsl/public/simulation.php"
