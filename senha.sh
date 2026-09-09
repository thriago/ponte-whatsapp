#!/usr/bin/env bash
# senha.sh — mostra ou troca a senha do administrador da interface.
#
# Uso:
#   bash senha.sh          mostra a senha atual (se ainda for a inicial)
#   bash senha.sh --nova   define uma senha nova
#
# Existe porque "esqueci a senha" não pode virar reinstalar tudo. A senha
# inicial só aparece uma vez no registro de atividade; depois que a pessoa a
# troca pela interface, ela não é mais recuperável — e aí o caminho é definir
# uma nova, que é o que `--nova` faz.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

if ! docker compose ps >/dev/null 2>&1; then
    echo "ERRO: não consegui falar com o Docker. Ele está rodando?"
    exit 1
fi

if [ "${1:-}" = "--nova" ]; then
    read -r -s -p "Senha nova (mínimo 8 caracteres): " s1; echo
    if [ "${#s1}" -lt 8 ]; then echo "Senha muito curta."; exit 1; fi
    read -r -s -p "Repita: " s2; echo
    if [ "$s1" != "$s2" ]; then echo "As senhas não conferem."; exit 1; fi

    # MSYS_NO_PATHCONV=1: no Git Bash do Windows, sem isto o caminho
    # /app/bin/senha.php é convertido pra C:/Program Files/Git/app/... antes de
    # chegar no container. No Linux a variável é inofensiva.
    MSYS_NO_PATHCONV=1 docker compose exec -T ponte php /app/bin/senha.php "$s1"
    echo
    echo "Senha alterada. Entre em http://127.0.0.1:$(grep -m1 '^UI_PORT=' .env 2>/dev/null | cut -d= -f2 || echo 8090)/ com o usuário admin."
    exit 0
fi

SENHA="$(docker compose logs ponte 2>/dev/null | grep -m1 'SENHA_INICIAL_ADMIN=' | sed 's/.*SENHA_INICIAL_ADMIN=//' | tr -d '\r' || true)"

if [ -n "$SENHA" ]; then
    echo "Usuário: admin"
    echo "Senha:   ${SENHA}"
    echo
    echo "Se esta senha não funcionar, ela já foi trocada. Defina uma nova:"
    echo "  bash senha.sh --nova"
else
    echo "Não achei a senha inicial no registro de atividade."
    echo "Isso é normal se a instalação já tem alguns dias — o registro é rotativo."
    echo
    echo "Defina uma senha nova:"
    echo "  bash senha.sh --nova"
fi
