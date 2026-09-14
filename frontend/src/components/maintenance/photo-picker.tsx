import { useEffect, useMemo, useRef } from 'react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/ui/button'
import { useFormatters } from '@/lib/format'

/**
 * FR-36's photographs: up to three, chosen on a telephone, shown before they
 * are sent.
 *
 * **The ceiling is repeated here and enforced elsewhere.** Three is the route's
 * validation, a 422 naming `photos`, and a CHECK constraint on the table. This
 * component keeps the reader from filling in a form that will be refused; it is
 * not what makes the rule true, and a fourth photograph arriving by some other
 * path is still refused by the server.
 *
 * **The previews are local and cost nothing.** Each file gets an object URL,
 * which is a handle on bytes already in the browser — no upload happens until
 * the form is submitted. They are revoked when the selection changes, because a
 * resident retaking a photograph three times on a telephone would otherwise
 * leave three images pinned in memory.
 *
 * **What comes back cannot be shown the same way.** `photo_paths` on a stored
 * request carries paths into the object store rather than URLs, and this
 * iteration has no route that exchanges one for a link — so a filed request
 * states how many photographs it carries and does not pretend to display them.
 */
export function PhotoPicker({
  id,
  value,
  max,
  onChange,
}: {
  id: string
  value: File[]
  max: number
  onChange: (files: File[]) => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const input = useRef<HTMLInputElement>(null)

  const previews = useMemo(
    () => value.map((file) => ({ file, url: URL.createObjectURL(file) })),
    [value],
  )

  useEffect(
    () => () => {
      for (const preview of previews) {
        URL.revokeObjectURL(preview.url)
      }
    },
    [previews],
  )

  const room = max - value.length

  function add(files: FileList | null) {
    if (files === null) {
      return
    }
    onChange([...value, ...[...files].slice(0, Math.max(0, room))])
    /*
     * The native control keeps the last selection, so choosing the same file
     * twice would fire no change event and the second attempt would look
     * broken. Clearing it after every read makes the control a source of
     * events rather than a second copy of the state.
     */
    if (input.current !== null) {
      input.current.value = ''
    }
  }

  return (
    <div className="grid gap-3">
      <input
        ref={input}
        id={id}
        type="file"
        className="w-full border border-rule bg-paper-raised px-3 py-2 text-base text-ink file:mr-3 file:border file:border-rule file:bg-paper file:px-3 file:py-1 file:text-base file:text-ink"
        accept="image/jpeg,image/png,image/webp"
        multiple
        disabled={room <= 0}
        onChange={(event) => add(event.target.files)}
      />

      <p className="m-0 text-steel">
        {room > 0
          ? t('maintenance.photos.remaining', { count: room })
          : t('maintenance.photos.full', { count: max })}
      </p>

      {previews.length > 0 ? (
        <ul className="m-0 grid list-none gap-3 p-0 sm:grid-cols-3">
          {previews.map((preview, index) => (
            <li
              key={preview.url}
              className="grid gap-2 border border-rule bg-paper p-2"
            >
              <img
                src={preview.url}
                alt={t('maintenance.photos.previewAlt', { number: index + 1 })}
                className="block h-32 w-full object-cover"
              />
              <p className="m-0 min-w-0 break-all text-steel">
                {preview.file.name} ·{' '}
                {t('maintenance.photos.kilobytes', {
                  size: formatters.count(Math.max(1, Math.round(preview.file.size / 1024))),
                })}
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-auto min-h-11 w-full min-w-0 whitespace-normal"
                onClick={() => onChange(value.filter((_, at) => at !== index))}
              >
                {t('maintenance.photos.remove')}
              </Button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}
