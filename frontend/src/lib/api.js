const TOKEN_KEY = 'creditflow.token'

export function getToken() {
  return window.localStorage.getItem(TOKEN_KEY)
}

export function saveToken(token) {
  window.localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken() {
  window.localStorage.removeItem(TOKEN_KEY)
}

export async function apiRequest(path, options = {}) {
  const headers = new Headers(options.headers || {})
  headers.set('Accept', 'application/json')

  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }

  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`/api${path}`, {
    ...options,
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  })
  const payload = response.status === 204 ? null : await response.json().catch(() => null)

  if (!response.ok) {
    const error = new Error(payload?.message || 'Nao foi possivel concluir a solicitacao.')
    error.status = response.status
    error.errors = payload?.errors || {}
    throw error
  }

  return payload
}
