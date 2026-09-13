/**
 * Where the API token lives between page loads.
 *
 * Sanctum issues a plain-text token that is never retrievable again, so it has
 * to be kept somewhere on the client. sessionStorage rather than localStorage:
 * the token dies with the browser tab, which matters on the shared workstation
 * at the security post where one screen is used by a changing shift.
 */

const TOKEN_KEY = 'dormitory.token'

export function readToken(): string | null {
  try {
    return window.sessionStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function rememberToken(token: string): void {
  try {
    window.sessionStorage.setItem(TOKEN_KEY, token)
  } catch {
    // A browser with storage disabled keeps the session in memory only, which
    // is a degraded but working session: the token still rides on every request
    // until the tab is reloaded.
  }
}

export function forgetToken(): void {
  try {
    window.sessionStorage.removeItem(TOKEN_KEY)
  } catch {
    // Nothing to clean up when the store was never writable.
  }
}
