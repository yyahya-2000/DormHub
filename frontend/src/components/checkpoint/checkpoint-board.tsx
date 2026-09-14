import { useTranslation } from 'react-i18next'

import { Panel } from '@/components/panel'
import { Button } from '@/components/ui/button'
import type { BoardEntry } from '@/lib/checkpoint-board'
import { useFormatters } from '@/lib/format'

/**
 * Who this terminal has written in, and by when they are due out.
 *
 * The rows this terminal recorded during this shift, kept in the tab and
 * nowhere else. The contract gives the post four calls and no list — §3.9.6
 * puts the register of a dormitory in the warden's and the administrator's
 * hands, not the desk's. It is the notebook beside the journal.
 */
export function CheckpointBoard({
  entries,
  onCheckOut,
  onDismiss,
  leavingVisitId,
}: {
  entries: BoardEntry[]
  onCheckOut: (entry: BoardEntry) => void
  onDismiss: (entry: BoardEntry) => void
  leavingVisitId: number | null
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const open = entries.filter((entry) => entry.checkedOutAt === null)
  const closed = entries.filter((entry) => entry.checkedOutAt !== null)

  return (
    <Panel
      caption={t('checkpoint.board.heading')}
      aside={t('checkpoint.board.count', { count: open.length })}
    >
      {open.length === 0 ? (
        <p className="px-4 py-6 text-lg text-steel">{t('checkpoint.board.empty')}</p>
      ) : (
        <ul className="m-0 list-none p-0">
          {open.map((entry) => {
            return (
              <li
                key={entry.visitId}
                className="grid gap-2 border-b border-rule/70 px-4 py-4 last:border-b-0"
              >
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                  <p className="m-0 min-w-0 text-xl font-semibold break-words text-ink">
                    {entry.guestName}
                  </p>
                  {entry.room !== null ? (
                    <p className="m-0 text-lg text-steel">
                      {t('checkpoint.board.room', { room: entry.room })}
                    </p>
                  ) : null}
                </div>

                <p className="m-0 text-lg text-ink">
                  {t('checkpoint.board.entered', {
                    time: formatters.time(entry.checkedInAt),
                  })}{' '}
                  ·{' '}
                  {t('checkpoint.board.dueAt', {
                    time: formatters.time(entry.dueAt),
                  })}
                </p>

                <div className="grid gap-2 sm:grid-cols-2">
                  <Button
                    type="button"
                    size="lg"
                    className="h-auto min-h-14 min-w-0 text-lg whitespace-normal"
                    disabled={leavingVisitId === entry.visitId}
                    onClick={() => onCheckOut(entry)}
                  >
                    {leavingVisitId === entry.visitId
                      ? `${t('common.saving')}…`
                      : t('checkpoint.exit.action')}
                  </Button>
                  <Button
                    type="button"
                    size="lg"
                    variant="outline"
                    className="h-auto min-h-14 min-w-0 text-lg whitespace-normal"
                    onClick={() => onDismiss(entry)}
                  >
                    {t('checkpoint.board.dismiss')}
                  </Button>
                </div>
              </li>
            )
          })}
        </ul>
      )}

      {closed.length > 0 ? (
        <div className="border-t border-rule px-4 py-3">
          <p className="label-caps m-0">{t('checkpoint.board.closedHeading')}</p>
          <ul className="m-0 mt-1 list-none p-0">
            {closed.map((entry) => (
              <li key={entry.visitId} className="text-steel">
                {entry.guestName} ·{' '}
                {t('checkpoint.board.left', {
                  time: formatters.time(entry.checkedOutAt),
                })}
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </Panel>
  )
}
