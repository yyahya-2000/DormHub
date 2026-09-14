import { useTranslation } from 'react-i18next'

import type { MaintenanceWorkLogEntry } from '@/api/generated/model'
import { useFormatters } from '@/lib/format'

/**
 * The history of one request, drawn as what it is: an append-only journal
 * (§3.4.1, decision 6; §4.4.4).
 *
 * **Why a feed of events and not a table.** Each row is a moment with an author,
 * and the thing the reader is looking for is a sentence — the reason a refusal
 * was given, the comment left at completion. A table would put that sentence in
 * a cell sized by the shortest column and would suggest the rows can be sorted,
 * edited or deleted. None of them can: nothing in this application writes to a
 * row of this journal after it exists.
 *
 * **Two absences are drawn rather than filled.** A null `from_status` means the
 * request came into being rather than moved, so the first entry reads as a
 * filing and not as a transition out of nowhere. A null `actor_id` means nobody
 * decided — the scheduled closure of FR-39 — and `by_the_scheduler` says so
 * plainly, so no reader is handed an author the record deliberately does not
 * have.
 */
export function WorkLog({ entries }: { entries: MaintenanceWorkLogEntry[] }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  if (entries.length === 0) {
    return <p className="px-4 py-6 text-steel">{t('maintenance.log.empty')}</p>
  }

  return (
    <ol className="m-0 list-none p-0">
      {entries.map((entry) => {
        const opened = entry.from_status === null || entry.from_status === undefined
        const author = entry.by_the_scheduler
          ? t('maintenance.log.scheduler')
          : (entry.actor_name ?? t('maintenance.log.unnamedActor'))

        return (
          <li
            key={entry.id}
            className="grid gap-1 border-b border-rule/70 px-4 py-3 last:border-b-0"
          >
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <p className="m-0 min-w-0 font-medium break-words text-ink">
                {opened
                  ? t('maintenance.log.filed')
                  : t('maintenance.log.moved', {
                      from: t(`maintenanceStatus.${entry.from_status}`, {
                        defaultValue: entry.from_status ?? '',
                      }),
                      to: t(`maintenanceStatus.${entry.to_status}`, {
                        defaultValue: entry.to_status,
                      }),
                    })}
              </p>
              <time className="text-steel" dateTime={entry.recorded_at}>
                {formatters.dateTime(entry.recorded_at)}
              </time>
            </div>

            <p className="m-0 text-steel">{author}</p>

            {/*
              The comment is where FR-37's «rejection without a reason is
              impossible» actually lands: the reason is the comment of the
              work-log row the refusal writes, and there is no field on the
              request to disagree with it. So it is set apart rather than run
              into the line above.
            */}
            {entry.comment !== null &&
            entry.comment !== undefined &&
            entry.comment !== '' ? (
              <p className="m-0 border-l-4 border-rule bg-paper px-3 py-2 break-words text-ink">
                {entry.comment}
              </p>
            ) : null}

            {/*
              `summary` is deliberately not drawn. It is the server's own
              restatement of the move — «Filed as Submitted», «Accepted with a
              target date» — written in English by the service layer, and the
              line above already says the same thing in the reader's language
              from `from_status` and `to_status`. Printing both would put an
              English sentence under a Russian one and would say nothing the
              transition and the author do not. What the server holds and the
              client cannot derive is the comment, and that is what is shown.
            */}
          </li>
        )
      })}
    </ol>
  )
}
