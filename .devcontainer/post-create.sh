#!/usr/bin/env bash
# Une seule fois, une fois les services démarrés : base de développement (migrations, données de démonstration).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
source .devcontainer/lib.sh

echo "==> Attente de PostgreSQL"
wait_for_database

echo "==> Migrations (rôle propriétaire de la base)"
php artisan migrate --database=pgsql_owner --force

echo "==> Données de démonstration (développement uniquement, idempotent)"
php artisan db:seed --class=DevelopmentSeeder --database=pgsql_owner --force

echo "==> Prêt. Lancez « composer dev » (ou attendez le démarrage automatique) puis ouvrez le port 8000."
