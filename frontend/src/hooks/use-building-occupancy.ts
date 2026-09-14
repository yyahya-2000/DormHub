import { useQueries } from '@tanstack/react-query'

import {
  getShowResidentCardQueryOptions,
  useListBuildingUsers,
  type listBuildingUsersResponse,
  type showResidentCardResponse,
} from '@/api/generated/dormitory'
import { RoleCode } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'

/**
 * Who holds which place in a building.
 *
 * The contract deliberately refuses to put a name on a place, so that a list of
 * forty rooms cannot turn into a roster of forty residents. The name lives on
 * the resident card of FR-06, behind its own object-level policy: the roll of
 * the building says who is attached to it, each card says which place that
 * person holds, and the two are joined here on `bed_id`.
 *
 * The policy that decides the card decides this too. A warden of another
 * building is refused each card individually, so the rooms of a building that
 * is not his show him no names — the criterion enforced at the API.
 */

export type Occupant = {
  userId: number
  fullName: string
  /** The open residency holding the place — what FR-05's termination needs. */
  residencyId: number | null
  movedInAt: string | null
}

export type BuildingOccupancy = {
  byBed: Map<number, Occupant>
  /** Whether the cards were asked for at all — false when the role may not read them. */
  canResolve: boolean
  rollError: unknown
  /** True while the cards are being read. */
  isResolving: boolean
}

/** One page of the roll is enough for a dormitory; the ceiling is the API's. */
const ROLL_PER_PAGE = 100

export function useBuildingOccupancy(
  buildingId: number,
  options: { rollEnabled: boolean; resolveEnabled: boolean },
): BuildingOccupancy {
  const roll = useListBuildingUsers<listBuildingUsersResponse, ApiError>(
    buildingId,
    { role: RoleCode.student, per_page: ROLL_PER_PAGE },
    {
      query: {
        enabled: Number.isInteger(buildingId) && options.rollEnabled,
        retry: false,
      },
    },
  )

  const residents = roll.data?.status === 200 ? roll.data.data.data : []

  const cards = useQueries({
    queries: residents.map((person) =>
      getShowResidentCardQueryOptions<showResidentCardResponse, ApiError>(person.id, {
        query: {
          enabled: options.resolveEnabled,
          retry: false,
          // A card is personal data and every read of one is logged, so the
          // answer is kept for the whole visit rather than re-fetched when a
          // second room is opened.
          staleTime: 5 * 60_000,
        },
      }),
    ),
  })

  // Built on every render rather than memoised: `useQueries` hands back a fresh
  // array each time, so a dependency on it would memoise nothing while looking
  // as though it did. The map holds one entry per occupied place.
  const byBed = new Map<number, Occupant>()
  for (const card of cards) {
    const response = card.data
    if (response === undefined || response.status !== 200) {
      continue
    }
    const person = response.data.data
    const bedId = person.current_bed?.bed_id
    if (bedId === null || bedId === undefined) {
      continue
    }
    const open = person.residency_history.find(
      (residency) => residency.is_open && residency.bed_id === bedId,
    )
    byBed.set(bedId, {
      userId: person.id,
      fullName: person.full_name,
      residencyId: open?.id ?? null,
      movedInAt: open?.moved_in_at ?? person.current_bed?.moved_in_at ?? null,
    })
  }

  return {
    byBed,
    canResolve: options.resolveEnabled,
    rollError: roll.isError ? roll.error : null,
    isResolving: options.resolveEnabled && cards.some((card) => card.isPending),
  }
}
