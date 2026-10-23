# CreditFlow

API REST para gestao de contas e movimentacoes de credito. O backend usa Laravel,
PHP 8.4 e PostgreSQL. O frontend opcional usa Vue 3 e fica na pasta `frontend`.

## Requisitos

- Docker Desktop com Docker Compose.
- Git.

PHP e Composer rodam dentro do container e nao precisam ser instalados no Windows.

## Iniciar o backend

Na primeira execucao, crie os arquivos locais de ambiente. Defina uma senha local
em `CREDITFLOW_DB_PASSWORD` no `.env` da raiz. Para manter um volume PostgreSQL ja
existente, use a senha configurada quando esse banco foi inicializado.

```bat
copy .env.example .env
copy backend\.env.example backend\.env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose restart app
docker compose exec app php artisan migrate
```

Se os arquivos locais ja existirem, nao copie os exemplos novamente: isso preserva
sua chave local e a senha do banco. Os arquivos `.env` sao ignorados pelo Git. A API
fica em `http://localhost:8000`; o PostgreSQL fica no servico `db` dentro do Compose.

Confira os containers e as migrations:

```bat
docker compose ps
docker compose exec app php artisan migrate:status
```

## Autenticacao

Cadastre um usuario:

```http
POST /api/register
Content-Type: application/json

{
  "name": "Tony",
  "email": "tony@example.com",
  "password": "senha-segura-123",
  "password_confirmation": "senha-segura-123"
}
```

O cadastro retorna um token Bearer e cria uma conta com saldo zero. Para entrar,
envie `POST /api/login` com `email` e `password`. Use o token retornado nas rotas
protegidas:

```http
Authorization: Bearer SEU_TOKEN
```

Exemplo de login:

```http
POST /api/login
Content-Type: application/json

{
  "email": "tony@example.com",
  "password": "senha-segura-123"
}
```

Consulte a conta sem body usando `GET /api/account` e o cabecalho Bearer.

## Endpoints

| Metodo | Rota | Autenticacao | Descricao |
| --- | --- | --- | --- |
| `POST` | `/api/register` | Nao | Cadastra usuario, conta e token |
| `POST` | `/api/login` | Nao | Autentica usuario e retorna token |
| `GET` | `/api/account` | Bearer | Consulta saldo da conta autenticada |
| `POST` | `/api/transactions/credit` | Bearer | Registra credito |
| `POST` | `/api/transactions/debit` | Bearer | Registra debito |
| `POST` | `/api/transactions/{id}/reverse` | Bearer | Estorna uma movimentacao |
| `GET` | `/api/transactions` | Bearer | Consulta extrato paginado |

### Credito e debito

Valores sao enviados como inteiros em centavos, sem ponto flutuante. Por exemplo,
`1250` representa R$ 12,50. Toda requisicao de credito ou debito precisa de uma
chave unica no cabecalho `Idempotency-Key`:

```http
POST /api/transactions/credit
Authorization: Bearer SEU_TOKEN
Idempotency-Key: credito-pedido-001
Content-Type: application/json

{
  "amount_cents": 1250
}
```

Para debitar, use `POST /api/transactions/debit` com o mesmo formato e uma chave
propria. Repetir chave e dados retorna a movimentacao original sem alterar o saldo.
Reutilizar a chave com outro tipo ou valor retorna `409 Conflict`. Valor ausente,
nao inteiro ou menor que 1 retorna `422`; saldo insuficiente tambem retorna `422`.

```http
POST /api/transactions/debit
Authorization: Bearer SEU_TOKEN
Idempotency-Key: debito-pedido-002
Content-Type: application/json

{
  "amount_cents": 500
}
```

### Estorno

Envie `POST /api/transactions/{id}/reverse` com Bearer token e sem body. O estorno
cria uma nova movimentacao ligada a original; a movimentacao anterior permanece no
extrato. Cada movimentacao pode ser estornada uma vez. O estorno de um credito e
recusado se o saldo atual nao cobrir o valor a devolver.

### Extrato

`GET /api/transactions` aceita os filtros opcionais:

- `type`: `credit`, `debit` ou `reversal`.
- `date_from` e `date_to`: datas inclusivas no formato `AAAA-MM-DD`.
- `per_page`: quantidade por pagina entre 1 e 100; padrao 15.
- `page`: numero da pagina.

Exemplo: `/api/transactions?type=debit&date_from=2026-10-01&date_to=2026-10-31&per_page=10`.
O extrato retorna somente as movimentacoes da conta autenticada e informa o usuario
responsavel, o vinculo de estorno e os dados de paginacao.

## Testes automatizados

O container instala os drivers PostgreSQL para a API e SQLite para a suite
automatizada em banco em memoria. Recrie a imagem depois de alterar o Dockerfile e
execute:

```bat
docker compose up -d --build app
docker compose exec app composer test
```

Os testes automatizados cobrem autenticacao, criacao da conta, saldo, idempotencia,
debito insuficiente, estorno, extrato, filtros e isolamento entre usuarios. A API
local continua usando PostgreSQL.

## Frontend Vue

O frontend Vue roda em um container separado e encaminha as chamadas `/api` para o
servico Laravel. Com os servicos `app` e `db` ativos, inicie a interface:

```bat
docker compose up -d frontend
```

Abra `http://localhost:5173`. Crie uma conta ou entre com um usuario cadastrado na
API. A tela permite consultar o saldo, registrar creditos e debitos em centavos,
filtrar e paginar o extrato e solicitar estornos. O frontend guarda o token Bearer
no armazenamento local do navegador; sair remove esse token.

Para conferir o bloqueio de concorrencia no PostgreSQL, deixe R$ 10,00 na conta e
envie ao mesmo tempo dois debitos de R$ 8,00 com chaves diferentes. Um deve retornar
`201`, o outro `422`, e o saldo final deve ser R$ 2,00.

## Decisoes tecnicas e uso de IA

As escolhas de arquitetura estao em [DECISOES.md](DECISOES.md). IA generativa foi
usada como apoio de mentoria para planejar, implementar, revisar e documentar o
projeto. A ferramenta utilizada foi OpenAI Codex/ChatGPT.

Exemplo de codigo corrigido com esse apoio: a primeira versao da migration que
preenche `responsible_user_id` usava uma atualizacao com `JOIN` que o PostgreSQL
rejeitou. A versao corrigida percorre os lancamentos em lotes e atualiza os IDs
agrupados pelo usuario responsavel:

```php
DB::table('transactions')
    ->join('accounts', 'transactions.account_id', '=', 'accounts.id')
    ->select([
        'transactions.id as transaction_id',
        'accounts.user_id as responsible_user_id',
    ])
    ->chunkById(500, function ($transactions): void {
        foreach ($transactions->groupBy('responsible_user_id') as $userId => $rows) {
            DB::table('transactions')
                ->whereIn('id', $rows->pluck('transaction_id'))
                ->update(['responsible_user_id' => $userId]);
        }
    }, 'transactions.id', 'transaction_id');
```

Essa correcao foi verificada ao executar a migration no PostgreSQL. A validacao
final do desafio e a explicacao das decisoes tecnicas cabem ao responsavel pelo
projeto.
