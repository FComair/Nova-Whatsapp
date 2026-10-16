#!/usr/bin/env bash
# =============================================================================
# Rendivoo - bootstrap d'un VPS Contabo (Ubuntu 22.04 / 24.04)
# Installe : Nginx, PHP 8.3-FPM, PostgreSQL 16, Redis, Supervisor (Horizon), Certbot,
#            pare-feu, fail2ban, mises à jour auto, swap, sauvegardes, utilisateurs admin + deploy.
#
# Usage (en root, une seule fois, sur le serveur neuf) :
#   ADMIN_PUBKEY="ssh-ed25519 AAAA... vous@pc" \
#   DEPLOY_PASSWORD='mot de passe long et unique' \
#   bash server-setup.sh
#
# ADMIN_PUBKEY    = votre clé personnelle (accès complet, sudo). Connexion par clé uniquement.
# DEPLOY_PASSWORD = mot de passe de l'utilisateur de déploiement (GitHub Actions, secret SSH_PASSWORD).
#                   20 caractères minimum. Saisi ici uniquement : jamais écrit dans un fichier.
# DEPLOY_PUBKEY   = facultatif : clé publique supplémentaire pour l'utilisateur de déploiement.
# =============================================================================
set -euo pipefail

DOMAIN="${DOMAIN:-rendivoo.be}"
APP_HOSTS="app.${DOMAIN} api.${DOMAIN}"
ADMIN_USER="${ADMIN_USER:-admin}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
APP_DIR="/var/www/rendivoo"
PHP_V="8.3"
PG_V="16"
DB_NAME="nova"
BACKUP_DIR="/var/backups/rendivoo"

: "${ADMIN_PUBKEY:?Définissez ADMIN_PUBKEY (votre clé publique SSH)}"
: "${DEPLOY_PASSWORD:?Définissez DEPLOY_PASSWORD (mot de passe SSH de GitHub Actions)}"
[ "${#DEPLOY_PASSWORD}" -ge 20 ] || { echo "DEPLOY_PASSWORD : 20 caractères minimum."; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo "Lancez ce script en root."; exit 1; }
export DEBIAN_FRONTEND=noninteractive

echo "==> 1/11 Mise à jour système"
apt-get update -y
apt-get upgrade -y
apt-get install -y software-properties-common ca-certificates curl git unzip rsync \
  ufw fail2ban unattended-upgrades nginx redis-server supervisor certbot python3-certbot-nginx

echo "==> 2/11 PHP ${PHP_V} + Composer"
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-pgsql" "php${PHP_V}-mbstring" \
  "php${PHP_V}-xml" "php${PHP_V}-curl" "php${PHP_V}-zip" "php${PHP_V}-bcmath" "php${PHP_V}-intl" \
  "php${PHP_V}-redis" "php${PHP_V}-gd" "php${PHP_V}-opcache"
curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo "==> 3/11 PostgreSQL ${PG_V} (dépôt officiel PGDG, écoute locale uniquement)"
install -d /usr/share/postgresql-common/pgdg
curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
. /etc/os-release
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" \
  > /etc/apt/sources.list.d/pgdg.list
apt-get update -y
apt-get install -y "postgresql-${PG_V}"
systemctl enable --now postgresql

echo "==> 4/11 Utilisateurs et clés SSH"
make_user () {  # $1=user  $2=pubkey (facultative)
  if ! id "$1" &>/dev/null; then
    # Certaines images Ubuntu (dont Contabo) ont déjà un groupe système du même nom (ex. « admin ») : on le réutilise.
    if getent group "$1" >/dev/null; then
      adduser --disabled-password --gecos "" --ingroup "$1" "$1"
    else
      adduser --disabled-password --gecos "" "$1"
    fi
  fi
  install -d -m 700 -o "$1" -g "$1" "/home/$1/.ssh"
  [ -n "$2" ] && echo "$2" > "/home/$1/.ssh/authorized_keys"
  touch "/home/$1/.ssh/authorized_keys"
  chown "$1:$1" "/home/$1/.ssh/authorized_keys"; chmod 600 "/home/$1/.ssh/authorized_keys"
}
make_user "$ADMIN_USER"  "$ADMIN_PUBKEY"
make_user "$DEPLOY_USER" "${DEPLOY_PUBKEY:-}"
# GitHub Actions se connecte par mot de passe (secrets SSH_USERNAME / SSH_PASSWORD), comme le déploiement Rushforless.
# L'administrateur n'a PAS de mot de passe (compte créé avec --disabled-password) : il ne peut entrer que par sa clé.
echo "${DEPLOY_USER}:${DEPLOY_PASSWORD}" | chpasswd
echo "${ADMIN_USER} ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-${ADMIN_USER}"
chmod 440 "/etc/sudoers.d/90-${ADMIN_USER}"
# L'utilisateur de déploiement ne peut faire QUE ces commandes en root :
cat > /etc/sudoers.d/91-rendivoo-deploy <<EOF
${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_V}-fpm, /usr/bin/supervisorctl start rendivoo-horizon, /usr/bin/supervisorctl restart rendivoo-horizon, /usr/bin/supervisorctl status
EOF
chmod 440 /etc/sudoers.d/91-rendivoo-deploy
visudo -cf /etc/sudoers.d/91-rendivoo-deploy

