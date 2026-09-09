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

echo "Reconstruindo e subindo (volumes preservados — a sessão pareada não se perde)..."
docker compose pull || true
docker compose up -d --build

echo "Pronto. docker compose ps:"
docker compose ps
