import type { ReactNode } from 'react'

import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

/**
 * One field of a form: caption above, control below, an optional note under it.
 *
 * Stacking rather than placing the caption beside the control is what makes the
 * forms of this slice survive 360 px (NFR-11) without a single media query. The
 * control is passed in, so the same wrapper serves an input, a native select
 * and a date field alike.
 */
export function FormField({
  id,
  label,
  note,
  children,
  className,
}: {
  id: string
  label: string
  note?: string
  children: ReactNode
  className?: string
}) {
  return (
    <div className={cn('grid min-w-0 gap-1', className)}>
      <Label htmlFor={id} className="label-caps">
        {label}
      </Label>
      {children}
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
