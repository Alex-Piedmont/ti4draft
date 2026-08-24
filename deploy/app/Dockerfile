FROM caddy:2.11-builder AS caddy-builder

RUN xcaddy build \
    --with github.com/baldinof/caddy-supervisor

FROM php:8.2-fpm-alpine AS base

COPY --from=caddy-builder /usr/bin/caddy /usr/bin/caddy
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY deploy/app/php/php.ini /usr/local/etc/php/conf.d/php.ini
COPY deploy/app/caddy/Caddyfile /etc/caddy/Caddyfile
COPY deploy/app/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chmod=755 deploy/app/entrypoint.sh /usr/local/bin/ti4draft-entrypoint

RUN caddy validate --config=/etc/caddy/Caddyfile

WORKDIR /code

RUN addgroup -g 1000 -S app \
  && adduser -u 1000 -S app -G app \
  && chown app:app /code

RUN apk --update --no-cache add \
    supervisor \
    su-exec \
    curl \
    curl-dev \
    libpng-dev \
    zlib

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/ti4draft-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

FROM base AS development

FROM base AS production

COPY --chown=app:app . /code

RUN su-exec app:app composer install --no-dev --optimize-autoloader
