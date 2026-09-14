import { useTranslation } from 'react-i18next'

import type { Room } from '@/api/generated/model'
import { placesOf } from '@/lib/rooms'
import { cn } from '@/lib/utils'

/**
 * Occupancy drawn as squares, one square per thing counted.
 *
 * A ratio answers «how full», a row of squares answers «how many, and which».
 * Both are printed, because they are different questions: «2 of 4 free» is the
 * figure the register checks against, and only the squares show that the fourth
 * place is blocked rather than taken.
 *
 * Nothing is distinguished by colour alone. Every square carries its state in
 * words, as a tooltip for a mouse and as `sr-only` text for a screen reader,
 * and the line under each row says the same thing in figures. There is no
 * legend: a legend is a second thing to read, and the words are already here.
 */

/** The tone of one place. Light is free, brass is held, grey is closed. */
const PLACE_SQUARE: Record<string, string> = {
  free: 'border-prussian/50 bg-paper-raised',
  occupied: 'border-brass/60 bg-brass',
  blocked: 'border-steel/60 bg-steel/40',
}

/** More squares than this on one card stops being a picture and becomes noise. */
const SQUARE_LIMIT = 48

/** One row of squares, one per registered place, coloured by the state of it. */
export function PlaceSquares({ room, className }: { room: Room; className?: string }) {
  const { t } = useTranslation()
  const places = placesOf(room)

  if (places.length === 0) {
    return <p className={cn('m-0 text-steel', className)}>{t('places.none')}</p>
  }

  return (
    <div className={cn('flex flex-wrap gap-1', className)}>
      {places.slice(0, SQUARE_LIMIT).map((place) => {
        const said = t('places.square', {
          label: place.label,
          state: t(`bedStatus.${place.status}`),
        })
        return (
          <span
            key={place.key}
            title={said}
            className={cn(
              'size-4 shrink-0 border transition-colors',
              PLACE_SQUARE[place.status] ?? PLACE_SQUARE.blocked,
            )}
          >
            <span className="sr-only">{said}</span>
          </span>
        )
      })}
      {places.length > SQUARE_LIMIT ? (
        <span className="text-steel tabular-nums">
          {t('housing.andMore', { more: places.length - SQUARE_LIMIT })}
        </span>
      ) : null}
    </div>
  )
}

/**
 * The same row one level up: a square for every room of a floor, dark where the
 * room is full. The language is the language of the places inside a room — the
 * light square is the one somebody could be moved into — so a floor card and a
 * room card can be read with the same glance.
 */
export function RoomSquares({
  total,
  free,
  className,
}: {
  total: number
  free: number
  className?: string
}) {
  const { t } = useTranslation()

  if (total === 0) {
    return null
  }

  const shown = Math.min(total, SQUARE_LIMIT)
  const fullCount = Math.max(total - free, 0)

  return (
    <div className={cn('flex flex-wrap gap-1', className)}>
      {Array.from({ length: shown }, (_, index) => {
        const isFull = index < fullCount
        const said = isFull ? t('housing.roomFull') : t('housing.roomHasFree')
        return (
          <span
            key={index}
            title={said}
            className={cn(
              'size-3 shrink-0 border transition-colors',
              isFull
                ? 'border-prussian bg-prussian'
                : 'border-prussian/40 bg-prussian-wash',
            )}
          >
            <span className="sr-only">{said}</span>
          </span>
        )
      })}
      {total > shown ? (
        <span className="text-steel tabular-nums">
          {t('housing.andMore', { more: total - shown })}
        </span>
      ) : null}
    </div>
  )
}
