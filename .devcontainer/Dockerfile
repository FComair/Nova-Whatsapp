# Image de développement : mêmes extensions PHP que docker/php/Dockerfile (PostgreSQL, Redis, intl, zip, bcmath, gd, pcntl, opcache, mbstring),
# plus le client PostgreSQL (psql) et Xdebug (désactivé par défaut : XDEBUG_MODE=off). Node 24, Git et GitHub CLI viennent des « features »
# de devcontainer.json. À garder alignée sur docker/php/Dockerfile quand les extensions changent.
FROM php:8.4-cli

# install-php-extensions (projet mlocati) : installe les bibliothèques système et compile chaque extension, y compris redis et xdebug, sans
# dépendre du serveur PECL. Version figée pour une construction reproductible.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.34/install-php-extensions /usr/local/bin/install-php-extensions

RUN apt-get update && apt-get install -y --no-install-recommends git unzip curl ca-certificates sudo openssl postgresql-client \
    && install-php-extensions pdo_pgsql pgsql intl zip bcmath gd pcntl opcache mbstring redis xdebug \
    && rm -rf /var/lib/apt/lists/*

# Xdebug : inactif tant que XDEBUG_MODE n'est pas « debug » ; le client écoute sur le port 9003 du poste (voir .devcontainer/README.md).
RUN { \
        echo 'xdebug.client_host=127.0.0.1'; \
        echo 'xdebug.client_port=9003'; \
        echo 'xdebug.start_with_request=trigger'; \
    } > /usr/local/etc/php/conf.d/zz-xdebug-dev.ini \
    && { echo 'memory_limit=1G'; echo 'opcache.validate_timestamps=1'; echo 'opcache.revalidate_freq=0'; } > /usr/local/etc/php/conf.d/zz-nova-dev.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /workspaces
