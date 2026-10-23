<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { apiRequest, clearToken, getToken, saveToken } from './lib/api'

const token = ref(getToken())
const user = ref(null)
const account = ref(null)
const transactions = ref([])
const pagination = ref(null)
const page = ref(1)
const loading = ref(false)
const authLoading = ref(false)
const transactionLoading = ref(false)
const pageError = ref('')
const pageSuccess = ref('')
const authMode = ref('login')
const filters = reactive({ type: '', date_from: '', date_to: '' })
const authForm = reactive({ name: '', email: '', password: '', password_confirmation: '' })
const amountForm = reactive({ amount_cents: '', type: 'credit' })
let pendingTransaction = { signature: '', key: '' }

const balance = computed(() => account.value?.balance_cents ?? 0)
const transactionCount = computed(() => pagination.value?.total ?? 0)
const isRegister = computed(() => authMode.value === 'register')

function formatMoney(cents) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((cents || 0) / 100)
}

function formatDate(value) {
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function transactionLabel(type) {
  return { credit: 'Crédito', debit: 'Débito', reversal: 'Estorno' }[type] || type
}

function transactionSymbol(transaction) {
  if (transaction.type === 'reversal') {
    return transaction.reversal_of_id ? '↩' : '↪'
  }
  return transaction.type === 'credit' ? '+' : '−'
}

function clearMessages() {
  pageError.value = ''
  pageSuccess.value = ''
}

function showError(error) {
  if (error.status === 401) {
    if (token.value) {
      logout('Sua sessão expirou. Entre novamente.')
    } else {
      pageError.value = error.message || 'E-mail ou senha inválidos.'
    }
    return
  }

  const validationMessages = Object.values(error.errors || {}).flat()
  pageError.value = validationMessages[0] || error.message
}

async function authenticate() {
  clearMessages()
  authLoading.value = true

  try {
    const path = isRegister.value ? '/register' : '/login'
    const body = isRegister.value
      ? { ...authForm }
      : { email: authForm.email, password: authForm.password }
    const result = await apiRequest(path, { method: 'POST', body })

    saveToken(result.token)
    token.value = result.token
    user.value = result.user
    account.value = result.account
    authForm.password = ''
    authForm.password_confirmation = ''
    pageSuccess.value = isRegister.value ? 'Conta criada com sucesso.' : ''
    await loadDashboard()
  } catch (error) {
    showError(error)
  } finally {
    authLoading.value = false
  }
}

function logout(message = '') {
  clearToken()
  token.value = null
  user.value = null
  account.value = null
  transactions.value = []
  pagination.value = null
  clearMessages()
  pageError.value = message
}

function transactionQuery(nextPage = 1) {
  const params = new URLSearchParams({ page: String(nextPage), per_page: '8' })
  if (filters.type) params.set('type', filters.type)
  if (filters.date_from) params.set('date_from', filters.date_from)
  if (filters.date_to) params.set('date_to', filters.date_to)
  return `?${params.toString()}`
}

async function loadDashboard(nextPage = page.value) {
  if (!token.value) return
  loading.value = true
  pageError.value = ''

  try {
    const [accountResult, transactionResult] = await Promise.all([
      apiRequest('/account'),
      apiRequest(`/transactions${transactionQuery(nextPage)}`),
    ])
    const paginationData = transactionResult.meta ?? transactionResult

    account.value = accountResult.account
    transactions.value = transactionResult.data
    pagination.value = paginationData
    page.value = paginationData.current_page ?? nextPage
  } catch (error) {
    showError(error)
  } finally {
    loading.value = false
  }
}

async function submitTransaction() {
  clearMessages()
  transactionLoading.value = true

  try {
    const amount = Number(amountForm.amount_cents)
    if (!Number.isSafeInteger(amount) || amount < 1) {
      throw new Error('Informe um valor inteiro positivo em centavos.')
    }

    const signature = `${amountForm.type}:${amount}`
    if (pendingTransaction.signature !== signature) {
      pendingTransaction = {
        signature,
        key: globalThis.crypto?.randomUUID?.()
          || `${Date.now()}-${Math.random().toString(16).slice(2)}`,
      }
    }

    await apiRequest(`/transactions/${amountForm.type}`, {
      method: 'POST',
      headers: { 'Idempotency-Key': pendingTransaction.key },
      body: { amount_cents: amount },
    })
    amountForm.amount_cents = ''
    pendingTransaction = { signature: '', key: '' }
    pageSuccess.value = amountForm.type === 'credit'
      ? 'Crédito registrado com sucesso.'
      : 'Débito registrado com sucesso.'
    await loadDashboard(1)
  } catch (error) {
    showError(error)
  } finally {
    transactionLoading.value = false
  }
}

async function reverseTransaction(transaction) {
  if (!window.confirm(`Confirma o estorno de ${transactionLabel(transaction.type).toLowerCase()} de ${formatMoney(transaction.amount_cents)}?`)) {
    return
  }

  clearMessages()
  try {
    await apiRequest(`/transactions/${transaction.id}/reverse`, { method: 'POST' })
    pageSuccess.value = 'Estorno registrado com sucesso.'
    await loadDashboard()
  } catch (error) {
    showError(error)
  }
}

function applyFilters() {
  clearMessages()
  loadDashboard(1)
}

function setAuthMode(mode) {
  authMode.value = mode
  clearMessages()
}

onMounted(async () => {
  if (!token.value) return
  try {
    await loadDashboard(1)
  } catch {
    // loadDashboard displays API errors and handles expired tokens.
  }
})
</script>

<template>
  <main v-if="!token" class="auth-layout">
    <section class="auth-story">
      <a class="brand" href="#" aria-label="CreditFlow início">
        <span class="brand-mark">C</span>
        <span>credit<span class="brand-light">flow</span></span>
      </a>
      <div class="story-copy">
        <span class="eyebrow">CONTROLE FINANCEIRO SIMPLES</span>
        <h1>Seu dinheiro,<br /><em>em movimento.</em></h1>
        <p>Acompanhe créditos, débitos e seu saldo em um só lugar.</p>
      </div>
      <span class="story-footer">GESTÃO DE CRÉDITO COM CLAREZA</span>
    </section>

    <section class="auth-panel">
      <div class="auth-card">
        <span class="eyebrow">BEM-VINDO AO CREDITFLOW</span>
        <h2>{{ isRegister ? 'Crie sua conta' : 'Acesse sua conta' }}</h2>
        <p class="muted">{{ isRegister ? 'Comece a organizar suas movimentações.' : 'Entre para consultar seu saldo e extrato.' }}</p>

        <div class="auth-tabs" role="tablist" aria-label="Acesso à conta">
          <button :class="{ active: !isRegister }" type="button" @click="setAuthMode('login')">Entrar</button>
          <button :class="{ active: isRegister }" type="button" @click="setAuthMode('register')">Criar conta</button>
        </div>

        <form class="form-stack" @submit.prevent="authenticate">
          <label v-if="isRegister">
            Nome
            <input v-model.trim="authForm.name" autocomplete="name" required maxlength="255" placeholder="Seu nome" />
          </label>
          <label>
            E-mail
            <input v-model.trim="authForm.email" autocomplete="email" type="email" required placeholder="voce@email.com" />
          </label>
          <label>
            Senha
            <input v-model="authForm.password" :autocomplete="isRegister ? 'new-password' : 'current-password'" type="password" required minlength="8" placeholder="Mínimo de 8 caracteres" />
          </label>
          <label v-if="isRegister">
            Confirme a senha
            <input v-model="authForm.password_confirmation" autocomplete="new-password" type="password" required minlength="8" placeholder="Digite a senha novamente" />
          </label>
          <p v-if="pageError" class="alert error" role="alert">{{ pageError }}</p>
          <button class="button primary full-width" :disabled="authLoading" type="submit">
            {{ authLoading ? 'Aguarde...' : isRegister ? 'Criar minha conta' : 'Entrar na conta' }}
            <span aria-hidden="true">→</span>
          </button>
        </form>
        <p class="auth-note">Ao continuar, sua conta será protegida por autenticação individual.</p>
      </div>
    </section>
  </main>

  <main v-else class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="#top" aria-label="CreditFlow início">
        <span class="brand-mark">C</span>
        <span>credit<span class="brand-light">flow</span></span>
      </a>
      <div class="side-label">ESPAÇO FINANCEIRO</div>
      <a class="side-link active" href="#overview"><span>▦</span> Visão geral</a>
      <a class="side-link" href="#statement"><span>↕</span> Movimentações</a>
      <div class="sidebar-bottom">
        <div class="profile-avatar">{{ user?.name?.slice(0, 1)?.toUpperCase() || 'U' }}</div>
        <div class="profile-text"><strong>{{ user?.name || 'Minha conta' }}</strong><small>{{ user?.email }}</small></div>
        <button class="icon-button logout-button" type="button" title="Sair" aria-label="Sair" @click="logout()">↗</button>
      </div>
    </aside>

    <section id="top" class="main-content">
      <header class="topbar">
        <div>
          <span class="eyebrow">PAINEL DA CONTA</span>
          <h1>Olá, {{ user?.name?.split(' ')[0] || 'bem-vindo' }} <span class="wave">✳</span></h1>
        </div>
        <div class="topbar-right"><span class="status-dot"></span> Conta ativa <span class="topbar-date">{{ new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long' }).format(new Date()) }}</span></div>
      </header>

      <div v-if="pageError" class="alert error page-alert" role="alert">{{ pageError }}</div>
      <div v-if="pageSuccess" class="alert success page-alert" role="status">{{ pageSuccess }}</div>

      <section id="overview" class="overview-grid">
        <article class="balance-card">
          <div class="balance-heading"><span>Saldo disponível</span><span class="balance-icon">R$</span></div>
          <div class="balance-value">{{ formatMoney(balance) }}</div>
          <div class="balance-bottom"><span>Conta em reais</span><span class="balance-pill"><i></i> Atualizado</span></div>
          <div class="balance-decoration"></div>
        </article>
        <article class="stat-card">
          <div class="stat-icon mint">↕</div>
          <span class="stat-label">Movimentações</span>
          <strong>{{ transactionCount }}</strong>
          <small>lançamentos registrados</small>
        </article>
        <article class="stat-card">
          <div class="stat-icon peach">◷</div>
          <span class="stat-label">Moeda da conta</span>
          <strong>BRL</strong>
          <small>Real brasileiro</small>
        </article>
      </section>

      <section class="action-section">
        <div class="section-heading"><div><span class="eyebrow">MOVIMENTE SUA CONTA</span><h2>Novo lançamento</h2></div></div>
        <form class="transaction-form" @submit.prevent="submitTransaction">
          <label class="amount-field">
            Valor em centavos
            <div class="input-prefix"><span>¢</span><input v-model="amountForm.amount_cents" type="number" min="1" step="1" required inputmode="numeric" placeholder="Ex.: 1250" /></div>
            <small>1250 centavos = R$ 12,50</small>
          </label>
          <div class="action-buttons">
            <button class="button primary" :disabled="transactionLoading" type="submit" @click="amountForm.type = 'credit'">{{ transactionLoading && amountForm.type === 'credit' ? 'Aguarde...' : '＋ Registrar crédito' }}</button>
            <button class="button secondary" :disabled="transactionLoading" type="submit" @click="amountForm.type = 'debit'">{{ transactionLoading && amountForm.type === 'debit' ? 'Aguarde...' : '− Registrar débito' }}</button>
          </div>
        </form>
      </section>

      <section id="statement" class="statement-section">
        <div class="section-heading statement-heading"><div><span class="eyebrow">HISTÓRICO DA CONTA</span><h2>Movimentações recentes</h2></div><button class="text-button" type="button" :disabled="loading" @click="loadDashboard()">↻ Atualizar</button></div>
        <form class="filters" @submit.prevent="applyFilters">
          <label>Tipo<select v-model="filters.type"><option value="">Todos</option><option value="credit">Crédito</option><option value="debit">Débito</option><option value="reversal">Estorno</option></select></label>
          <label>De<input v-model="filters.date_from" type="date" /></label>
          <label>Até<input v-model="filters.date_to" type="date" /></label>
          <button class="button filter-button" type="submit">Filtrar</button>
          <button class="button clear-button" type="button" @click="filters.type = ''; filters.date_from = ''; filters.date_to = ''; applyFilters()">Limpar</button>
        </form>

        <div class="transaction-list">
          <div v-if="loading" class="empty-state">Carregando movimentações...</div>
          <div v-else-if="transactions.length === 0" class="empty-state"><span class="empty-icon">↕</span><strong>Nenhuma movimentação por aqui</strong><small>Seus créditos e débitos aparecerão nesta lista.</small></div>
          <template v-else>
            <article v-for="transaction in transactions" :key="transaction.id" class="transaction-row">
              <div class="movement-icon" :class="transaction.type">{{ transactionSymbol(transaction) }}</div>
              <div class="movement-info"><strong>{{ transactionLabel(transaction.type) }}</strong><small>{{ formatDate(transaction.created_at) }} <span>·</span> ID {{ transaction.id }}</small></div>
              <div class="movement-amount" :class="transaction.type">
                <strong>{{ transaction.type === 'debit' ? '−' : '+' }} {{ formatMoney(transaction.amount_cents) }}</strong>
                <small v-if="transaction.reversal_of_id">Estorno do lançamento #{{ transaction.reversal_of_id }}</small>
                <small v-else-if="transaction.reversed_by_id">Estornado no lançamento #{{ transaction.reversed_by_id }}</small>
                <small v-else>Concluído</small>
              </div>
              <button v-if="transaction.type !== 'reversal' && !transaction.reversed_by_id" class="reverse-button" type="button" @click="reverseTransaction(transaction)">Estornar</button>
              <span v-else class="completed-mark">✓</span>
            </article>
          </template>
        </div>

        <footer v-if="pagination && pagination.last_page > 1" class="pagination">
          <span>Página {{ pagination.current_page }} de {{ pagination.last_page }}</span>
          <div><button class="button page-button" type="button" :disabled="!pagination.prev_page_url || loading" @click="loadDashboard(page - 1)">← Anterior</button><button class="button page-button" type="button" :disabled="!pagination.next_page_url || loading" @click="loadDashboard(page + 1)">Próxima →</button></div>
        </footer>
      </section>
      <footer class="page-footer">CreditFlow <span>·</span> Gestão clara para decisões melhores.</footer>
    </section>
  </main>
</template>
