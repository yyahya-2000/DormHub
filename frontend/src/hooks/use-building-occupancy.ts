import { useMemo } from 'react'
import { useQueries } from '@tanstack/react-query'

import {
  getShowResidentCardQueryOptions,
  useListBuildingUsers,
  type listBuildingUsersResponse,
  type showResidentCardResponse,
} from '@/api/generated/dormitory'
import { RoleCode, type User } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'

/**
 * Who holds which place in a building.
 *
 * FR-43 asks that selecting a room show who lives in it, and the contract
 * deliberately refuses to put that on the room: a place «carries no name», so
 * that a list of forty rooms cannot turn into a roster of forty residents
 * because a relation happened to be loaded. The name lives on the resident card
 * of FR-06, behind its own object-level policy, and the card is where this hook
 * goes to get it: the roll of the building says who is attached to it, each
 * card says which place that person holds, and the two are joined here on
 * `bed_id`.
 *
 * Two consequences, both intended.
 *
 * Every card read is written to the audit log as `resident.card_viewed`,
 * because it is a read of personal data and §3.9.6 records those. Resolving
 * occupancy is therefore an act, not a decoration, and the caller triggers it
 * by opening a room rather than by opening the page.
 *
 * The policy that decides the card decides this too. A warden of another
 * building is refused each card individually, so the plan of a building that is
 * not his shows him no names — which is the criterion, enforced where the
 * criterion says it should be, at the API.
 */

export type Occupant = {
  userId: number
  fullName: string
  /** The open residency holding the place — what FR-05's termination needs. */
  residencyId: number | null
  contractNumber: string | null
  movedInAt: string | null
}

export type BuildingOccupancy = {
  /** Everyone holding a role in the building, as the roll route returned them. */
  roll: User[]
  /** The residents among them — the candidates for a placement (FR-03). */
  residents: User[]
  byBed: Map<number, Occupant>
  /** Whether the cards were asked for at all — false when the role may not read them. */
  canResolve: boolean
  isRollPending: boolean
  rollError: unknown
  /** True while the cards are being read. */
  isResolving: boolean
  /** True once every card that could be read has been. */
  isResolved: boolean
  /** How many cards the server refused; their places stay unnamed. */
  refusedCards: number
}

function isResidentOf(person: User, buildingId: number): boolean {
  return (person.roles ?? []).some(
    (grant) => grant.role === RoleCode.student && grant.building_id === buildingId,
  )
}

export function useBuildingOccupancy(
  buildingId: number,
  options: { rollEnabled: boolean; resolveEnabled: boolean },
): BuildingOccupancy {
  const roll = useListBuildingUsers<listBuildingUsersResponse, ApiError>(buildingId, {
    query: {
      enabled: Number.isInteger(buildingId) && options.rollEnabled,
      retry: false,
    },
  })

  const people = roll.data?.status === 200 ? roll.data.data.data : null

  const residents = useMemo(
    () => (people ?? []).filter((person) => isResidentOf(person, buildingId)),
    [people, buildingId],
  )

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
      contractNumber: open?.contract_number ?? null,
      movedInAt: open?.moved_in_at ?? person.current_bed?.moved_in_at ?? null,
    })
  }

  return {
    roll: people ?? [],
    residents,
    byBed,
    canResolve: options.resolveEnabled,
    isRollPending: roll.isPending,
    rollError: roll.isError ? roll.error : null,
    isResolving: options.resolveEnabled && cards.some((card) => card.isPending),
    isResolved:
      options.resolveEnabled && cards.length > 0 && cards.every((card) => !card.isPending),
    refusedCards: cards.filter((card) => card.isError).length,
  }
}
