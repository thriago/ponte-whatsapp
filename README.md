# Ponte WhatsApp → canal Integrador

Conecta um número comum de WhatsApp, lido por QR Code, ao **canal Integrador**
do GuiaChat. As mensagens dos seus clientes caem na fila do atendimento, e as
respostas do atendente (ou do bot) voltam pro WhatsApp sozinhas.

Roda na sua máquina ou no seu servidor. Você instala, pareia o número e informa
duas coisas: o endereço do seu GuiaChat e o token do canal. Nada além disso.

---

## ⚠️ Leia antes de instalar

**Esta ferramenta usa uma API não-oficial do WhatsApp.** O número pareado pode
ser **bloqueado pela Meta a qualquer momento, sem aviso e sem recurso** —
inclusive nos primeiros dias de uso.

- **Não use um número que você não possa perder.**
- Não é homologada pela Meta, não tem garantia e não tem suporte.
- O motor por baixo é o [wuzapi](https://github.com/asternic/wuzapi), software
  de terceiros (licença MIT). Ele não é mantido aqui.
- Quem instala assume o risco. Se o número cair, não há para quem recorrer.

O caminho oficial e estável para WhatsApp é a **Cloud API da Meta**, que o
GuiaChat também suporta — se o número importa para o seu negócio, prefira ela.

---

## O que você precisa ter em mãos

| Item | Onde consegue |
|---|---|
| Uma máquina Linux, Windows ou Mac ligada 24h | é ela que fica com o WhatsApp conectado |
| Docker instalado e rodando | [docs.docker.com/engine/install](https://docs.docker.com/engine/install/) ou Docker Desktop |
| O endereço do seu GuiaChat | ex.: `https://suaempresa.exemplo.com.br` |
| O token do canal Integrador | no GuiaChat: **Canais e APIs → Adicionar Canal → Integrador**. Começa com `gc_int_` |
| Um celular com o número que vai atender | é nele que você lê o QR Code |

Não precisa de domínio próprio, nem de IP fixo, nem de abrir porta no roteador.
A ponte é quem procura o GuiaChat, e não o contrário — funciona atrás de NAT,
CGNAT e Wi-Fi de escritório.

Prefere instalar sem Docker, direto no Linux? Veja
[INSTALAR-SEM-DOCKER.md](INSTALAR-SEM-DOCKER.md).

---

## Instalar

```bash
git clone https://github.com/thriago/ponte-whatsapp.git
cd ponte-whatsapp
bash instalar.sh
```

O instalador:

1. mostra o aviso de risco e pede que você digite `aceito`;
2. confere se o Docker está instalado e respondendo;
3. **pergunta se você vai abrir a interface nesta máquina ou de outro
   computador** — se for de outro, ele descobre o IP desta máquina, libera o
   acesso pela rede e pergunta de quais IPs você acessa;
4. pergunta a senha do administrador da interface (Enter em branco = ele sorteia
   uma e mostra no final);
5. gera o arquivo `.env` com os segredos, sem você precisar editar nada;
6. sobe os containers e imprime **o endereço certo** para entrar.

A primeira execução demora alguns minutos: o motor de WhatsApp é compilado a
partir do código-fonte. Da segunda vez em diante sobe em segundos.

**No Windows**, rode pelo Git Bash ou pelo WSL — o `docker compose` usado é o do
Docker Desktop.

---

## Depois de instalar

1. **Abra a interface** no endereço que o instalador imprimiu — `http://127.0.0.1:8090/`
   se você instalou no próprio computador, ou `http://<ip-da-máquina>:8090/` se
   instalou num servidor.
2. **Entre** com o usuário `admin` e a senha que apareceu no fim da instalação.
   Se ela foi sorteada, a troca é obrigatória no primeiro acesso.
3. **Sessões → Nova sessão**: dê um nome ("Comercial", "Suporte"). Cada sessão é
   um número de WhatsApp independente.
4. **Conectar / QR**: no celular, abra WhatsApp → *Aparelhos conectados* →
   *Conectar um aparelho*, e leia o QR da tela. Ela atualiza sozinha; não existe
   botão de "verificar".
5. **Integrar** (aparece assim que o número parear): cole o endereço do seu
   GuiaChat e o token do canal, e clique em **Testar conexão**. O teste consulta
   a API de verdade e diz na hora se o token vale.
6. Pronto. Mande uma mensagem para o número pareado e acompanhe em **Últimos
   eventos** o que entrou, o que saiu e o que falhou.

### O que circula

- **Texto**, nos dois sentidos.
- **Imagem, áudio, vídeo e documento**, nos dois sentidos, até 20 MB por arquivo.
- Mensagem de **grupo**, **status/stories** e **eco da própria mensagem** são
  ignorados de propósito — aparecem no registro de eventos com o motivo.

A resposta do atendente leva até alguns segundos para sair: a ponte consulta a
fila do GuiaChat periodicamente, em vez de manter uma porta aberta esperando.

---

## Esqueci a senha

```bash
bash senha.sh          # mostra a senha inicial, se ela ainda não foi trocada
bash senha.sh --nova   # define uma senha nova
```

Não há recuperação por e-mail: quem tem acesso à máquina define a senha.

---

## Atualizar

```bash
bash atualizar.sh
```

Preserva o `.env` e os volumes — **a sessão pareada não se perde**, você não
precisa ler o QR de novo. O script recusa rodar se houver alteração local não
commitada, para não descartar customização em silêncio.

---

## Quando der errado

| Sintoma | Onde olhar |
|---|---|
| A tela de QR não sai de "aguardando" | `docker compose logs -f ponte` e `docker compose logs -f wuzapi` |
| Mandei mensagem e não chegou no atendimento | tela **Últimos eventos** — ela mostra o código HTTP da chamada e o motivo da recusa |
| "Testar conexão" recusa o token | confira se o endereço é a base do seu GuiaChat (sem `/api/...` no fim) e se o token é o do canal **Integrador** |
| Mensagem de um número específico não entra | confira `ALLOWLIST_NUMEROS` no `.env` — ver abaixo |
| Nada responde | `docker compose ps` mostra o que está de pé; `docker compose restart` reinicia |

Recomeçar do zero, **perdendo o pareamento e o histórico**:

```bash
docker compose down -v && bash instalar.sh
```

---

## Segurança

- Quem alcança a interface é decidido por `BIND_ADDR` no `.env`, respondido na
  instalação:

  | `BIND_ADDR` | Quem abre |
  |---|---|
  | `127.0.0.1` (padrão) | só a própria máquina |
  | `0.0.0.0` | qualquer um que alcance a porta |

  Para mudar depois: edite o `.env` e rode `docker compose up -d`.
- **`0.0.0.0` numa máquina com IP público deixa a interface exposta à internet.**
  Nesse caso preencha `ACL_IPS` no `.env` com os IPs de onde você acessa — o
  instalador pergunta isso. Domínio, `Origin` e `Referer` não protegem nada:
  quem chama escolhe o que mandar. A fronteira é IP e senha.
- **A interface é HTTP puro, sem TLS.** Numa rede local, tudo bem. Atravessando
  a internet, a senha viaja em claro: ponha atrás de uma VPN ou de um proxy
  reverso com HTTPS.
- A sessão pareada dá acesso de leitura a **todas as conversas daquele número**.
  O volume do Docker é tão sensível quanto o próprio celular.
- O token do canal fica guardado em texto no banco local da ponte (ela precisa
  dele para chamar a API). O arquivo tem permissão restrita e o token nunca é
  registrado no log.
- `ALLOWLIST_NUMEROS` no `.env` é um filtro **opcional** de números.
  **Vazio, que é o padrão, significa atender todos** — é o uso normal.
  Preenchido, a ponte fala só com os números da lista, nos dois sentidos: serve
  para homologar falando com o seu próprio celular antes de abrir para o
  público. Depois de preencher ou esvaziar, rode
  `docker compose restart ponte`.

---

## O que esta ferramenta não é

- Não é homologada pela Meta.
- Não tem suporte nem garantia.
- Não substitui a Cloud API oficial para quem depende do número.

Como funciona por dentro, e por que cada decisão foi tomada assim:
[docs/COMO-FUNCIONA.md](docs/COMO-FUNCIONA.md).

---

## Licença

Este projeto está sob a licença **MIT** — veja [LICENSE](LICENSE). Use, altere,
distribua e cobre por isso se quiser. Sem garantia de espécie alguma.

O motor de WhatsApp é o **[wuzapi](https://github.com/asternic/wuzapi)**, de
Nicolás Gudiño, também sob licença MIT. Ele **não faz parte deste repositório**:
o instalador o compila a partir do código-fonte oficial, preso a um commit
específico. Todo o crédito pelo motor é do projeto original, e é lá que se
reportam problemas dele.

O wuzapi, por sua vez, usa a biblioteca
[whatsmeow](https://github.com/tulir/whatsmeow).
