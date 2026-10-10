#!/usr/bin/env bash
# À la création et quand le contenu du dépôt change (aussi utilisé par les préconstructions Codespaces) : dépendances et assets.
# Idempotent et rapide si rien n'a changé : « composer install » et « npm ci » respectent les fichiers de verrouillage.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

echo "==> Dépendances PHP (composer.lock)"
composer install --no-interaction --prefer-dist --no-progress

echo "==> Dépendances JavaScript (package-lock.json)"
npm ci --no-audit --no-fund

echo "==> Assets (vite build) : l'application fonctionne même sans serveur Vite"
npm run build
