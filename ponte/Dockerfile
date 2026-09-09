FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        sqlite3 libsqlite3-dev curl ca-certificates \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
RUN mkdir -p /app/data && chmod 700 /app/data \
    && chmod +x /app/entrypoint.sh

ENV APP_PORT=8090
EXPOSE 8090

ENTRYPOINT ["/app/entrypoint.sh"]
