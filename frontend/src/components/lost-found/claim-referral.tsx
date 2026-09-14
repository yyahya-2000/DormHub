import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { useReferLostFoundClaim } from '@/api/generated/dormitory'
import type { Notification } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { FormField, selectClassName } from '@/components/form-field'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'
import { flagOf, numberOf } from '@/lib/notifications'
import { useLostFoundRefresh } from '@/lib/lost-found-cache'

/** FR-26's last clause: «a claim the two sides cannot settle is referred to the warden, who decides». */
export function ClaimReferral({ notification }: { notification: Notification }) {
  const { t } = useTranslation()
  const refresh = useLostFoundRefresh()

  const payload = notification.payload
  const claimId = numberOf(payload, 'lost_found_claim_id')
  const itemId = numberOf(payload, 'lost_found_item_id')
  const offered = flagOf(payload, 'referral_offered') === true

  const [note, setNote] = useState('')
  const [open, setOpen] = useState(false)
  const [referred, setReferred] = useState(false)
  const refer = useReferLostFoundClaim<ApiError>()

  if (itemId === null && claimId === null) {
    return null
  }

  return (
    <div className="grid gap-2">
      {itemId === null ? null : (
        <p className="m-0">
          <Link className="font-medium text-prussian underline" to={`/lost-found/${itemId}`}>
            {t('lostFound.openEntry')}
          </Link>
        </p>
      )}

      {!offered || claimId === null ? null : referred ? (
        <p className="m-0 border-l-4 border-prussian bg-prussian-wash px-3 py-2 text-ink">
          {t('lostFound.referral.done')}
        </p>
      ) : (
        <div className="grid gap-2 border-l-4 border-brass bg-brass-wash px-3 py-3">
          <p className="m-0 text-ink">{t('lostFound.referral.offer')}</p>

          {refer.isError ? (
            <RequestRefusal error={refer.error} vocabulary="lostFound" />
          ) : null}

          {open ? (
            <>
              <FormField
                id={`lost-found-referral-${notification.id}`}
                label={t('lostFound.referral.note')}
              >
                <textarea
                  id={`lost-found-referral-${notification.id}`}
                  className={`${selectClassName} h-24 py-2`}
                  value={note}
                  maxLength={1000}
                  onChange={(event) => setNote(event.target.value)}
                />
              </FormField>
              <div className="grid gap-2 sm:grid-cols-2">
                <Button
                  type="button"
                  size="lg"
                  className="h-auto min-h-12 min-w-0 whitespace-normal"
                  disabled={refer.isPending}
                  onClick={() =>
                    refer.mutate(
                      {
                        lostFoundClaim: claimId,
                        data: note.trim() === '' ? {} : { note: note.trim() },
                      },
                      {
                        onSuccess: (response) => {
                          if (response.status !== 200) {
                            return
                          }
                          setReferred(true)
                          refresh()
                        },
                      },
                    )
                  }
                >
                  {refer.isPending ? `${t('common.saving')}…` : t('lostFound.referral.send')}
                </Button>
                <Button
                  type="button"
                  size="lg"
                  variant="outline"
                  className="h-auto min-h-12 min-w-0 whitespace-normal"
                  onClick={() => setOpen(false)}
                >
                  {t('common.cancel')}
                </Button>
              </div>
            </>
          ) : (
            <div>
              <Button
                type="button"
                variant="outline"
                className="h-auto min-h-11 min-w-0 whitespace-normal"
                onClick={() => setOpen(true)}
              >
                {t('lostFound.referral.action')}
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