echo "==> 5/11 Durcissement SSH, pare-feu, fail2ban, swap"
mkdir -p /etc/ssh/sshd_config.d
# Préfixe 01 : sshd retient la PREMIÈRE valeur lue, et certaines images (cloud-init) déposent un 50-*.conf.
rm -f /etc/ssh/sshd_config.d/99-rendivoo.conf
cat > /etc/ssh/sshd_config.d/01-rendivoo.conf <<EOF
PermitRootLogin no
# Mot de passe accepté pour le déploiement GitHub Actions ; l'administrateur n'a pas de mot de passe (clé seule).
PasswordAuthentication yes
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AllowUsers ${ADMIN_USER} ${DEPLOY_USER}
MaxAuthTries 3
EOF
sshd -t
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
systemctl enable --now fail2ban
dpkg-reconfigure -f noninteractive unattended-upgrades
if ! swapon --show | grep -q .; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf && sysctl -p /etc/sysctl.d/99-swappiness.conf
fi

echo "==> 6/11 PHP-FPM"
cat > "/etc/php/${PHP_V}/fpm/conf.d/99-rendivoo.ini" <<'EOF'
expose_php = Off
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 12M
max_execution_time = 60
opcache.enable = 1
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
; Code immuable par release : le déploiement recharge php-fpm.
opcache.validate_timestamps = 0
EOF
POOL="/etc/php/${PHP_V}/fpm/pool.d/www.conf"
sed -i "s/^user = .*/user = ${DEPLOY_USER}/; s/^group = .*/group = www-data/" "$POOL"
sed -i "s/^listen.owner = .*/listen.owner = www-data/; s/^listen.group = .*/listen.group = www-data/" "$POOL"
sed -i "s/^pm.max_children = .*/pm.max_children = 20/" "$POOL"
systemctl enable --now "php${PHP_V}-fpm"
systemctl restart "php${PHP_V}-fpm"

echo "==> 7/11 Arborescence applicative, base de données et .env de production"
mkdir -p "${APP_DIR}/releases" "${APP_DIR}/artifacts" \
  "${APP_DIR}/shared/storage/app/public" \
  "${APP_DIR}/shared/storage/framework/cache/data" \
  "${APP_DIR}/shared/storage/framework/sessions" \
  "${APP_DIR}/shared/storage/framework/views" \
  "${APP_DIR}/shared/storage/logs"
ENV_FILE="${APP_DIR}/shared/.env"
[ -f "$ENV_FILE" ] || touch "$ENV_FILE"

# Base et rôles : mêmes rôles que docker/postgres/init/01-roles-and-extensions.sql (RLS, docs/01 §0), mais mots de passe
# aléatoires et propriétaire NON superutilisateur. Les extensions sont créées ici par le superutilisateur « postgres » :
# les migrations (CREATE EXTENSION IF NOT EXISTS) n'ont alors plus rien à faire. Exécuté une seule fois (.env vide).
# BYPASSRLS pour le propriétaire : les tables sont en FORCE ROW LEVEL SECURITY, et les fonctions SECURITY DEFINER qu'il possède
# (auth_memberships, whatsapp_account_lookup…) doivent lire sans contexte d'organisation. En développement, il est superutilisateur.
# Sans ce droit, ces fonctions ne renvoient rien : connexion impossible (« Identifiants incorrects »), constaté le 2026-10-16.
if ! grep -q '^DB_OWNER_PASSWORD=' "$ENV_FILE"; then
  OWNER_PW="$(openssl rand -hex 24)"; APP_PW="$(openssl rand -hex 24)"; PLATFORM_PW="$(openssl rand -hex 24)"
  sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
CREATE ROLE nova_owner    LOGIN PASSWORD '${OWNER_PW}'    NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;
CREATE ROLE nova_app      LOGIN PASSWORD '${APP_PW}'      NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE nova_platform LOGIN PASSWORD '${PLATFORM_PW}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE DATABASE ${DB_NAME} OWNER nova_owner;
\connect ${DB_NAME}
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
ALTER SCHEMA public OWNER TO nova_owner;
REVOKE ALL ON DATABASE ${DB_NAME} FROM PUBLIC;
GRANT CONNECT ON DATABASE ${DB_NAME} TO nova_owner, nova_app, nova_platform;
GRANT USAGE ON SCHEMA public TO nova_app, nova_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE nova_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO nova_app;
ALTER DEFAULT PRIVILEGES FOR ROLE nova_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO nova_app;
SQL

  # Socle du .env de production. Le reste (e-mail SMTP, WhatsApp, Meta, IA, facturation…) se complète à la main
  # à partir de .env.example : sudo -u deploy nano ${ENV_FILE}
  cat >> "$ENV_FILE" <<EOF
