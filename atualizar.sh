#!/usr/bin/env bash
# atualizar.sh — git pull + rebuild + up -d, preservando .env e os volumes
# (sessão do WhatsApp e configuração da ponte). Recusa rodar com alteração
# local não commitada, pra não descartar customização em silêncio.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
    echo "ERRO: há alteração local não commitada. Commite, descarte ou stash antes de atualizar."
    git status --short
    exit 1
fi

echo "Buscando atualização..."
git pull --ff-only

echo "Reconstruindo as imagens (volumes preservados — a sessão pareada não se perde)..."
# buildx direto, não `docker compose build` — ver a nota no instalar.sh sobre
# o caminho "bake" que falha em algumas versões do Docker Desktop.
docker buildx build 'https://github.com/asternic/wuzapi.git#919c72c9750b2a1eedf0fcf9c9592f05fe46f61c' -t ponte-whatsapp-motor:local
docker buildx build ./ponte -t ponte-whatsapp-app:local

echo "Subindo..."
docker compose up -d

echo "Pronto. docker compose ps:"
docker compose ps
