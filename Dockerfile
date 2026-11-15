# The PHP-led site needs no frontend build. Install only the API workspace.
FROM node:22-bookworm-slim AS node-runtime
WORKDIR /opt/upon-a-star
COPY package.json package-lock.json ./
COPY server/package.json ./server/package.json
COPY client/package.json ./client/package.json
RUN npm ci --omit=dev --workspace server --include-workspace-root --ignore-scripts \
    && npm cache clean --force

FROM php:8.3-apache-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl libcurl4-openssl-dev libonig-dev libstdc++6 \
    && for extension in pdo_mysql mbstring curl; do \
        if ! php -r "exit(extension_loaded('$extension') ? 0 : 1);"; then \
            docker-php-ext-install -j2 "$extension" || exit 1; \
        fi; \
    done \
    && a2enmod rewrite proxy proxy_http headers setenvif \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node-runtime /usr/local/bin/node /usr/local/bin/node
COPY --from=node-runtime /opt/upon-a-star/node_modules /opt/upon-a-star/node_modules
COPY server /opt/upon-a-star/server
COPY deployment/start.sh deployment/init-database.mjs /opt/upon-a-star/deployment/
COPY database/submission/silver_screen.sql /opt/upon-a-star/database/submission/silver_screen.sql

# Only the PHP pages and their assets are under Apache's document root.
COPY index.php .htaccess /var/www/html/
COPY base /var/www/html/base
COPY php /var/www/html/php
COPY deployment/apache.conf /etc/apache2/sites-available/000-default.conf
RUN rm -rf /var/www/html/base/database \
    && chmod 755 /opt/upon-a-star/deployment/start.sh \
    && printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf \
    && printf 'memory_limit=64M\nexpose_php=Off\ndisplay_errors=Off\nlog_errors=On\nerror_log=/proc/self/fd/2\ndate.timezone=Asia/Singapore\n' \
        > /usr/local/etc/php/conf.d/upon-a-star.ini

ENV NODE_ENV=production \
    TZ=Asia/Singapore \
    PORT=10000 \
    MYSQL_SSL=true \
    MYSQL_SSL_CA=/etc/ssl/certs/ca-certificates.crt
WORKDIR /opt/upon-a-star/server
EXPOSE 10000
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD curl --fail --silent --max-time 4 "http://127.0.0.1:${PORT}/api/health" > /dev/null || exit 1
CMD ["/opt/upon-a-star/deployment/start.sh"]
