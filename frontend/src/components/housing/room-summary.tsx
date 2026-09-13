import { useTranslation } from 'react-i18next'

import { RoomStatus, type Room } from '@/api/generated/model'
import { useFormatters } from '@/lib/format'
import { vacanciesOf } from '@/lib/rooms'

/**
 * The counted state of one room, said in full.
 *
 * Four figures arrive with a room and three of them are easy to confuse, so all
 * three are named rather than summarised. `capacity` is the ceiling the register
 * checks against; `beds_count` is how many places actually exist;
 * `occupied_beds_count` is how many are held. `free_places` is the fourth and is
 * the remainder the register would refuse a further **place** on — not the
 * number of beds a resident could move into, which is why vacancies are counted
 * separately, from the state of the places themselves.
 */
export function RoomSummary({ room }: { room: Room }) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  const vacancies = vacanciesOf(room)

  return (
    <div className="grid gap-1">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h3 className="m-0 font-mono text-xl font-semibold text-ink">{room.number}</h3>
        <span className="text-steel">{t('rooms.floor', { floor: room.floor })}</span>
        {room.type !== undefined ? (
          <span className="text-steel">{t(`roomType.${room.type}`)}</span>
        ) : null}
        {room.status !== undefined ? (
          <span
            className={
              room.status === RoomStatus.in_service
                ? 'border border-prussian/25 bg-prussian-wash px-2 py-0.5 text-prussian'
                : 'border border-brick/40 bg-brick-wash px-2 py-0.5 text-brick'
            }
          >
            {t(`roomStatus.${room.status}`)}
          </span>
        ) : null}
      </div>

      <p className="m-0">
        {t('rooms.occupancy', {
          occupied: formatters.count(room.occupied_beds_count ?? 0),
          capacity: formatters.count(room.capacity),
        })}
      </p>
      <p className="m-0 text-steel">
        {t('rooms.places', {
          beds: formatters.count(room.beds_count),
          capacity: formatters.count(room.capacity),
        })}
        {' · '}
        {t('rooms.vacancies', { count: vacancies })}
        {' · '}
        {t('rooms.remainder', { count: room.free_places })}
      </p>
    </div>
  )
}
