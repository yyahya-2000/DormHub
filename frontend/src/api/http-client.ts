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
  /*
   * An image is not text, and reading it as text destroys it: the two
   * photograph routes answer with the file itself wherever the object store
   * cannot sign a URL, and `text()` over those bytes returns a string nothing
   * can turn back into a picture. The body is handed on as a `Blob` instead,
   * and the screen that asked for it makes an object URL out of it. Which of
   * the two shapes arrived is the content type's answer and nobody else's —
   * the same route answers JSON on a deployment whose store signs links.
   */
  const contentType = response.headers.get('Content-Type') ?? ''
  if (contentType.startsWith('image/')) {
    return await response.blob()
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

/**
 * The multipart fields the contract types as a list.
 *
 * There is one today — FR-36's `photos`, «up to three» — and the set is written
 * out rather than inferred, because inferring it is what would go wrong. The
 * lost-and-found module of the same contract sends a single `photo`, and a rule
 * of the form «any file part is a list» would wrap that one in brackets the
 * server does not expect.
 */
const MULTIPART_LIST_FIELDS = new Set(['photos'])

/**
 * A multipart body whose list fields are named the way the server parses them.
 *
 * The generated operation appends each element of an array under the same plain
 * name — `photos`, `photos` — which is correct HTML and is not what PHP reads:
 * `$_FILES` keeps the last part under a plain name and builds an array only
 * from `photos[]`. One photograph therefore arrived as a scalar and was refused
 * with «the photos field must be an array», and three arrived as one.
 *
 * The rename lives here, in the single exit point towards the API, for the same
 * reason the bearer token does: it is a property of the wire and of every
 * request that crosses it, not of the screen that happened to make this one.
 * `src/api/generated` is overwritten by `npm run api:generate` and is never
 * edited, so the correction could not live there in any case.
 */
function withListFieldNames(body: FormData): FormData {
  const corrected = new FormData()
  for (const [name, value] of body.entries()) {
    corrected.append(MULTIPART_LIST_FIELDS.has(name) ? `${name}[]` : name, value)
  }
  return corrected
}

export async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
  const token = readToken()
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  /*
   * A form is not JSON, and the difference is a header this function must not
   * write. FR-36 sends photographs, so the generated operation hands us a
   * `FormData`; the boundary that separates its parts is chosen by the browser
   * and is part of the content type. Setting `multipart/form-data` here without
   * the boundary — or, worse, `application/json` over a body that is not — makes
   * the server read an empty request and answer 422 about fields that were in
   * fact sent. Leaving the header off is what lets fetch write the right one.
   */
  const sendsAForm = options.body instanceof FormData
  if (options.body !== undefined && !sendsAForm && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }
  if (token !== null) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const body = sendsAForm
    ? withListFieldNames(options.body as FormData)
    : options.body

  const response = await fetch(`${API_BASE_URL}${url}`, { ...options, headers, body })

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
