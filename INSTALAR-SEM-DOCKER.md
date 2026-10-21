# Instalar direto no Linux, sem Docker

Este é o caminho manual. Ele existe para servidor onde instalar Docker não é
opção. **Se você pode usar Docker, use** — `bash instalar.sh` faz tudo o que
está aqui embaixo sozinho, e é o caminho testado com mais frequência.

O aviso de risco do [README](README.md) vale igual: a API do WhatsApp usada aqui
é **não-oficial**, e o número pode ser bloqueado a qualquer momento.

Os comandos abaixo assumem Debian 12 ou Ubuntu 22.04+. Em outra distribuição,
mudam os nomes dos pacotes, não os passos.

Comece baixando o projeto — os passos seguintes rodam de dentro dessa pasta:

```bash
git clone https://github.com/thriago/ponte-whatsapp.git
cd ponte-whatsapp
```

---

## 1. Dependências

São duas: **PHP** para a ponte e **Go** para compilar o motor.

```bash
sudo apt update
sudo apt install -y php-cli php-sqlite3 php-curl php-mbstring \
                    sqlite3 git curl ca-certificates golang-go
```

Confira as versões — a ponte precisa de **PHP 8.1 ou maior**, e o motor de
**Go 1.21 ou maior**:

```bash
php -v
php -m | grep -E 'pdo_sqlite|curl|mbstring'   # as três precisam aparecer
go version
```

Se o `golang-go` da sua distribuição for antigo, instale o Go oficial:
<https://go.dev/doc/install>.

---

## 2. Compilar e subir o motor (wuzapi)

```bash
cd /opt
sudo git clone https://github.com/asternic/wuzapi.git
cd wuzapi
sudo git checkout 919c72c9750b2a1eedf0fcf9c9592f05fe46f61c
sudo go build -o wuzapi .
```

O commit acima é o mesmo que o `docker-compose.yml` usa. Não troque por `main`
sem ler o changelog do projeto: é software de terceiros e muda sem aviso.

Gere os segredos (guarde os três, você vai usá-los no passo 3):

```bash
openssl rand -hex 24   # WUZAPI_ADMIN_TOKEN
openssl rand -hex 16   # WUZAPI_GLOBAL_ENCRYPTION_KEY
openssl rand -hex 24   # WUZAPI_GLOBAL_HMAC_KEY
```

Suba o motor:

```bash
cd /opt/wuzapi
export WUZAPI_ADMIN_TOKEN='<o primeiro valor>'
export WUZAPI_GLOBAL_ENCRYPTION_KEY='<o segundo>'
export WUZAPI_GLOBAL_HMAC_KEY='<o terceiro>'
export WEBHOOK_FORMAT=json
./wuzapi -skipmedia -datadir=/opt/wuzapi/dbdata
```

⚠️ **O `-skipmedia` não é opcional.** Sem ele, o motor baixa toda a mídia de
toda conversa assim que chega, e um número movimentado consome a memória da
máquina até derrubá-la. A ponte busca cada arquivo sob demanda, só do que vai
mesmo ser encaminhado.

O motor escuta em `127.0.0.1:8080`. **Não publique essa porta na internet**:
quem a alcança controla as sessões de WhatsApp.

---

## 3. Configurar a ponte

Na pasta onde você clonou este repositório:

```bash
cp .env.example .env
chmod 600 .env
nano .env
```

Preencha:

| Variável | Valor |
|---|---|
| `WUZAPI_ADMIN_TOKEN` | o mesmo do passo 2 |
| `WUZAPI_GLOBAL_ENCRYPTION_KEY` | o mesmo do passo 2 |
| `WUZAPI_GLOBAL_HMAC_KEY` | o mesmo do passo 2 |
| `UI_PORT` | `8090`, ou outra porta livre |
| `ACL_IPS` | vazio, salvo se for acessar de outra máquina |
| `ALLOWLIST_NUMEROS` | **vazio** para atender todos (o normal) |
| `MENSAGEM_VALIDADE_MIN` | `5`: minutos que uma resposta pode esperar pra sair antes de ser descartada |
| `SENHA_ADMIN_INICIAL` | a senha que você quer para o usuário `admin` |

Fora do Docker, três variáveis a mais dizem à ponte onde as coisas estão:

```bash
export DB_PATH=/opt/ponte-dados/app.db     # o banco local da ponte
export WUZAPI_BASE_URL=http://127.0.0.1:8080
export PONTE_INTERNAL_URL=http://127.0.0.1:8090
export APP_PORT=8090
```

