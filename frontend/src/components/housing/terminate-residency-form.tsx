import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { useTerminateResidency } from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'
import { FormField } from '@/components/form-field'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { todayIso } from '@/lib/format'
import { useHousingRefresh } from '@/lib/housing-cache'

/**
 * FR-05, moving a resident out.
 *
 * The row is not deleted. A ground and a date are written, the place is free
 * from the moment the termination is recorded, and the residency stays on the
 * card of FR-06 as history.
 */
export function TerminateResidencyForm({
  residencyId,
  title,
  onDone,
}: {
  residencyId: number | null
  title: string
  onDone: () => void
}) {
  const { t } = useTranslation()
  const refresh = useHousingRefresh()
  const [ground, setGround] = useState('')
  const [movedOutAt, setMovedOutAt] = useState(todayIso)
  const terminate = useTerminateResidency<ApiError>()

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (residencyId === null) {
      return
    }
    terminate.mutate(
      {
        residency: residencyId,
        data: { ground: ground.trim(), moved_out_at: movedOutAt },
      },
      {
        onSuccess: () => {
          refresh()
          onDone()
        },
      },
    )
  }

  const id = `terminate-${residencyId ?? 'unknown'}`

  return (
    <form className="grid gap-3 border border-rule bg-paper px-3 py-3" onSubmit={submit}>
      <h4 className="label-caps m-0">{title}</h4>

      {terminate.isError ? <RequestRefusal error={terminate.error} /> : null}

      {residencyId === null ? (
        <p className="m-0 text-steel">{t('residency.unknownRecord')}</p>
      ) : null}

      <FormField id={`${id}-ground`} label={t('fields.ground')} required>
        <Input
          id={`${id}-ground`}
          value={ground}
          minLength={3}
          maxLength={255}
          required
          onChange={(event) => setGround(event.target.value)}
        />
      </FormField>

      <FormField id={`${id}-date`} label={t('fields.moved_out_at')} required>
        <Input
          id={`${id}-date`}
          type="date"
          value={movedOutAt}
          required
          onChange={(event) => setMovedOutAt(event.target.value)}
        />
      </FormField>

      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={terminate.isPending || residencyId === null}>
          {terminate.isPending ? `${t('common.saving')}…` : t('residency.release')}
        </Button>
        <Button type="button" variant="outline" onClick={onDone}>
          {t('common.cancel')}
        </Button>
      </div>
    </form>
  )
}