APP_NAME=Rendivoo
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://app.${DOMAIN}
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=${DB_NAME}
DB_USERNAME=nova_app
DB_PASSWORD=${APP_PW}
DB_OWNER_USERNAME=nova_owner
DB_OWNER_PASSWORD=${OWNER_PW}
DB_PLATFORM_USERNAME=nova_platform
DB_PLATFORM_PASSWORD=${PLATFORM_PW}

SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

WHATSAPP_DRIVER=meta

# --- À compléter (voir .env.example) : MAIL_*, WHATSAPP_*, META_*, CONVERSATION_*, BILLING_*, NOVA_FOUNDER_EMAIL ---
EOF
  unset OWNER_PW APP_PW PLATFORM_PW
fi
chmod 640 "$ENV_FILE"
chown -R "${DEPLOY_USER}:www-data" "${APP_DIR}"

echo "==> 8/11 Nginx"
cat > /etc/nginx/sites-available/rendivoo <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name __HOSTS__;

    root __APP__/current/public;
    index index.php;
    charset utf-8;
    client_max_body_size 12M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php__PHPV__-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 60s;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
sed -i "s|__HOSTS__|${APP_HOSTS}|; s|__APP__|${APP_DIR}|; s|__PHPV__|${PHP_V}|" /etc/nginx/sites-available/rendivoo
ln -sf /etc/nginx/sites-available/rendivoo /etc/nginx/sites-enabled/rendivoo
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx
systemctl reload nginx

echo "==> 9/11 Redis, Horizon (Supervisor) et scheduler (cron)"
systemctl enable --now redis-server
cat > /etc/supervisor/conf.d/rendivoo-horizon.conf <<EOF
[program:rendivoo-horizon]
process_name=%(program_name)s
command=php ${APP_DIR}/current/artisan horizon
directory=${APP_DIR}/current
user=${DEPLOY_USER}
autostart=true
autorestart=true
startretries=10
redirect_stderr=true
stdout_logfile=${APP_DIR}/shared/storage/logs/horizon.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=3
stopwaitsecs=3600
EOF
cat > /etc/cron.d/rendivoo <<EOF
* * * * * ${DEPLOY_USER} cd ${APP_DIR}/current && php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/rendivoo
systemctl enable --now supervisor
supervisorctl reread && supervisorctl update

echo "==> 10/11 Sauvegarde quotidienne de la base (03:30, 14 jours conservés sur ce serveur)"
install -d -m 700 -o postgres -g postgres "$BACKUP_DIR"
cat > /etc/cron.d/rendivoo-backup <<EOF
30 3 * * * postgres pg_dump -Fc ${DB_NAME} > ${BACKUP_DIR}/${DB_NAME}-\$(date +\%F).dump && find ${BACKUP_DIR} -name '${DB_NAME}-*.dump' -mtime +14 -delete
EOF
chmod 644 /etc/cron.d/rendivoo-backup

echo "==> 11/11 Redémarrage SSH (durcissement)"
systemctl reload ssh || systemctl reload sshd

cat <<DONE

============================================================
 Serveur prêt. NE FERMEZ PAS cette session avant d'avoir testé,
 dans un AUTRE terminal :   ssh ${ADMIN_USER}@<IP-du-serveur>

 Étapes suivantes :
 1. DNS (chez votre registrar) : enregistrements A
      app.${DOMAIN}  ->  <IP du VPS>
      api.${DOMAIN}  ->  <IP du VPS>
 2. Une fois le DNS propagé, HTTPS :
      sudo certbot --nginx -d app.${DOMAIN} -d api.${DOMAIN} --redirect \\
           -m VOTRE@EMAIL --agree-tos --no-eff-email
 3. Complétez le fichier d'environnement de production (base, clé et Redis déjà remplis) :
      sudo -u ${DEPLOY_USER} nano ${APP_DIR}/shared/.env
 4. Secrets GitHub (Settings > Secrets and variables > Actions), comme pour Rushforless :
      SSH_HOST (IP du VPS), SSH_PORT (22), SSH_USERNAME (${DEPLOY_USER}), SSH_PASSWORD (DEPLOY_PASSWORD)
 5. Poussez sur main : le workflow déploie automatiquement.
 Sauvegardes de la base : ${BACKUP_DIR} (copiez-les aussi HORS de ce serveur).
============================================================
DONE
