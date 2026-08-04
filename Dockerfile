# Dependabot only reads FROM instructions, so images copied from are declared as stages.
FROM composer:2.9.8 AS composer_upstream

FROM ghcr.io/mlocati/php-extension-installer:2.11.1 AS php_extension_installer_upstream

FROM php:8.5-cli-alpine3.22 AS php_upstream
LABEL authors="nicolas-codemate"

COPY --from=composer_upstream /usr/bin/composer /usr/bin/composer

COPY --from=php_extension_installer_upstream /usr/bin/install-php-extensions /usr/local/bin/


FROM php_upstream AS php_base

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

RUN set -eux; \
	apk add --no-cache \
		file \
		git \
	; \
	install-php-extensions \
		apcu \
		intl \
		opcache \
		zip \
	;


# dev image
FROM php_base AS php_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
	install-php-extensions \
		xdebug \
	;

ENTRYPOINT ["sleep", "infinity"]


# prod image
FROM php_base AS php_prod

ENV APP_ENV=prod

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link . ./

RUN set -eux; \
	mkdir -p var/cache var/log var/share; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer dump-env prod; \
	composer run-script --no-dev post-install-cmd; \
	chmod +x bin/console; sync;

# Keep the start period well under the hourly process recycle, which puts the health state back to starting.
HEALTHCHECK --interval=5m --timeout=10s --start-period=30s --retries=2 \
	CMD ["php", "bin/console", "app:health"]

CMD ["php", "bin/console", "messenger:consume", "scheduler_default", "--time-limit=3600"]
