import { BedStatus, type Room } from '@/api/generated/model'

/**
 * Reading the counted state of a room.
 *
 * `free_places` on the wire is the remainder the register would refuse a
 * further **place** on — `capacity` minus the places registered. It is not the
 * number of beds a resident could move into, and the two differ in the ordinary
 * case: a room of four registered places standing empty has a free remainder of
 * zero and four vacancies. Everything a card draws is the second question, so
 * it is counted here, from the places themselves where the answer carried them.
 */

/** One place as a card draws it: a label to name it and a state to colour it. */
export type Place = { key: string; label: string; status: string }

/**
 * The places of a room, in the order the register lists them.
 *
 * The room lists of a building arrive with their places loaded, and those are
 * used when they are there — `blocked` exists only in that answer and no
 * arithmetic recovers it. A room that arrived without them is reconstructed
 * from its three figures: right about how many, and never in contradiction
 * with the line of text beside it.
 */
export function placesOf(room: Room): Place[] {
  const beds = room.beds ?? []
  if (beds.length > 0) {
    return beds.map((bed) => ({ key: String(bed.id), label: bed.label, status: bed.status }))
  }

  const occupied = room.occupied_beds_count ?? 0
  const free = room.vacant_beds

  return Array.from({ length: room.beds_count }, (_, index) => ({
    key: `n${index}`,
    label: String(index + 1),
    status:
      index < occupied
        ? BedStatus.occupied
        : index < occupied + free
          ? BedStatus.free
          : BedStatus.blocked,
  }))
}

/** How many places of this room somebody could be moved into today. */
export function vacanciesOf(room: Room): number {
  const beds = room.beds ?? []
  if (beds.length > 0) {
    return beds.filter((bed) => bed.status === BedStatus.free).length
  }
  return room.vacant_beds
}
