import { useEffect, useState, type FormEvent } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useLogin } from '@/api/generated/dormitory'
import type {
  InvalidCredentials,
  LoginLocked,
  ValidationError,
} from '@/api/generated/model'
import { ApiError } from '@/api/http-client'
import { useSession } from '@/auth/session-context'
import { LanguageSwitch } from '@/components/language-switch'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useFormatters } from '@/lib/format'

/**
 * FR-08, the entrance to the system.
 *
 * The contract gives sign-in two distinct refusals and this screen keeps them
 * distinct. 401 means the credential was wrong and carries how many attempts
 * remain; 429 means the login is inside its block window and carries the
 * seconds left. Collapsing the second one into a general failure would be the
 * worst possible message: the person would keep typing a password that is in
 * fact correct, for fifteen minutes, with nothing on screen to explain it.
 */

type Refusal =
  | { kind: 'invalid'; attemptsLeft: number }
  | { kind: 'locked'; until: number }
  | { kind: 'validation'; fields: string[] }
  | { kind: 'network' }
  | { kind: 'unexpected'; status: number }

function refusalFrom(error: unknown): Refusal {
  if (!(error instanceof ApiError)) {
    return { kind: 'network' }
  }
  if (error.status === 401) {
    const body = error.body as InvalidCredentials | null
    return { kind: 'invalid', attemptsLeft: body?.attempts_left ?? 0 }
  }
  if (error.status === 429) {
    const body = error.body as LoginLocked | null
    const seconds = body?.retry_after ?? error.retryAfterHeader ?? 0
    return { kind: 'locked', until: Date.now() + seconds * 1000 }
  }
  if (error.status === 422) {
    const body = error.body as ValidationError | null
    return { kind: 'validation', fields: Object.keys(body?.errors ?? {}) }
  }
  return { kind: 'unexpected', status: error.status }
}

export function LoginPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session, signIn, wasSignedOutByServer } = useSession()
  const location = useLocation()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [refusal, setRefusal] = useState<Refusal | null>(null)
  // The clock the countdown is measured against; moved by the timer below and
  // by the answer that started the block, never during rendering.
  const [now, setNow] = useState(() => Date.now())

  const login = useLogin<ApiError>({
    mutation: {
      onSuccess: (response) => {
        if (response.status === 200) {
          setRefusal(null)
          signIn(response.data.data.token)
        }
      },
      onError: (error) => {
        setNow(Date.now())
        setRefusal(refusalFrom(error))
      },
    },
  })

  // The block is a wait, so it is shown as one: the remaining time ticks down
  // in front of the person instead of being stated once and going stale.
  useEffect(() => {
    if (refusal?.kind !== 'locked') {
      return
    }
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [refusal])

  const secondsLeft =
    refusal?.kind === 'locked' ? Math.max(0, Math.ceil((refusal.until - now) / 1000)) : 0

  if (session.status === 'authenticated') {
    const from = (location.state as { from?: string } | null)?.from
    return <Navigate to={from ?? '/'} replace />
  }

  const isLocked = refusal?.kind === 'locked' && secondsLeft > 0

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (isLocked || login.isPending) {
      return
    }
    login.mutate({ data: { email, password } })
  }

  return (
    <div className="flex min-h-dvh flex-col bg-paper">
      <header className="bg-prussian text-white">
        <div className="mx-auto flex w-full max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <p className="font-semibold tracking-wide">{t('app.fullName')}</p>
          <LanguageSwitch />
        </div>
        <div className="h-1 bg-brass" />
      </header>

      <main className="mx-auto w-full max-w-3xl grow px-4 py-10">
        <div className="max-w-xl border border-rule bg-paper-raised">
          <div className="border-b border-rule px-5 py-4">
            <h1 className="text-2xl font-semibold text-ink">{t('login.title')}</h1>
            <p className="mt-1 text-steel">{t('login.lead')}</p>
          </div>

          <form className="grid gap-5 px-5 py-6" onSubmit={submit} noValidate>
            {wasSignedOutByServer ? (
              <p
                className="border-l-4 border-brass bg-brass-wash px-4 py-3 text-ink"
                role="status"
              >
                {t('login.sessionExpired')}
              </p>
            ) : null}

            {refusal !== null ? (
              <div
                className="border-l-4 border-brick bg-brick-wash px-4 py-3 text-ink"
                role="alert"
                aria-live="assertive"
              >
                {refusal.kind === 'invalid' ? (
                  <>
                    <p className="font-semibold">{t('login.invalid')}</p>
                    <p className="mt-1">
                      {refusal.attemptsLeft > 0
                        ? t('login.attemptsLeft', { count: refusal.attemptsLeft })
                        : t('login.lastAttemptUsed')}
                    </p>
                  </>
                ) : null}

                {refusal.kind === 'locked' ? (
                  <>
                    <p className="font-semibold">{t('login.lockedTitle')}</p>
                    <p className="mt-1">
                      {secondsLeft > 0
                        ? t('login.lockedBody', {
                            remaining: formatters.duration(secondsLeft),
                          })
                        : t('login.lockedOver')}
                    </p>
                  </>
                ) : null}

                {refusal.kind === 'validation' ? (
                  <>
                    <p className="font-semibold">{t('login.validationTitle')}</p>
                    <ul className="mt-1 list-disc pl-5">
                      {refusal.fields.map((field) => (
                        <li key={field}>
                          {field === 'email' || field === 'password'
                            ? t(`login.validation.${field}`)
                            : t('login.validation.other', { field })}
                        </li>
                      ))}
                    </ul>
                  </>
                ) : null}

                {refusal.kind === 'network' ? <p>{t('errors.network')}</p> : null}

                {refusal.kind === 'unexpected' ? (
                  <p>{t('errors.unexpected', { status: refusal.status })}</p>
                ) : null}
              </div>
            ) : null}

            <div className="grid gap-2">
              <Label htmlFor="email" className="label-caps">
                {t('login.email')}
              </Label>
              <Input
                id="email"
                name="email"
                type="email"
                autoComplete="username"
                inputMode="email"
                required
                value={email}
                onChange={(event) => {
                  // The block belongs to a login, not to the workstation. When
                  // the email changes, the refusal shown for the previous one
                  // stops applying — otherwise the next person on the shift
                  // would inherit a colleague's fifteen minutes.
                  setRefusal(null)
                  setEmail(event.target.value)
                }}
              />
            </div>

            <div className="grid gap-2">
              <Label htmlFor="password" className="label-caps">
                {t('login.password')}
              </Label>
              <Input
                id="password"
                name="password"
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />
            </div>

            <Button type="submit" size="lg" disabled={isLocked || login.isPending}>
              {login.isPending ? `${t('login.submitting')}…` : t('login.submit')}
            </Button>

            <p className="text-steel">{t('login.note')}</p>
          </form>
        </div>
      </main>
    </div>
  )
}
