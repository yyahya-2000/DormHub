import { useEffect, useState, type FormEvent } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useSetPassword } from '@/api/generated/dormitory'
import { ApiError } from '@/api/http-client'
import { useSession } from '@/auth/session-context'
import { FormField } from '@/components/form-field'
import { LanguageSwitch } from '@/components/language-switch'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { fieldMessages } from '@/lib/api-refusal'
import { useFormatters } from '@/lib/format'

/**
 * FR-42, the other end of the issue: the resident sets a password of their own.
 *
 * The screen sits outside the session, of necessity — the account has no
 * password to sign in with yet, which is the whole point of the one-time code.
 * It is the only screen besides sign-in that an anonymous browser may reach.
 *
 * The refusal the contract describes says nothing about which of four causes it
 * was: no such address, a code that never existed, one already spent, one
 * expired. That vagueness is deliberate on the server — a one-time code is a
 * secret, and a message that distinguished «no such address» from «wrong code»
 * would answer a question nobody signed in asked. So the screen states all four
 * at once rather than guessing at one, and adds the way out: ask the warden for
 * a new code. Anything narrower would be an invention.
 *
 * A mail link carries the address and the code in the query string, so both
 * fields are prefilled from it when they are there. They stay editable: a code
 * read off a paper slip at the desk is the same code.
 */

type Refusal =
  | { kind: 'fields'; messages: { field: string; messages: string[] }[] }
  | { kind: 'refused' }
  | { kind: 'throttled'; until: number | null }
  | { kind: 'network' }
  | { kind: 'unexpected'; status: number }

function refusalFrom(error: unknown): Refusal {
  if (!(error instanceof ApiError)) {
    return { kind: 'network' }
  }
  if (error.status === 422) {
    const fields = fieldMessages(error)
    // A 422 with `errors` is the payload failing validation and names the
    // field. A 422 without them is the code not resolving, and that one is
    // deliberately unattributable.
    return fields.length > 0 ? { kind: 'fields', messages: fields } : { kind: 'refused' }
  }
  if (error.status === 429) {
    const header = error.retryAfterHeader
    return { kind: 'throttled', until: header === null ? null : Date.now() + header * 1000 }
  }
  return { kind: 'unexpected', status: error.status }
}

