#!/bin/bash
set -e

# Start Python service in background if Python is available
if command -v python3 >/dev/null 2>&1; then
    cd /var/www/html/python-service
    python3 app.py &
    PY_PID=$!
else
    echo "Python not available, skipping Python service"
fi

# Désactiver tous les MPM avant le démarrage d'Apache
rm -f /etc/apache2/mods-enabled/mpm*.load /etc/apache2/mods-enabled/mpm*.conf
if command -v a2dismod >/dev/null 2>&1; then
  a2dismod mpm_event mpm_worker || true
  a2enmod mpm_prefork || true
fi

# Forward signals to Python process and Apache
if [ -n "$PY_PID" ]; then
    trap "kill -TERM $PY_PID 2>/dev/null || true" SIGTERM SIGINT
fi

exec apache2-foreground
