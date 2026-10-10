#!/usr/bin/env bash
# Fonctions communes aux scripts du conteneur de développement (à inclure avec « source »).

# set_env CLÉ VALEUR : remplace (ou ajoute) CLÉ=VALEUR dans .env, sans toucher aux autres lignes.
set_env() {
    local key="$1" value="$2"
    local escaped
    escaped="$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')"

    if grep -qE "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

# wait_for_database : attend que PostgreSQL réponde (au plus ~2 minutes), sinon échoue avec un message clair.
wait_for_database() {
    local host="${DB_HOST:-postgres}" tries=0
    until pg_isready -h "$host" -U nova_owner -d nova >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            echo "PostgreSQL ne répond pas sur ${host}:5432. Voir .devcontainer/README.md (dépannage)." >&2
            return 1
        fi
        sleep 2
    done
}
