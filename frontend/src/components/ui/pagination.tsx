import { useEffect } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/**
 * The foot of a list: back, the position, forward. Two arrows and one number —
 * no page buttons, no «showing 21–40 of 137». A dormitory list is walked, not
 * navigated, and the arrows are what the walk needs.
 *
 * Nothing is drawn while there is a single page, so a screen can put this under
 * every list and short lists stay as they were.
 *
 * The arrows are icon buttons at 40 px, which is the touch target the rest of
 * the system keeps; the labels they carry are for assistive software, and the
 * number between them is the only visible text (NFR-10 keeps it at 16 px).
 */
export function Pagination({
  page,
  lastPage,
  onPageChange,
  disabled = false,
  className,
}: {
  page: number
  /** `meta.last_page` of the answer; use `lastPageOf` to read it safely. */
  lastPage: number
  onPageChange: (page: number) => void
  /** True while the list is being re-read, so the arrows cannot be run over. */
  disabled?: boolean
  className?: string
}) {
  const { t } = useTranslation()

  const total = lastPage > 1 ? Math.floor(lastPage) : 1
  const current = page < 1 ? 1 : page

  // A page can outlive its contents: delete the last request on page three and
  // the server answers with two pages and nothing to show. The row pulls the
  // caller back to the last page that exists instead of leaving a blank list.
  useEffect(() => {
    if (current > total) {
      onPageChange(total)
    }
  }, [current, total, onPageChange])

  if (total <= 1) {
    return null
  }

  const shown = current > total ? total : current

  return (
    <nav
      aria-label={t('common.pagination.label')}
      className={cn(
        'flex items-center justify-end gap-3 border-t border-rule px-4 py-2',
        className,
      )}
    >
      <Button
        type="button"
        variant="outline"
        size="icon-lg"
        aria-label={t('common.pagination.previous')}
        disabled={disabled || shown <= 1}
        onClick={() => {
          onPageChange(shown - 1)
        }}
      >
        <ChevronLeft aria-hidden="true" className="size-5" />
      </Button>
      <span aria-live="polite" className="text-steel tabular-nums">
        {t('common.pagination.position', { page: shown, total })}
      </span>
      <Button
        type="button"
        variant="outline"
        size="icon-lg"
        aria-label={t('common.pagination.next')}
        disabled={disabled || shown >= total}
        onClick={() => {
          onPageChange(shown + 1)
        }}
      >
        <ChevronRight aria-hidden="true" className="size-5" />
      </Button>
    </nav>
  )
}
