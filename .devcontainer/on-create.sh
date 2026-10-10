#!/usr/bin/env bash
# Une seule fois, à la création du Codespace : fichier .env (jamais versionné) avec une clé d'application, droits sur storage.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

echo "==> Préparation de l'espace de travail"

# Le dépôt appartient à un autre utilisateur que celui du conteneur : Git doit l'accepter.
git config --global --add safe.directory "$PWD"

# .env à partir de .env.example (valeurs de développement jetables). Ne remplace jamais un .env existant.
if [ ! -f .env ]; then
    cp .env.example .env
    echo "    .env créé depuis .env.example"
fi

# Clé d'application : générée ici (avant « composer install », dont les scripts démarrent l'application).
if ! grep -qE '^APP_KEY=.+' .env; then
    key="base64:$(openssl rand -base64 32)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" .env
    echo "    APP_KEY générée"
fi

mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/private storage/app/public bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache
