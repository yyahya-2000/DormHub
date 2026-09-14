import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import type { Room } from '@/api/generated/model'
import { PlaceSquares } from '@/components/housing/place-squares'
import { useFormatters } from '@/lib/format'
import { vacanciesOf } from '@/lib/rooms'
import { cn } from '@/lib/utils'

/**
 * The counted state of one room, drawn and then said.
 *
 * The header of a room page and the card in a grid answer the same question
 * with the same picture; only the detail below them differs. Two figures follow
 * the squares because two of them are independent — a blocked place is neither
 * taken nor free, and no single ratio can say so.
 */
export function RoomFigures({ room, className }: { room: Room; className?: string }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  return (
    <div className={cn('grid justify-items-start gap-1.5', className)}>
      <PlaceSquares room={room} />
      <p className="m-0 text-pretty text-steel">
        {t('housing.placesTaken', {
          taken: formatters.count(room.occupied_beds_count ?? 0),
          total: formatters.count(room.beds_count),
        })}
        {' · '}
        {t('housing.freeOf', {
          free: formatters.count(vacanciesOf(room)),
          total: formatters.count(room.beds_count),
        })}
      </p>
    </div>
  )
}

/**
 * One room in a grid. The whole card is the link to the room.
 *
 * The card is a picture and a sentence about the same thing. The squares are
 * the places, one each, coloured by state; the line underneath counts the free
 * ones in words. A room with nowhere to move anybody into is also a quieter
 * card — the ground drops to `paper` and the rule goes grey — so that a floor
 * of full rooms can be told from a floor of empty ones without reading a
 * single number.
 */
export function RoomCard({ room, to }: { room: Room; to: string }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const vacancies = vacanciesOf(room)
  const full = vacancies === 0

  return (
    <Link
      to={to}
      className={cn(
        'group grid h-full content-start gap-2 border px-3 py-3 transition-colors',
        full
          ? 'border-steel/40 bg-paper hover:border-prussian hover:bg-paper-raised'
          : 'border-rule bg-paper-raised hover:border-prussian hover:bg-prussian-wash/40',
      )}
    >
      <div className="flex flex-wrap items-baseline gap-x-3">
        <span className="font-mono text-xl font-semibold text-ink">{room.number}</span>
        {room.type !== undefined ? (
          <span className="text-steel">{t(`roomType.${room.type}`)}</span>
        ) : null}
      </div>

      <PlaceSquares room={room} />

      <p className="m-0 text-steel">
        {t('housing.freeOf', {
          free: formatters.count(vacancies),
          total: formatters.count(room.beds_count),
        })}
      </p>
    </Link>
  )
}
