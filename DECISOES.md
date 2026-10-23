# Decisoes tecnicas

1. **PostgreSQL para persistencia:** o banco relacional mantem usuarios, contas e movimentacoes com chaves estrangeiras e indices de unicidade.

2. **Valores em centavos:** os valores sao inteiros (`amount_cents` e `balance_cents`), evitando imprecisao de ponto flutuante.

3. **Sanctum para autenticacao:** o login emite tokens Bearer e cada operacao financeira acessa apenas a conta do usuario autenticado.

4. **Operacoes atomicas com bloqueio da conta:** atualizacao do saldo e registro da movimentacao ocorrem na mesma transacao; `FOR UPDATE` serializa operacoes concorrentes da mesma conta.

5. **Idempotencia e estorno compensatorio:** `Idempotency-Key` e unica por conta. Um estorno cria outro lancamento ligado ao original, preservando o historico e impedindo o estorno duplicado.
