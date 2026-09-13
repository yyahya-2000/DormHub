import { BedStatus, RoomStatus, type Room } from '@/api/generated/model'

/**
 * Reading the counted state of a room.
 *
 * `free_places` on the wire is the remainder the register would refuse a
 * further **place** on — `capacity` minus the places registered. It is not the
 * number of beds a resident could move into, and the two differ in the ordinary
 * case: a room of four registered places standing empty has a free remainder of
 * zero and four vacancies. The register sends the first; the second is counted
 * here from the states of the places themselves, which the same answer carries.
 */
export function vacanciesOf(room: Room): number {
  if (room.status !== undefined && room.status !== RoomStatus.in_service) {
    return 0
  }
  return (room.beds ?? []).filter((bed) => bed.status === BedStatus.free).length
}
