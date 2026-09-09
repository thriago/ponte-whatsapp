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

# ── Descobre o IP desta maquina na rede ─────────────────────────────────
# Detectar e' melhor que perguntar: quem instala num servidor alugado nem
# sempre sabe o IP de cor, e digitado errado a instalacao termina apontando
# pra um endereco que nao abre. Se nada for descoberto, o instalador pergunta.
descobrir_ip() {
    local ip=""

    # 1) Rota de saida. E' o metodo bom: devolve o IP da interface que
    #    realmente fala com o mundo, sem depender de nome de interface nem de
    #    adivinhar faixa. Nao manda pacote alem da consulta de rota.
    ip="$(ip route get 1.1.1.1 2>/dev/null | sed -n 's/.*src \([0-9.]*\).*/\1/p' | head -1)"

    # 2) Interfaces com escopo global, descartando as virtuais do Docker/libvirt
    #    PELO NOME. Filtrar por faixa de IP seria errado: 172.16-172.31 e' rede
    #    privada legitima (o proprio WSL usa 172.17.x), e descartar essa faixa
    #    jogaria fora justamente o endereco certo em muita maquina.
    if [ -z "$ip" ]; then
        ip="$(ip -4 -o addr show scope global 2>/dev/null \
            | grep -Ev ' (docker|br-|veth|virbr|lo)' \
            | sed -n 's/.*inet \([0-9.]*\).*/\1/p' | head -1)"
    fi

    # 3) macOS: Wi-Fi e depois Ethernet.
    [ -z "$ip" ] && ip="$(ipconfig getifaddr en0 2>/dev/null)"
    [ -z "$ip" ] && ip="$(ipconfig getifaddr en1 2>/dev/null)"

    # 4) Ultimo recurso: primeiro endereco nao-loopback que a maquina anuncia.
    [ -z "$ip" ] && ip="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -v '^127\.' | head -1)"

    printf '%s' "$ip"
}

# ── Como esta maquina vai ser acessada ──────────────────────────────────
# O padrao do compose e' escutar so' em 127.0.0.1. Isso protege quem instalou
# no proprio computador, mas deixa a interface INALCANCAVEL quando a ponte
# roda num servidor sem tela — que e' o caso mais comum. Por isso a pergunta.
BIND_ADDR="127.0.0.1"
ACL_ESCOLHIDA=""
UI_PORT_ESCOLHIDA="8090"
IP_LOCAL="$(descobrir_ip)"

if [ ! -f .env ]; then
    linha
    echo "  Onde voce vai abrir a interface?"
    linha
    echo
    echo "  1) Nesta mesma maquina (vou usar o navegador daqui)"
    echo "  2) De outro computador (esta maquina e' um servidor)"
    echo
    read -r -p "Escolha [1/2]: " ondeacesso

    if [ "$ondeacesso" = "2" ]; then
        BIND_ADDR="0.0.0.0"
        echo
        echo "  A interface vai ficar acessivel pela rede."
        if [ -n "$IP_LOCAL" ]; then
            echo "  IP detectado desta maquina: ${IP_LOCAL}"
        else
            echo "  Nao consegui descobrir o IP desta maquina sozinho."
            read -r -p "  Qual o IP dela na rede? (so' pra mostrar no final): " IP_LOCAL
        fi
        echo
        echo "  IMPORTANTE: se esta maquina tem IP publico, a interface fica"
        echo "  exposta na internet e a senha viaja SEM criptografia (e' HTTP)."
        echo "  Restrinja quem pode abrir, informando os IPs de onde voce acessa."
        echo
        echo "  Ex.: 192.168.0.10        (so' o seu computador)"
        echo "       192.168.0.10,203.0.113.4"
        echo "  Enter em branco = QUALQUER origem que alcance a porta."
        echo
        read -r -p "  IPs autorizados: " ACL_ESCOLHIDA
        if [ -z "$ACL_ESCOLHIDA" ]; then
            echo
            echo "  >> Sem restricao de IP. Qualquer um que alcance a porta ve a"
            echo "     tela de login. A senha passa a ser a unica barreira."
            echo "     Da' pra corrigir depois: preencha ACL_IPS no .env e rode"
            echo "     docker compose restart ponte"
        fi
        echo
        read -r -p "  Porta da interface [8090]: " p
        [ -n "$p" ] && UI_PORT_ESCOLHIDA="$p"
    fi
    echo
fi

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
        echo "UI_PORT=${UI_PORT_ESCOLHIDA}"
        echo "# Quem alcanca a interface. 127.0.0.1 = so' esta maquina."
        echo "# 0.0.0.0 = qualquer um que chegue na porta (servidor acessado"
        echo "# de outro computador). Mudou aqui? rode: docker compose up -d"
        echo "BIND_ADDR=${BIND_ADDR}"
        echo "# Restringe o acesso a interface por IP. Vazio = qualquer origem"
        echo "# que alcance a porta. Ex.: ACL_IPS=192.168.0.10,203.0.113.4"
        echo "ACL_IPS=${ACL_ESCOLHIDA}"
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

# ── Constroi as imagens ──────────────────────────────────────────────────
# Direto pelo buildx, e NAO por `docker compose build`: em algumas versoes do
# Docker Desktop o compose roteia o build por um caminho ("bake") que falha
# antes de compilar ("failed to execute bake"). O buildx nao passa por ele.
# Os nomes das imagens batem com o `image:` de cada servico no compose.
MOTOR_CTX='https://github.com/asternic/wuzapi.git#919c72c9750b2a1eedf0fcf9c9592f05fe46f61c'
echo
echo "Construindo o servidor de WhatsApp a partir do codigo-fonte."
echo "A primeira vez demora alguns minutos."
docker buildx build "$MOTOR_CTX" -t ponte-whatsapp-motor:local
echo
echo "Construindo a ponte."
docker buildx build ./ponte -t ponte-whatsapp-app:local

# ── Sobe ────────────────────────────────────────────────────────────────
echo
echo "Subindo os containers."
docker compose up -d

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
BIND_EFETIVO="$(grep -m1 '^BIND_ADDR=' .env | cut -d= -f2)"
BIND_EFETIVO="${BIND_EFETIVO:-127.0.0.1}"

# Qual endereco imprimir. Mostrar 127.0.0.1 pra quem instalou num servidor sem
# tela manda a pessoa pra um endereco que nao abre do computador dela.
if [ "$BIND_EFETIVO" = "0.0.0.0" ]; then
    [ -z "$IP_LOCAL" ] && IP_LOCAL="$(descobrir_ip)"
    if [ -n "$IP_LOCAL" ]; then
        ENDERECO="http://${IP_LOCAL}:${UI_PORT}/"
    else
        ENDERECO="http://<o-ip-desta-maquina>:${UI_PORT}/"
    fi
else
    ENDERECO="http://127.0.0.1:${UI_PORT}/"
fi

echo
linha
echo "  INSTALADO"
linha
echo
echo "  1. Abra no navegador:   ${ENDERECO}"
if [ "$BIND_EFETIVO" = "0.0.0.0" ]; then
    echo "     (deste computador:   http://127.0.0.1:${UI_PORT}/ )"
fi
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
if [ -n "$SENHA_ESCOLHIDA" ]; then
    echo "  A senha que voce escolheu ficou registrada no .env (so' o dono do"
    echo "  arquivo le). Ela nao e' mais usada depois deste primeiro boot:"
    echo "  se quiser, apague a linha SENHA_ADMIN_INICIAL de la."
    echo
fi
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