export function SetPasswordPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const { session } = useSession()
  const [params] = useSearchParams()

  const [email, setEmail] = useState(() => params.get('email') ?? '')
  const [code, setCode] = useState(() => params.get('token') ?? '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [refusal, setRefusal] = useState<Refusal | null>(null)
  const [mismatch, setMismatch] = useState(false)
  const [done, setDone] = useState(false)
  const [now, setNow] = useState(() => Date.now())

  const setPasswordCall = useSetPassword<ApiError>({
    mutation: {
      onSuccess: (response) => {
        if (response.status === 204) {
          setRefusal(null)
          setDone(true)
        }
      },
      onError: (error) => {
        setNow(Date.now())
        setRefusal(refusalFrom(error))
      },
    },
  })

  const waitUntil = refusal?.kind === 'throttled' ? refusal.until : null

  useEffect(() => {
    if (waitUntil === null) {
      return
    }
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [waitUntil])

  const secondsLeft =
    waitUntil === null ? 0 : Math.max(0, Math.ceil((waitUntil - now) / 1000))

  // Someone already holding a session has no one-time code to spend.
  if (session.status === 'authenticated') {
    return <Navigate to="/" replace />
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (setPasswordCall.isPending) {
      return
    }
    if (password !== confirmation) {
      // Caught here rather than sent: the server would answer the same thing,
      // but the round trip would also spend a request against the limiter of a
      // route that counts guesses.
      setRefusal(null)
      setMismatch(true)
      return
    }
    setMismatch(false)
    setPasswordCall.mutate({
      data: {
        email: email.trim(),
        token: code.trim(),
        password,
        password_confirmation: confirmation,
      },
    })
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
            <h1 className="text-2xl font-semibold text-ink">{t('setPassword.title')}</h1>
            <p className="mt-1 text-steel">{t('setPassword.lead')}</p>
          </div>

          {done ? (
            <div className="grid gap-4 px-5 py-6">
              <div
                className="border-l-4 border-prussian bg-prussian-wash px-4 py-3 text-ink"
                role="status"
              >
                <p className="m-0 font-semibold">{t('setPassword.doneTitle')}</p>
                <p className="mt-1 mb-0">{t('setPassword.doneBody')}</p>
              </div>
              <div>
                <Button asChild size="lg">
                  <Link to="/login">{t('setPassword.toLogin')}</Link>
                </Button>
              </div>
            </div>
          ) : (
            <form className="grid gap-5 px-5 py-6" onSubmit={submit} noValidate>
              {refusal !== null || mismatch ? (
                <div
                  className="border-l-4 border-brick bg-brick-wash px-4 py-3 text-ink"
                  role="alert"
                  aria-live="assertive"
                >
                  {mismatch ? (
                    <>
                      <p className="m-0 font-semibold">{t('setPassword.mismatchTitle')}</p>
                      <p className="mt-1 mb-0">{t('setPassword.mismatchBody')}</p>
                    </>
                  ) : null}

                  {refusal?.kind === 'refused' ? (
                    <>
                      <p className="m-0 font-semibold">{t('setPassword.refusedTitle')}</p>
                      <p className="mt-1 mb-0">{t('setPassword.refusedBody')}</p>
                      <ul className="mt-1 mb-0 list-disc pl-5">
                        <li>{t('setPassword.refusedCause1')}</li>
                        <li>{t('setPassword.refusedCause2')}</li>
                        <li>{t('setPassword.refusedCause3')}</li>
                        <li>{t('setPassword.refusedCause4')}</li>
                      </ul>
                      <p className="mt-1 mb-0">{t('setPassword.refusedWayOut')}</p>
                    </>
                  ) : null}

                  {refusal?.kind === 'fields' ? (
                    <>
                      <p className="m-0 font-semibold">{t('setPassword.validationTitle')}</p>
                      <ul className="mt-1 mb-0 list-disc pl-5">
                        {refusal.messages.map((entry) => (
                          <li key={entry.field}>
                            <span className="font-medium">
                              {t(`setPassword.fields.${entry.field}`, {
                                defaultValue: entry.field,
                              })}
                            </span>
                            {': '}
                            {entry.messages.join(' ')}
                          </li>
                        ))}
                      </ul>
                    </>
                  ) : null}

                  {refusal?.kind === 'throttled' ? (
                    <>
                      <p className="m-0 font-semibold">{t('login.throttledTitle')}</p>
                      <p className="mt-1 mb-0">
                        {secondsLeft > 0
                          ? t('setPassword.throttledBody', {
                              remaining: formatters.duration(secondsLeft),
                            })
                          : t('setPassword.throttledBodySoon')}
                      </p>
                    </>
                  ) : null}

                  {refusal?.kind === 'network' ? <p className="m-0">{t('errors.network')}</p> : null}

                  {refusal?.kind === 'unexpected' ? (
                    <p className="m-0">{t('errors.unexpected', { status: refusal.status })}</p>
                  ) : null}
                </div>
              ) : null}

              <FormField id="sp-email" label={t('setPassword.email')}>
                <Input
                  id="sp-email"
                  type="email"
                  inputMode="email"
                  autoComplete="username"
                  value={email}
                  maxLength={255}
                  required
                  onChange={(event) => setEmail(event.target.value)}
                />
              </FormField>

              <FormField id="sp-code" label={t('setPassword.code')} note={t('setPassword.codeNote')}>
                <Input
                  id="sp-code"
                  value={code}
                  autoComplete="one-time-code"
                  spellCheck={false}
                  className="font-mono"
                  required
                  onChange={(event) => setCode(event.target.value)}
                />
              </FormField>

              <FormField
                id="sp-password"
                label={t('setPassword.password')}
                note={t('setPassword.passwordNote')}
              >
                <Input
                  id="sp-password"
                  type="password"
                  autoComplete="new-password"
                  minLength={8}
                  value={password}
                  required
                  onChange={(event) => {
                    setMismatch(false)
                    setPassword(event.target.value)
                  }}
                />
              </FormField>

              <FormField id="sp-confirm" label={t('setPassword.confirmation')}>
                <Input
                  id="sp-confirm"
                  type="password"
                  autoComplete="new-password"
                  minLength={8}
                  value={confirmation}
                  required
                  onChange={(event) => {
                    setMismatch(false)
                    setConfirmation(event.target.value)
                  }}
                />
              </FormField>

              <Button type="submit" size="lg" disabled={setPasswordCall.isPending}>
                {setPasswordCall.isPending
                  ? `${t('setPassword.submitting')}…`
                  : t('setPassword.submit')}
              </Button>

              <p className="m-0 text-steel">{t('setPassword.note')}</p>

              <p className="m-0">
                <Link className="text-prussian underline" to="/login">
                  {t('setPassword.backToLogin')}
                </Link>
              </p>
            </form>
          )}
        </div>
      </main>
    </div>
  )
}
