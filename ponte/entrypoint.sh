#!/bin/sh
# ponte/entrypoint.sh — migra o banco, sobe o laço `puxar` em segundo plano e
# sobe o servidor web em primeiro plano (mantém o container vivo).
set -e

php /app/bin/migrar.php

php /app/bin/puxar_loop.php >> /app/data/puxar.log 2>&1 &

echo "Ponte escutando em 0.0.0.0:${APP_PORT}"
exec php -S 0.0.0.0:"${APP_PORT}" -t /app/public /app/public/index.php
