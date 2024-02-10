FROM dunglas/frankenphp

WORKDIR /app

RUN set -eux; \
	install-php-extensions \
		opcache \
		pdo pdo_pgsql \
	;

ENV APP_ENV=prod
ENV SERVER_NAME=:80
ENV FRANKENPHP_CONFIG="worker ./public/index.php"

RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

COPY . .