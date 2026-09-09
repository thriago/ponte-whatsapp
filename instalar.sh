#!/usr/bin/env bash
# instalar.sh — instala e sobe a ponte.
#
# Uso:  bash instalar.sh
#
# Deixa tudo pronto: ao terminar, só falta abrir a interface, ler o QR Code com
# o WhatsApp e informar o endereço e o token do sistema de atendimento.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

AVISO='Esta ferramenta usa uma API NAO-OFICIAL do WhatsApp (wuzapi, de terceiros).
O numero pareado pode ser BLOQUEADO A QUALQUER MOMENTO, SEM AVISO E SEM
RECURSO - inclusive nos primeiros dias de uso. Nao use um numero que voce
nao possa perder. Nao e homologada pelo WhatsApp, nao tem garantia e nao
tem suporte. Ao instalar, o risco e seu.'

linha() { printf '%s\n' '===================================================================='; }

linha
echo "  AVISO DE RISCO - leia antes de continuar"
linha
printf '%s\n' "$AVISO"
linha
echo
read -r -p 'Digite "aceito" para continuar (qualquer outra coisa cancela): ' resposta
if [ "$resposta" != "aceito" ]; then
    echo "Cancelado. Nada foi instalado."
    exit 1
fi
echo

# ── Docker ──────────────────────────────────────────────────────────────
if ! command -v docker >/dev/null 2>&1; then
    echo "ERRO: docker nao encontrado."
    echo
    echo "Instale o Docker e rode este script de novo:"
    echo "  Linux:       https://docs.docker.com/engine/install/"
    echo "  Windows/Mac: Docker Desktop"
    echo
    echo "Prefere instalar sem Docker? Veja INSTALAR-SEM-DOCKER.md"
    exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
    echo "ERRO: 'docker compose' nao respondeu. O Docker esta rodando?"
    exit 1
fi
if ! docker info >/dev/null 2>&1; then
    echo "ERRO: o servico do Docker nao responde. Inicie o Docker e tente de novo."
    exit 1
fi
echo "Docker OK: $(docker --version)"
echo

gerar_hex() { # $1 = bytes
    openssl rand -hex "$1" 2>/dev/null \
        || php -r 'echo bin2hex(random_bytes((int)$argv[1]));' "$1" 2>/dev/null \
        || head -c "$((2 * $1))" /dev/urandom | od -An -tx1 | tr -d ' \n'
}

# ── Senha do administrador ──────────────────────────────────────────────
# Perguntar, em vez de sortear e mandar a pessoa cacar no log, foi pedido
# explicito: quem instala escolhe a senha e ja entra com ela. Quem nao quiser
# escolher aperta Enter e recebe uma sorteada, mostrada no fim.
SENHA_ESCOLHIDA=""
if [ ! -f .env ]; then
    echo "Escolha a senha do administrador da interface."
    echo "(Enter em branco = eu sorteio uma e mostro no final.)"
    read -r -s -p "Senha (minimo 8 caracteres): " s1; echo
    if [ -n "$s1" ]; then
        if [ "${#s1}" -lt 8 ]; then
            echo "Senha muito curta. Vou sortear uma."
        else
            read -r -s -p "Repita a senha: " s2; echo
            if [ "$s1" != "$s2" ]; then
                echo "As senhas nao conferem. Vou sortear uma."
            else
                SENHA_ESCOLHIDA="$s1"
            fi
        fi
    fi
    [ -n "$SENHA_ESCOLHIDA" ] && echo "Senha definida." || echo "Vou sortear a senha."
    echo
fi

# ── .env ────────────────────────────────────────────────────────────────
if [ -f .env ]; then
    echo ".env ja existe - mantendo a configuracao atual."
else
    echo "Gerando .env ..."
    {
        echo "WUZAPI_ADMIN_TOKEN=$(gerar_hex 24)"
        echo "WUZAPI_GLOBAL_ENCRYPTION_KEY=$(gerar_hex 16)"
        echo "WUZAPI_GLOBAL_HMAC_KEY=$(gerar_hex 24)"
        echo "UI_PORT=8090"
        echo "# Restringe o acesso a interface por IP. Vazio = qualquer origem"
        echo "# que alcance a porta. Ex.: ACL_IPS=192.168.0.10,203.0.113.4"
        echo "ACL_IPS="
        echo "# Filtro OPCIONAL de numeros, nos dois sentidos."
        echo "# VAZIO = atende TODOS os numeros. E o normal."
        echo "# Preencha (separado por virgula) so' para homologar falando com um"
        echo "# numero unico antes de abrir pro publico. Ex.: 5511999998888"
        echo "ALLOWLIST_NUMEROS="
        [ -n "$SENHA_ESCOLHIDA" ] && echo "SENHA_ADMIN_INICIAL=${SENHA_ESCOLHIDA}"
    } > .env
    chmod 600 .env 2>/dev/null || true
    echo ".env criado."
fi

# ── Sobe ────────────────────────────────────────────────────────────────
echo
echo "Subindo os containers. A primeira vez demora: o servidor de WhatsApp e"
echo "compilado a partir do codigo-fonte."
docker compose up -d --build

echo
echo "Aguardando a ponte iniciar..."
SENHA=""
for _ in $(seq 1 40); do
    SENHA="$(docker compose logs ponte 2>/dev/null | grep -m1 'SENHA_INICIAL_ADMIN=' | sed 's/.*SENHA_INICIAL_ADMIN=//' | tr -d '\r' || true)"
    [ -n "$SENHA" ] && break
    sleep 1
done
[ -n "$SENHA_ESCOLHIDA" ] && SENHA="$SENHA_ESCOLHIDA"

UI_PORT="$(grep -m1 '^UI_PORT=' .env | cut -d= -f2)"
UI_PORT="${UI_PORT:-8090}"

echo
linha
echo "  INSTALADO"
linha
echo
echo "  1. Abra no navegador:   http://127.0.0.1:${UI_PORT}/"
echo "  2. Usuario:             admin"
if [ -n "$SENHA" ]; then
    echo "     Senha:               ${SENHA}"
else
    echo "     Senha:               rode  bash senha.sh"
fi
echo
echo "  3. Crie uma sessao e leia o QR Code com o WhatsApp do numero."
echo "  4. Informe o endereco e o token do seu sistema de atendimento."
echo
echo "  Esqueceu a senha depois?   bash senha.sh"
echo "  Atualizar:                 bash atualizar.sh"
echo "  Ver o que esta rodando:    docker compose ps"
echo "  Registro de atividade:     docker compose logs -f ponte"
echo
echo "  Atendendo TODOS os numeros. Para restringir durante um teste,"
echo "  preencha ALLOWLIST_NUMEROS no .env e rode: docker compose restart ponte"
echo
linha
printf '%s\n' "$AVISO"
linha
