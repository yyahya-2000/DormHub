import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import {
  useShowGuestDocumentNumber,
  type showGuestDocumentNumberResponse,
} from '@/api/generated/dormitory'
import type { GuestRequest } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'
import { RequestRefusal } from '@/components/request-refusal'
import { Button } from '@/components/ui/button'

/**
 * NFR-06 and §3.9.6: the document number in full, on the one route that gives
 * it, with the reading recorded.
 *
 * **The warning comes before the number and not after it.** Every call writes
 * `guest_request.document_viewed` to the audit log with the name of whoever
 * asked, and a person who finds that out afterwards has been told too late to
 * decide anything. So the button says what pressing it does, and the panel
 * repeats it once the number is on the screen.
 *
 * **Why a button and not a field.** A field would be sent on every list and
 * every refresh, and the log would fill with readings nobody performed — the
 * record of who looked would then be worthless as evidence, which is the whole
 * reason it is kept. The contract makes the same choice on the wire, and this
 * is that choice on the screen.
 *
 * The control is drawn for the warden of the dormitory and the administrator.
 * The security post is deliberately outside that circle: at the desk the
 * document is in the officer's hand, and the last four characters are what a
 * comparison needs.
 */
export function DocumentNumberReveal({ request }: { request: GuestRequest }) {
  const { t } = useTranslation()
  const [asked, setAsked] = useState(false)

  const number = useShowGuestDocumentNumber<showGuestDocumentNumberResponse, ApiError>(
    request.id,
    {
      query: {
        enabled: asked,
        retry: false,
        // Each press is a reading and has to reach the server as one. A cached
        // answer returned quietly would be a number on the screen with no line
        // in the log behind it.
        staleTime: 0,
        gcTime: 0,
      },
    },
  )

  const shown = number.data?.status === 200 ? number.data.data.data : null

  if (!asked) {
    return (
      <div className="border border-rule bg-paper px-3 py-3">
        <p className="m-0 text-steel">{t('guestDocument.warning')}</p>
        <div className="mt-2">
          <Button type="button" variant="outline" size="sm" onClick={() => setAsked(true)}>
            {t('guestDocument.reveal')}
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="border-l-4 border-brass bg-brass-wash px-3 py-3">
      {number.isError ? <RequestRefusal error={number.error} /> : null}

      {number.isPending && !number.isError ? (
        <p className="m-0 text-steel">{t('common.loading')}…</p>
      ) : null}

      {shown !== null ? (
        <>
          <p className="label-caps m-0">
            {t(`guestDocumentType.${shown.guest_doc_type}`, {
              defaultValue: shown.guest_doc_type,
            })}
          </p>
          <p className="m-0 font-mono text-xl break-all text-ink">
            {shown.guest_doc_number}
          </p>
          <p className="mt-2 mb-0 text-steel">{t('guestDocument.recorded')}</p>
        </>
      ) : null}

      <div className="mt-2">
        <Button type="button" variant="outline" size="sm" onClick={() => setAsked(false)}>
          {t('guestDocument.hide')}
        </Button>
      </div>
    </div>
  )
}
