#!/bin/bash
set -e

# Start Python service in background
cd /var/www/html/python-service
python3 app.py &
PY_PID=$!

# Désactiver tous les MPM avant le démarrage d'Apache
rm -f /etc/apache2/mods-enabled/mpm*.load /etc/apache2/mods-enabled/mpm*.conf
if command -v a2dismod >/dev/null 2>&1; then
  a2dismod mpm_event mpm_worker || true
  a2enmod mpm_prefork || true
fi

# Forward signals to Python process and Apache
trap "kill -TERM $PY_PID 2>/dev/null || true" SIGTERM SIGINT

exec apache2-foreground
