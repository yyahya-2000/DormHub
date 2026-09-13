import type { ReactNode } from 'react'

import { cn } from '@/lib/utils'

/**
 * The one container of the system: a ruled panel with a caption strip, closer
 * to a form printed on paper than to a card. Square corners, a single hairline
 * border, no shadow.
 */
export function Panel({
  caption,
  aside,
  children,
  className,
}: {
  caption: string
  aside?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <section className={cn('border border-rule bg-paper-raised', className)}>
      <header className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-rule bg-prussian-wash px-4 py-3">
        <h2 className="label-caps">{caption}</h2>
        {aside !== undefined ? <div className="text-steel">{aside}</div> : null}
      </header>
      {children}
    </section>
  )
}

/** One label-and-value row of a record, stacked on a narrow screen. */
export function FieldRow({
  label,
  children,
  note,
}: {
  label: string
  children: ReactNode
  note?: string
}) {
  return (
    <div className="grid gap-x-6 gap-y-1 border-b border-rule/70 px-4 py-3 last:border-b-0 sm:grid-cols-[14rem_1fr]">
      <dt className="label-caps pt-0.5">{label}</dt>
      <dd className="m-0">
        <div className="text-ink">{children}</div>
        {note !== undefined ? <p className="mt-1 text-steel">{note}</p> : null}
      </dd>
    </div>
  )
}
