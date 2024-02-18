FROM dunglas/frankenphp

ARG HTTP_PORT
WORKDIR /app

RUN install-php-extensions pdo_pgsql;

ENV APP_ENV=prod
ENV SERVER_NAME=:${HTTP_PORT}
ENV FRANKENPHP_CONFIG="worker ./public/index.php"

RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

COPY . .