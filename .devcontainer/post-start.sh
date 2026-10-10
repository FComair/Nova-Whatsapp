#!/usr/bin/env bash
# À chaque démarrage du Codespace : adresses publiques du Codespace dans .env, migrations en attente, démarrage de l'application.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
source .devcontainer/lib.sh

[ -f .env ] || bash .devcontainer/on-create.sh

# Adresses : dans un Codespace, le navigateur joint l'application par https://<nom>-<port>.<domaine>, jamais par localhost.
# Les valeurs ci-dessous ne concernent que ce Codespace et ne sont écrites que dans .env (jamais versionné).
if [ -n "${CODESPACE_NAME:-}" ]; then
    domain="${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
    app_url="https://${CODESPACE_NAME}-8000.${domain}"
    vite_host="${CODESPACE_NAME}-5173.${domain}"

    set_env APP_URL "$app_url"
    set_env SESSION_SECURE_COOKIE true
    set_env TRUSTED_PROXIES '*'
    set_env VITE_DEV_SERVER_ORIGIN "https://${vite_host}"
    set_env VITE_CORS_ORIGIN "$app_url"
    set_env VITE_HMR_HOST "$vite_host"
    set_env VITE_HMR_PROTOCOL wss
    set_env VITE_HMR_CLIENT_PORT 443
else
    # Dev Container local (VS Code) : tout passe par localhost.
    set_env APP_URL "http://localhost:8000"
fi

# Parcours de développement : pas de double authentification obligatoire (activable depuis Réglages > Sécurité), codes téléphone dans le journal.
set_env NOVA_REQUIRE_2FA false
set_env NOVA_ADMIN_REQUIRE_MFA false
set_env WHATSAPP_DRIVER fake
set_env BILLING_GATEWAY fake

wait_for_database
php artisan config:clear --quiet
php artisan migrate --database=pgsql_owner --force --quiet

if [ "${NOVA_AUTOSTART:-1}" = "1" ]; then
    bash .devcontainer/dev.sh start
fi
