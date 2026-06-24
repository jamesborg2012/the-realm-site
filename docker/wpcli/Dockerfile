# Tooling image for the one-shot bootstrap: wp-cli (from the official image)
# plus Composer for installing third-party plugins/themes via WPackagist.
FROM wordpress:cli-php8.2

USER root
RUN apk add --no-cache bash git unzip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1

COPY bootstrap.sh search-replace.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/bootstrap.sh /usr/local/bin/search-replace.sh

# Default run executes the full bootstrap. Passing args (e.g. `wp ... plugin list`
# or `search-replace.sh`) execs them instead — see the dispatch in bootstrap.sh.
ENTRYPOINT ["bootstrap.sh"]
