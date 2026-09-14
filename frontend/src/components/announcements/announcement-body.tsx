import { useId, useState } from 'react'
import { ChevronDown, ChevronUp } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/ui/button'
import { BODY_FOLD_LENGTH } from '@/lib/announcements'
import { cn } from '@/lib/utils'

/**
 * The text of a notice, folded to four lines while it is long.
 *
 * A dormitory announcement is as often two sentences as two screens — the
 * schedule of a water shutoff runs to a page — and a feed that prints both in
 * full stops being a feed. The fold is the ordinary one: four lines, a button
 * under them, and the whole text on the other side of it.
 *
 * The clamp is drawn by CSS and not by cutting the string, so the text a
 * screen reader and a search of the page find is the whole text whether the
 * card is open or not; `aria-expanded` is what states which of the two the eye
 * is being shown.
 */
export function AnnouncementBody({ body }: { body: string }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const bodyId = useId()

  const folds = body.length > BODY_FOLD_LENGTH || body.split('\n').length > 5

  return (
    <div className="grid min-w-0 gap-2">
      <p
        id={bodyId}
        className={cn(
          'm-0 min-w-0 break-words whitespace-pre-line text-ink',
          folds && !open ? 'line-clamp-4' : null,
        )}
      >
        {body}
      </p>

      {folds ? (
        <div>
          <Button
            type="button"
            variant="link"
            className="h-auto min-h-11 min-w-0 px-0 whitespace-normal"
            aria-expanded={open}
            aria-controls={bodyId}
            onClick={() => setOpen((current) => !current)}
          >
            {open ? t('announcements.collapse') : t('announcements.readMore')}
            {open ? (
              <ChevronUp aria-hidden="true" className="size-5" />
            ) : (
              <ChevronDown aria-hidden="true" className="size-5" />
            )}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
