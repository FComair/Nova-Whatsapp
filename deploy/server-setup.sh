#!/usr/bin/env bash
# =============================================================================
# Rendivoo - bootstrap d'un VPS Contabo (Ubuntu 22.04 / 24.04)
# Installe : Nginx, PHP 8.3-FPM, Redis, Supervisor (Horizon), Certbot, pare-feu,
#            fail2ban, mises à jour auto, swap, utilisateurs admin + deploy.
#
# Usage (en root, une seule fois, sur le serveur neuf) :
#   ADMIN_PUBKEY="ssh-ed25519 AAAA... vous@pc" \
#   DEPLOY_PUBKEY="ssh-ed25519 AAAA... github-actions" \
#   bash server-setup.sh
#
# ADMIN_PUBKEY  = votre clé personnelle (accès complet, sudo)
# DEPLOY_PUBKEY = clé DÉDIÉE à GitHub Actions (droits limités)
# =============================================================================
set -euo pipefail

DOMAIN="${DOMAIN:-rendivoo.be}"
APP_HOSTS="app.${DOMAIN} api.${DOMAIN}"
ADMIN_USER="${ADMIN_USER:-admin}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
APP_DIR="/var/www/rendivoo"
PHP_V="8.3"

: "${ADMIN_PUBKEY:?Définissez ADMIN_PUBKEY (votre clé publique SSH)}"
: "${DEPLOY_PUBKEY:?Définissez DEPLOY_PUBKEY (clé publique dédiée à GitHub Actions)}"
[ "$(id -u)" -eq 0 ] || { echo "Lancez ce script en root."; exit 1; }
export DEBIAN_FRONTEND=noninteractive

echo "==> 1/9 Mise à jour système"
apt-get update -y
apt-get upgrade -y
apt-get install -y software-properties-common ca-certificates curl git unzip rsync \
  ufw fail2ban unattended-upgrades nginx redis-server supervisor certbot python3-certbot-nginx

echo "==> 2/9 PHP ${PHP_V} + Composer"
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-pgsql" "php${PHP_V}-mbstring" \
  "php${PHP_V}-xml" "php${PHP_V}-curl" "php${PHP_V}-zip" "php${PHP_V}-bcmath" "php${PHP_V}-intl" \
  "php${PHP_V}-redis" "php${PHP_V}-gd" "php${PHP_V}-opcache"
curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo "==> 3/9 Utilisateurs et clés SSH"
make_user () {  # $1=user  $2=pubkey
  id "$1" &>/dev/null || adduser --disabled-password --gecos "" "$1"
  install -d -m 700 -o "$1" -g "$1" "/home/$1/.ssh"
  echo "$2" > "/home/$1/.ssh/authorized_keys"
  chown "$1:$1" "/home/$1/.ssh/authorized_keys"; chmod 600 "/home/$1/.ssh/authorized_keys"
}
make_user "$ADMIN_USER"  "$ADMIN_PUBKEY"
make_user "$DEPLOY_USER" "$DEPLOY_PUBKEY"
echo "${ADMIN_USER} ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-${ADMIN_USER}"
chmod 440 "/etc/sudoers.d/90-${ADMIN_USER}"
# L'utilisateur de déploiement ne peut faire QUE ces commandes en root :
cat > /etc/sudoers.d/91-rendivoo-deploy <<EOF
${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_V}-fpm, /usr/bin/supervisorctl start rendivoo-horizon, /usr/bin/supervisorctl restart rendivoo-horizon, /usr/bin/supervisorctl status
EOF
chmod 440 /etc/sudoers.d/91-rendivoo-deploy
visudo -cf /etc/sudoers.d/91-rendivoo-deploy

echo "==> 4/9 Durcissement SSH, pare-feu, fail2ban, swap"
mkdir -p /etc/ssh/sshd_config.d
cat > /etc/ssh/sshd_config.d/99-rendivoo.conf <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
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

echo "==> 5/9 PHP-FPM"
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

echo "==> 6/9 Arborescence applicative"
mkdir -p "${APP_DIR}/releases" \
  "${APP_DIR}/shared/storage/app/public" \
  "${APP_DIR}/shared/storage/framework/cache/data" \
  "${APP_DIR}/shared/storage/framework/sessions" \
  "${APP_DIR}/shared/storage/framework/views" \
  "${APP_DIR}/shared/storage/logs"
[ -f "${APP_DIR}/shared/.env" ] || { touch "${APP_DIR}/shared/.env"; chmod 640 "${APP_DIR}/shared/.env"; }
chown -R "${DEPLOY_USER}:www-data" "${APP_DIR}"

echo "==> 7/9 Nginx"
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

echo "==> 8/9 Redis, Horizon (Supervisor) et scheduler (cron)"
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

echo "==> 9/9 Redémarrage SSH (durcissement)"
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
 3. Renseignez le fichier d'environnement de production :
      sudo -u ${DEPLOY_USER} nano ${APP_DIR}/shared/.env
 4. Secrets GitHub (Settings > Secrets and variables > Actions) :
      DEPLOY_HOST, DEPLOY_USER, DEPLOY_SSH_KEY (clé PRIVÉE), DEPLOY_KNOWN_HOSTS
 5. Poussez sur main : le workflow déploie automatiquement.
============================================================
DONE
