# Rates Publisher — PHP + Apache image for devbox.
# No Composer: the .xlsx reader is built in and only needs PHP's zip extension
# (SimpleXML and mbstring are already part of the official PHP image).

# ---------------------------------------------------------------- base
FROM php:8.4-apache AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Production php.ini (display_errors off) plus the tool's upload limits
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-rates.ini"

# Apache: honour the app's .htaccess files, hide version details
COPY docker/apache.conf /etc/apache2/conf-available/rates.conf
RUN a2enconf rates

# ---------------------------------------------------------------- test
# Runs the test suite during the build: if a test fails, the build fails and
# the running container is left untouched.
FROM base AS test
WORKDIR /src
COPY . .
RUN php tests/run.php && touch /tmp/tests-passed

# ---------------------------------------------------------------- app
FROM base AS app
# Forces the test stage to run before this image can be built
COPY --from=test /tmp/tests-passed /tmp/tests-passed

# Only what the site needs (no docs, tests or git data)
COPY index.php .htaccess /var/www/html/
COPY includes/ /var/www/html/includes/
COPY assets/ /var/www/html/assets/

EXPOSE 80
