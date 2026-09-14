import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { useChangePassword } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { useSession } from '@/auth/session-context'
import { FormField } from '@/components/form-field'
import { LanguageSwitch } from '@/components/language-switch'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

/**
 * FR-42, the other end of the issue: the resident replaces the password the
 * office printed for them.
 *
 * The route is behind the token — `current_password` is checked against the
 * signed-in account's own hash, so a stolen token is not enough to take an
 * account over — and it revokes every token of the account, the one that made
 * the call included. There is nothing to do afterwards but sign in again, and
 * the screen does not pretend otherwise: on 204 the session is dropped and the
 * guard carries the person to the sign-in form.
 */
export function ChangePasswordPage() {
  const { t } = useTranslation()
  const { signOut } = useSession()

  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [mismatch, setMismatch] = useState(false)

  const change = useChangePassword<ApiError>()

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (change.isPending) {
      return
    }
    if (password !== confirmation) {
      setMismatch(true)
      return
    }
    setMismatch(false)
    change.mutate(
      {
        data: {
          current_password: current,
          password,
          password_confirmation: confirmation,
        },
      },
      {
        onSuccess: (response) => {
          if (response.status === 204) {
            signOut()
          }
        },
      },
    )
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
            <h1 className="text-2xl font-semibold text-ink">
              {t('changePassword.title')}
            </h1>
          </div>

          <form className="grid gap-5 px-5 py-6" onSubmit={submit} noValidate>
            {change.isError ? <RequestRefusal error={change.error} /> : null}

            {mismatch ? (
              <div
                className="border-l-4 border-brick bg-brick-wash px-4 py-3 text-ink"
                role="alert"
                aria-live="assertive"
              >
                {t('changePassword.mismatch')}
              </div>
            ) : null}

            <FormField id="cp-current" label={t('changePassword.current')} required>
              <Input
                id="cp-current"
                type="password"
                autoComplete="current-password"
                value={current}
                required
                onChange={(event) => setCurrent(event.target.value)}
              />
            </FormField>

            <FormField id="cp-password" label={t('changePassword.password')} required>
              <Input
                id="cp-password"
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

            <FormField id="cp-confirm" label={t('changePassword.confirmation')} required>
              <Input
                id="cp-confirm"
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

            <Button type="submit" size="lg" disabled={change.isPending}>
              {change.isPending ? `${t('common.saving')}…` : t('changePassword.submit')}
            </Button>
          </form>
        </div>
      </main>
    </div>
  )
}