E o `.env` precisa ser lido pelo processo — a maneira mais simples:

```bash
set -a && . ./.env && set +a
mkdir -p /opt/ponte-dados && chmod 700 /opt/ponte-dados
```

---

## 4. Criar o banco e o usuário admin

```bash
php ponte/bin/migrar.php
```

Ele cria as tabelas e o usuário `admin`. Se `SENHA_ADMIN_INICIAL` estava
preenchida, a senha é essa. Se estava vazia, ele imprime uma senha sorteada na
linha `SENHA_INICIAL_ADMIN=...` — anote, e a troca será exigida no primeiro
acesso.

---

## 5. Subir a ponte

São **dois processos**: o servidor web e o laço que busca as respostas
pendentes no GuiaChat.

```bash
php ponte/bin/puxar_loop.php >> /opt/ponte-dados/puxar.log 2>&1 &
php -S 127.0.0.1:8090 -t ponte/public ponte/public/index.php
```

⚠️ **Um único `puxar_loop.php` por instalação.** Dois processos com o mesmo
token do canal disputam a mesma fila e duplicam mensagem.

**Se você vai abrir a interface de outro computador**, `127.0.0.1` não serve —
ela só responde a quem está na própria máquina. Troque por `0.0.0.0`:

```bash
php -S 0.0.0.0:8090 -t ponte/public ponte/public/index.php
```

e **preencha `ACL_IPS`** com os IPs de onde você acessa, senão qualquer um que
alcance a porta chega à tela de login. Lembre também que isto é HTTP puro: pela
internet, use VPN ou um proxy reverso com HTTPS.

Abra `http://127.0.0.1:8090/` (ou `http://<ip-da-máquina>:8090/`) e siga o passo
a passo do README — parear o QR e informar o endereço e o token do canal.

---

## 6. Deixar rodando sozinho (systemd)

Os comandos acima morrem quando você fecha o terminal. Para o servidor manter
tudo de pé e voltar depois de um reboot, crie três serviços.

`/etc/systemd/system/wuzapi.service`:

```ini
[Unit]
Description=Motor WhatsApp (wuzapi)
After=network.target

[Service]
WorkingDirectory=/opt/wuzapi
Environment=WEBHOOK_FORMAT=json
EnvironmentFile=/opt/ponte/.env
ExecStart=/opt/wuzapi/wuzapi -skipmedia -datadir=/opt/wuzapi/dbdata
Restart=always
User=ponte

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/ponte-web.service`:

```ini
[Unit]
Description=Ponte WhatsApp — interface web
After=wuzapi.service

[Service]
WorkingDirectory=/opt/ponte
EnvironmentFile=/opt/ponte/.env
Environment=DB_PATH=/opt/ponte-dados/app.db
Environment=WUZAPI_BASE_URL=http://127.0.0.1:8080
Environment=PONTE_INTERNAL_URL=http://127.0.0.1:8090
ExecStart=/usr/bin/php -S 127.0.0.1:8090 -t /opt/ponte/ponte/public /opt/ponte/ponte/public/index.php
Restart=always
User=ponte

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/ponte-puxar.service`: igual ao anterior, trocando só a
descrição e o `ExecStart`:

```ini
ExecStart=/usr/bin/php /opt/ponte/ponte/bin/puxar_loop.php
```

Ative os três (ajuste `/opt/ponte` para onde você clonou o repositório e crie o
usuário `ponte` com `sudo adduser --system --group ponte`):

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now wuzapi ponte-web ponte-puxar
sudo systemctl status wuzapi ponte-web ponte-puxar
```

---

## Manutenção

| Tarefa | Comando |
|---|---|
| Ver o que está acontecendo | `journalctl -u ponte-web -u ponte-puxar -u wuzapi -f` |
| Trocar a senha do admin | `php ponte/bin/senha.php "<senha nova>"` (com as variáveis do passo 3 exportadas) |
| Atualizar a ponte | `git pull` e reiniciar os serviços |
| Atualizar o motor | novo `git checkout <commit>` em `/opt/wuzapi`, `go build`, reiniciar |

**Faça backup** de `/opt/ponte-dados/` e `/opt/wuzapi/dbdata/`. O primeiro tem a
integração e o histórico; o segundo tem a sessão pareada — perdê-lo obriga a ler
o QR de novo.
