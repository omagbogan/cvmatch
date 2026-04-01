#!/bin/bash
set -e

# Désactiver tous les MPM avant le démarrage d'Apache
rm -f /etc/apache2/mods-enabled/mpm*.load /etc/apache2/mods-enabled/mpm*.conf
if command -v a2dismod >/dev/null 2>&1; then
  a2dismod mpm_event mpm_worker || true
  a2enmod mpm_prefork || true
fi

exec apache2-foreground
