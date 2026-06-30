// Thin fetch wrapper for the Laravel API.
//
// Reads the base URL from `import.meta.env.VITE_API_BASE_URL` (set in `.env`).
// Attaches the Sanctum bearer token from the auth store when present.
// Throws `ApiError` (with parsed body) on non-2xx responses so callers can
// `try/catch` for validation errors.

const RAW_BASE = import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api/v1'

// Strip trailing slash so `joinUrl('/foo')` doesn't produce `//foo`.
const API_BASE_URL: string = RAW_BASE.replace(/\/+$/, '')

const TOKEN_KEY = 'insurehub.token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token === null) {
    localStorage.removeItem(TOKEN_KEY)
  } else {
    localStorage.setItem(TOKEN_KEY, token)
  }
}

export interface ApiErrorBody {
  message: string
  errors?: Record<string, string[]>
}

export class ApiError extends Error {
  readonly status: number
  readonly body: ApiErrorBody | null

  constructor(status: number, body: ApiErrorBody | null) {
    super(body?.message || `HTTP ${status}`)
    this.status = status
    this.body = body
  }
}

function joinUrl(path: string): string {
  if (path.startsWith('http://') || path.startsWith('https://')) {
    return path
  }
  return `${API_BASE_URL}/${path.replace(/^\/+/, '')}`
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (body !== undefined && !(body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(joinUrl(path), {
    ...init,
    method,
    headers,
    body:
      body === undefined
        ? undefined
        : body instanceof FormData
          ? body
          : JSON.stringify(body),
  })

  // 204 No Content → no body to parse.
  if (response.status === 204) {
    return undefined as T
  }

  // Try to parse JSON; tolerate empty body.
  const text = await response.text()
  const parsed = text.length > 0 ? (JSON.parse(text) as unknown) : null

  if (!response.ok) {
    const errBody =
      parsed && typeof parsed === 'object'
        ? (parsed as ApiErrorBody)
        : null
    if (response.status === 401) {
      // Token expired/invalid — clear so we don't keep sending it.
      setToken(null)
    }
    throw new ApiError(response.status, errBody)
  }

  return parsed as T
}

export const api = {
  get: <T>(path: string, init?: RequestInit) => request<T>('GET', path, undefined, init),
  post: <T>(path: string, body?: unknown, init?: RequestInit) =>
    request<T>('POST', path, body, init),
  patch: <T>(path: string, body?: unknown, init?: RequestInit) =>
    request<T>('PATCH', path, body, init),
  put: <T>(path: string, body?: unknown, init?: RequestInit) =>
    request<T>('PUT', path, body, init),
  delete: <T>(path: string, init?: RequestInit) =>
    request<T>('DELETE', path, undefined, init),
}

/** Shape of paginated list responses from the Laravel API. */
export interface Paginated<T> {
  data: T[]
  links?: unknown
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

/** Shape of single-record responses from the Laravel API. */
export interface Single<T> {
  data: T
}

/** Build a `?a=b&c=d` query string from a record, skipping undefined/null values. */
export function buildQuery(params: Record<string, unknown> | undefined): string {
  if (!params) return ''
  const usp = new URLSearchParams()
  for (const [key, val] of Object.entries(params)) {
    if (val === undefined || val === null || val === '') continue
    if (Array.isArray(val)) {
      for (const item of val) usp.append(`${key}[]`, String(item))
    } else if (typeof val === 'boolean') {
      usp.set(key, val ? '1' : '0')
    } else {
      usp.set(key, String(val))
    }
  }
  const qs = usp.toString()
  return qs.length > 0 ? `?${qs}` : ''
}
