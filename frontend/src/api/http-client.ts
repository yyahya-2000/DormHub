import { readToken, forgetToken } from '@/auth/token-storage'

/**
 * The single exit point of the presentation layer towards the API (§3.3.1).
 *
 * Every generated operation is routed through this function, so the three
 * things that must hold for all of them hold in one place: the base path of the
 * versioned API, the bearer token of the current session, and the reaction to a
 * token the server no longer accepts.
 */

export const API_BASE_URL = '/api/v1'

/** A refusal the API described in the contract, carried with its status and body. */
export class ApiError<TBody = unknown> extends Error {
  readonly status: number
  readonly body: TBody
  readonly retryAfterHeader: number | null

  constructor(status: number, body: TBody, retryAfterHeader: number | null) {
    super(`API responded with ${status}`)
    this.name = 'ApiError'
    this.status = status
    this.body = body
    this.retryAfterHeader = retryAfterHeader
  }
}

/** Fired when the API rejects the stored token; the router sends the person back to sign-in. */
export const SESSION_EXPIRED_EVENT = 'dormitory:session-expired'

function announceSessionExpiry(): void {
  forgetToken()
  window.dispatchEvent(new CustomEvent(SESSION_EXPIRED_EVENT))
}

function isSignInRequest(url: string): boolean {
  return url.includes('/auth/login')
}

function parseRetryAfter(response: Response): number | null {
  const raw = response.headers.get('Retry-After')
  if (raw === null) {
    return null
  }
  const seconds = Number.parseInt(raw, 10)
  return Number.isFinite(seconds) ? seconds : null
}

async function readBody(response: Response): Promise<unknown> {
  if (response.status === 204) {
    return null
  }
  const text = await response.text()
  if (text.length === 0) {
    return null
  }
  try {
    return JSON.parse(text) as unknown
  } catch {
    return text
  }
}

/**
 * The envelope the generated code is typed against: the parsed body, the status
 * it arrived with and the headers. The status is what the pages discriminate on,
 * so a 200 body and a 403 body never get confused for one another.
 */
export type ApiResponse<TData = unknown> = {
  data: TData
  status: number
  headers: Headers
}

export async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
  const token = readToken()
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body !== undefined && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }
  if (token !== null) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`${API_BASE_URL}${url}`, { ...options, headers })

  if (!response.ok) {
    // A 401 on sign-in means the credential was wrong and belongs to the form.
    // A 401 anywhere else means the token is gone, and the session ends.
    if (response.status === 401 && !isSignInRequest(url)) {
      announceSessionExpiry()
    }
    throw new ApiError(response.status, await readBody(response), parseRetryAfter(response))
  }

  const envelope: ApiResponse = {
    data: await readBody(response),
    status: response.status,
    headers: response.headers,
  }

  return envelope as T
}

export default apiFetch
