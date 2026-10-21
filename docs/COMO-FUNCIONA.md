# Como funciona por dentro

Este documento é para quem vai operar, auditar ou modificar a ponte. Para
instalar e usar, o [README](../README.md) basta.

---

## As três peças

```
docker compose up
   ├── wuzapi   motor: expõe a biblioteca whatsmeow por REST (sessões, QR, envio)
   └── ponte    interface web + tradução entre o motor e a API do canal
```

O **motor** é software de terceiros ([wuzapi](https://github.com/asternic/wuzapi),
licença MIT), preso a um commit específico no `docker-compose.yml`. Ele nunca é
alcançável de fora: só a ponte fala com ele, pela rede interna do Docker.

A **ponte** é o código deste repositório. PHP sem framework e sem dependência
externa, SQLite local, um front controller único (`ponte/public/index.php`).

A fronteira entre as duas é deliberada: **só `ponte/src/WuzapiClient.php`
conhece a API do motor**. Trocar de motor é reescrever esse arquivo, sem tocar
na interface, no instalador nem no contrato com o sistema de atendimento.

---

## Por que a resposta é buscada, e não recebida

O tráfego tem duas direções, e só uma é fácil:

| Direção | Quem inicia | Precisa de porta aberta? |
|---|---|---|
| mensagem chegando (aqui → GuiaChat) | esta máquina | **não**, é saída |
| resposta do atendente (GuiaChat → aqui) | o servidor do GuiaChat | **sim**, seria entrada |

A segunda é o que costuma inviabilizar a instalação: a máquina vive atrás de
NAT, muitas vezes atrás de CGNAT, onde não existe IP público para abrir. E sem
domínio não existe certificado válido — sobrariam HTTP puro (conversa do cliente
e token trafegando em claro) ou certificado próprio.

Por isso o modo padrão é **puxar**:

```
a cada poucos segundos:
   GET  <guiachat>/api/v1/canal/pendentes    o que está na fila
   POST <guiachat>/api/v1/canal/confirmar    "entreguei estas"
```

Sem porta aberta, sem domínio, com HTTPS válido (quem conecta é esta máquina).
O custo é alguns segundos de latência na resposta, invisíveis num atendimento.

O modo `webhook` continua existindo para quem já tem servidor com domínio e
HTTPS — é a mesma fila do outro lado, só muda quem procura quem.

**Ler não confirma.** A confirmação vai depois da entrega, com o resultado de
cada uma: `enviado` (com o id da mensagem no WhatsApp) ou `falhou` (com o
motivo, que aparece para o atendente ao lado da mensagem). Entrega não
confirmada volta sozinha à fila depois de 60 segundos.

Quatro regras em cima disso:

| Regra | Por quê |
|---|---|
| **Uma entrega sai uma vez só.** O que já foi entregue ao motor fica registrado no banco da ponte; se a entrega voltar da fila, a ponte só confirma de novo | o motor pode levar mais de um minuto pra responder um envio quando a conexão do WhatsApp está ruim, e não cancela o envio quando a ponte desiste de esperar. Reenviar nessa hora fazia o cliente receber a mesma mensagem dezenas de vezes |
| **Mensagem velha não sai.** Passou de `MENSAGEM_VALIDADE_MIN` (padrão 5 minutos) sem conseguir enviar, ela é descartada como `falhou` | a resposta de uma hora atrás, chegando fora de contexto e depois que outro atendente já assumiu, é pior que nenhuma |
| **Sessão desconectada não acumula fila.** Com o WhatsApp deslogado, a entrega espera no máximo 1 minuto e depois falha, avisando que é preciso ler o QR de novo | é o tempo de uma reconexão rápida, sem segurar mensagem por horas |
| **O número é conferido antes de enviar.** A ponte pergunta ao WhatsApp como o número está registrado (ver "nono dígito" abaixo) e falha com o motivo se ele não tiver WhatsApp | enviar para um número que não existe não dá erro nenhum: a mensagem só some |

**Entregue e lido.** A ponte assina os recibos do motor e repassa ao
GuiaChat (`POST /api/v1/canal/status`) os das mensagens que ela mesma enviou.
É o que acende os dois risquinhos no atendimento.

O motivo da falha e os recibos dependem de o GuiaChat aceitar o resultado no
`confirmar`. Numa instalação mais antiga, a ponte continua tirando tudo da fila
do mesmo jeito; só não aparecem o motivo nem os risquinhos.

⚠️ **Um único consumidor por token.** Dois processos puxando a mesma fila
colidem na trava de visibilidade e duplicam mensagem.

---

## Mídia: sempre sob demanda

O motor sobe com `-skipmedia`, sempre. Sem essa flag ele baixa toda a mídia de
toda conversa assim que ela chega, inclusive de grupos que ninguém vai atender,
e um número movimentado consome a memória da máquina até derrubá-la.

Com a flag, a referência da mídia continua chegando no evento — a ponte busca
**um arquivo por vez**, só das mensagens que vão mesmo ser encaminhadas, e mede
o tamanho antes de baixar (o teto é 20 MB, o mesmo que a API do canal aceita).

⚠️ **Falha de download nunca descarta a mensagem.** Sem esse cuidado, o cliente
manda um áudio, o download falha e ninguém fica sabendo que ele falou: não vira
contato nem aparece para o atendente. Quando a mídia não vem, a ponte encaminha
um texto avisando disso. Melhor uma conversa incompleta que uma conversa
invisível.

---

## Tradução

**Entrando** (motor → sistema de atendimento):

| motor | canal |
|---|---|
| JID (`5511999998888@s.whatsapp.net`) | `de`, só os dígitos |
| `Info.Id` | `id_externo` (é o que evita mensagem repetida) |
| `Message.conversation` | `texto` |
| `pushName` | `contato.nome` |
| mídia | baixada sob demanda e enviada por upload direto |
| localização (pino ou tempo real) | `tipo: localizacao` com latitude, longitude, nome e endereço. O GuiaChat mostra no mapa e o robô de atendimento recebe o endereço. Instalação que ainda não aceita o tipo recebe um texto com o link do mapa |

**Saindo** (sistema de atendimento → motor):

| canal | motor |
|---|---|
| `texto` | `POST /chat/send/text` |
| `midia.url` | baixa com o token do canal → `POST /chat/send/{image,audio,video,document}` |

O que é ignorado de propósito, sempre com o motivo registrado em **Últimos
eventos**: eco da própria mensagem (senão vira laço infinito), grupo,
status/stories, figurinha, contato compartilhado e enquete.

### Os cantos que mordem

| Caso | O que acontece |
|---|---|
| **LID** | o endereçamento novo da Meta: o remetente vem como um ID opaco (`...@lid`) em vez do telefone. O telefone real vem num campo irmão, `SenderAlt`. A ponte guarda o par localmente e resolve pelo mapa quando o `SenderAlt` não vier. Sem os dois, ela **recusa** em vez de inventar um contato |
| **aparelho secundário** | o JID vem como `numero:76@servidor`. O `:76` é o aparelho, não parte do número — colar os dois cria um contato fantasma |
| **nono dígito** | `5511999998888` e `551199998888` são a mesma pessoa, mas quem tem WhatsApp desde antes do nono dígito continua registrado sem ele. Enviar pra forma errada não dá erro: a mensagem some. Antes de enviar, a ponte pergunta ao WhatsApp (`/user/check` do motor) qual é o número registrado, envia pra ele e guarda a resposta por 7 dias. A allowlist aceita as duas formas automaticamente |
| **maiúsculas do motor** | a documentação do wuzapi diverge do binário em alguns campos (`connected` vs `Connected`, `id` string vs inteiro). Onde divergir, vale o binário — por isso o parser tenta variantes em vez de travar numa só |

Todo evento de entrada grava o **payload bruto** no registro. É o que permite
corrigir um campo novo em minutos, em vez de caçar às cegas.

---

## O filtro de números

`ALLOWLIST_NUMEROS` no `.env` vale nos dois sentidos: mensagem de número fora da
lista é descartada com registro, e nenhuma resposta sai para fora dela, mesmo
que chegue por qualquer caminho.

**Vazia significa atender todos.** É o padrão e é o uso normal. Preenchida, liga
o modo homologação, útil para provar a instalação falando só com o seu próprio
celular. A interface mostra em qual dos dois modos a instalação está, em toda
tela, e o registro de boot diz o mesmo.

Isto não é fronteira de segurança — é conveniência de teste. Quem protege o
acesso é a senha da interface e a ACL de IP.

---

## Segurança

| Risco | Tratamento |
|---|---|
| Interface exposta na internet | nasce escutando só em `127.0.0.1`; publicar é escolha explícita no `docker-compose.yml` |
| Alguém de fora acha a porta exposta | `ACL_IPS` no `.env`, validada pelo IP real da conexão |
| Tentação de validar por domínio | `Origin`, `Referer` e `Host` são escolhidos por quem chama: servem para organizar, nunca para barrar |
| Senha padrão que ninguém troca | senha sorteada tem troca obrigatória no primeiro acesso; senha escolhida na instalação vale direto |
| Sessão do WhatsApp roubada | o volume do Docker dá acesso a todas as conversas do número: trate-o como o próprio celular |
| Webhook do motor | a assinatura HMAC é conferida e registrada; a fronteira principal continua sendo a rede interna |
| Token do canal em texto no banco | inevitável, a ponte precisa dele para chamar a API. Arquivo com permissão restrita, e o token nunca vai para o log |
| Mídia enchendo o disco | `-skipmedia`, busca sob demanda e teto de tamanho antes do download |

⚠️ No Docker Desktop (Windows e Mac), o tráfego do host chega ao container com o
IP do gateway da rede Docker, não `127.0.0.1`. Por isso a ACL só entra em ação
quando `ACL_IPS` é preenchido: barrar por `REMOTE_ADDR == 127.0.0.1` bloquearia
quem está na própria máquina.

---

## Onde ficam os dados

| Dado | Onde | Perder significa |
|---|---|---|
| sessão pareada do WhatsApp | volume `wuzapi_dbdata` | ler o QR de novo |
| sessões, integração, eventos, login, registro de envios | volume `ponte_data` | reconfigurar a integração |
| segredos | `.env` na pasta do repositório | regerar e reconfigurar |

`docker compose down` preserva os dois volumes. `docker compose down -v`
**apaga**.
