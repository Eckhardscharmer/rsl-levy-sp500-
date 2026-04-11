#!/bin/bash
# RSL Setup Script fuer Hetzner Ubuntu 24.04

# SSH Root Login erlauben
sed -i 's/PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config
systemctl restart sshd
echo "[OK] SSH konfiguriert"

# Apache konfigurieren
ln -sf /var/www/rsl/public /var/www/html/rsl
systemctl restart apache2
echo "[OK] Apache konfiguriert"

# Kursdaten herunterladen
nohup python3 /var/www/rsl/scripts/07_download_yfinance.py > /tmp/download.log 2>&1 &
echo "[OK] Download gestartet (PID: $!)"
echo "Fortschritt: tail -f /tmp/download.log"
