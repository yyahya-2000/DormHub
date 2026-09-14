import { cloneElement, isValidElement, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

/**
 * One field of a form: caption above, control below, an optional note under it.
 *
 * Stacking rather than placing the caption beside the control is what makes the
 * forms of this slice survive 360 px (NFR-11) without a single media query. The
 * control is passed in, so the same wrapper serves an input, a native select
 * and a date field alike.
 *
 * A field that must be filled says so with a red asterisk and with nothing
 * else. The asterisk is a convention every filled-in form in the country uses,
 * so it needs no sentence explaining it, and it is `aria-hidden` for the same
 * reason a sentence would be: what a screen reader announces is `aria-required`
 * on the control itself, which this wrapper sets.
 */
export function FormField({
  id,
  label,
  required = false,
  note,
  children,
  className,
}: {
  id: string
  label: string
  /** Draws the asterisk and marks the control `aria-required`. */
  required?: boolean
  note?: string
  children: ReactNode
  className?: string
}) {
  const { t } = useTranslation()

  // The control is somebody else's element, so the attribute is put on it by
  // cloning. A caller that passes a fragment or several elements gets the
  // asterisk and sets `aria-required` itself — there is no single control to
  // put it on, and guessing would put it on the wrong one.
  const control =
    required && isValidElement<{ 'aria-required'?: boolean | 'true' | 'false' }>(children)
      ? cloneElement(children, {
          'aria-required': children.props['aria-required'] ?? true,
        })
      : children

  return (
    <div className={cn('grid min-w-0 gap-1', className)}>
      <Label htmlFor={id} className="label-caps">
        <span>
          {label}
          {required ? (
            <span aria-hidden="true" title={t('common.requiredField')} className="ml-1 text-brick">
              *
            </span>
          ) : null}
        </span>
      </Label>
      {control}
      {note !== undefined ? <p className="m-0 text-steel">{note}</p> : null}
    </div>
  )
}

/**
 * The native select, dressed to match `ui/input.tsx`. A native control rather
 * than a scripted one: the security post works on whatever browser is on the
 * shared machine, and the list of room types is not worth a popover.
 *
 * `min-w-0` is load bearing. A select is sized by its widest option, and the
 * widest option here is a whole Russian sentence — without it the form sets the
 * width of the page instead of the other way round.
 */
export const selectClassName =
  'h-11 w-full min-w-0 border border-rule bg-paper-raised px-3 text-base text-ink outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50'
