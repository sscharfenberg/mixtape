# syntax=docker/dockerfile:1
#
# MixTape — local dev PHP-FPM image
# =================================
# A throwaway *development* image for working on the app away from home, where
# the real server (debbie) is only reachable over the LAN. This is NOT the
# production image — prod on debbie uses the git-deploy model
# (docs/self-hosting/03-production-deploy.md). Here the source is bind-mounted at
# runtime (see ../docker-compose.yml) so edits are live; this image only supplies
# the PHP runtime + tooling. Dependencies (vendor/) are reused from the bind mount
# or installed by the entrypoint on first boot — never baked in.
#
# PHP 8.4 is chosen to match debbie's php-fpm 8.4, not the 8.5 you run on the Mac.

FROM php:8.4-fpm-bookworm

# System libraries the PHP extensions below link against, plus git/unzip for
# composer and postgresql-client for the entrypoint's DB wait (pg_isready) and
# for restoring a dump you brought from home (psql).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip \
        libicu-dev libzip-dev libpq-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        postgresql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql intl zip bcmath exif gd opcache pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer 2 straight from its official image (no hand-bootstrapping).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dev opcache: revalidate every request so edits take effect without a restart —
# the opposite of the production tuning.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=100M'; \
        echo 'post_max_size=100M'; \
    } > /usr/local/etc/php/conf.d/zz-mixtape-dev.ini

WORKDIR /var/www

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
