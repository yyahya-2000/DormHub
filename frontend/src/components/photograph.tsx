import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useLostFoundPhotograph,
  useMaintenancePhotograph,
  type Photograph as PhotographLink,
} from '@/hooks/use-photograph'

/** The ceiling FR-36 states and the server enforces; a longer list is not drawn. */
const MAX_PHOTOGRAPHS = 3

/**
 * The photographs attached to a maintenance request, as a row of thumbnails.
 *
 * One request per thumbnail, each made by the thumbnail itself: the link is
 * temporary, so it is asked for when the picture is about to be drawn and not
 * when the record is read. Mounting a component per index rather than calling
 * the hook in a loop is what lets the number of photographs change — a card
 * read before the record arrives has none — without the order of hooks
 * changing under React.
 */
export function MaintenancePhotographs({
  requestId,
  count,
  subject,
}: {
  requestId: number
  count: number
  subject: string
}) {
  const indexes = Array.from({ length: Math.min(count, MAX_PHOTOGRAPHS) }, (_, at) => at)

  return (
    <ul className="m-0 flex list-none flex-wrap gap-3 p-0">
      {indexes.map((index) => (
        <li key={index} className="min-w-0">
          <MaintenancePhotograph
            requestId={requestId}
            index={index}
            subject={subject}
            number={index + 1}
          />
        </li>
      ))}
    </ul>
  )
}

function MaintenancePhotograph({
  requestId,
  index,
  subject,
  number,
}: {
  requestId: number
  index: number
  subject: string
  number: number
}) {
  const { t } = useTranslation()
  const photograph = useMaintenancePhotograph(requestId, index)

  return (
    <Plate
      photograph={photograph}
      alt={t('maintenance.photos.alt', { number, subject })}
    />
  )
}

/** The single photograph of an entry of the bureau — FR-24. */
export function LostFoundPhotograph({ itemId, title }: { itemId: number; title: string }) {
  const { t } = useTranslation()
  const photograph = useLostFoundPhotograph(itemId)

  return (
    <Plate photograph={photograph} alt={t('lostFound.photograph.alt', { title })} />
  )
}

/**
 * One photograph at the size a card can hold, and the same photograph at its
 * own size when it is pressed.
 *
 * A thumbnail of a broken tap decides nothing; the warden who has to promise a
 * date needs the picture whole. `<dialog>` is what carries it, because the
 * behaviour that matters here — the Escape key, the focus that stays inside,
 * the rest of the page inert behind it — is the element's own and not
 * something to be rebuilt out of a div.
 *
 * A link that never arrives draws nothing at all, and neither does one that
 * arrives dead — a refusal and an address whose signature has expired between
 * the answer and the drawing look the same to the reader of a card, and a
 * warning in the place of a picture is noise on a screen that already says how
 * the request is going.
 */
function Plate({ photograph, alt }: { photograph: PhotographLink; alt: string }) {
  const { t } = useTranslation()
  const full = useRef<HTMLDialogElement>(null)
  const [broken, setBroken] = useState(false)

  if (photograph.isPending) {
    return <Skeleton className="h-32 w-44" />
  }

  if (photograph.url === null || broken) {
    return null
  }

  return (
    <>
      <button
        type="button"
        className="block cursor-pointer border border-rule bg-paper p-1"
        onClick={() => full.current?.showModal()}
      >
        <img
          src={photograph.url}
          alt={alt}
          className="block h-32 w-44 object-cover"
          onError={() => setBroken(true)}
        />
      </button>

      <dialog
        ref={full}
        className="m-auto border border-rule bg-paper-raised p-0 backdrop:bg-ink/70"
      >
        <div className="grid gap-3 p-3">
          <img
            src={photograph.url}
            alt={alt}
            className="block max-h-[70vh] max-w-[80vw] object-contain"
          />
          <Button
            type="button"
            variant="outline"
            className="h-auto min-h-11 min-w-0 justify-self-start whitespace-normal"
            onClick={() => full.current?.close()}
          >
            {t('common.close')}
          </Button>
        </div>
      </dialog>
    </>
  )
}
