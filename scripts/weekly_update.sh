#!/bin/bash
cd /var/www/rsl
python3 scripts/07_download_yfinance.py >> /tmp/download.log 2>&1
php scripts/03_calculate_rsl.php >> /tmp/rsl.log 2>&1
php scripts/04_run_backtest.php >> /tmp/backtest.log 2>&1
