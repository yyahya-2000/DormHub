import { useQueries } from '@tanstack/react-query'

import {
  getListBuildingUsersQueryOptions,
  type listBuildingUsersResponse,
} from '@/api/generated/dormitory'
import { RoleCode, type User } from '@/api/generated/model'
import type { ApiError } from '@/api/http-client'

/**
 * The staff of one dormitory, by post.
 *
 * FR-41 puts a role on a person and not a person in a post, so «who is the duty
 * officer here» is a question the roll route answers with a filter: one request
 * per post, `role=...`, in the order the chain of appointment runs. The
 * grouping is then the requests themselves, which is why nothing here sorts.
 *
 * The resident role is deliberately absent: a resident is not staff, and the
 * residents of a building are read from the housing screens.
 */

/** The four posts, from the one the administrator appoints downwards. */
export const STAFF_ROLES = [
  RoleCode.warden,
  RoleCode.manager,
  RoleCode.duty_officer,
  RoleCode.security,
] as const

export type StaffRole = (typeof STAFF_ROLES)[number]

export type StaffGroup = {
  role: StaffRole
  people: User[]
}

export type BuildingStaff = {
  groups: StaffGroup[]
  total: number
  isPending: boolean
  error: unknown
}

/** A post is held by a handful of people; one page is the whole post. */
const PER_PAGE = 100

export function useBuildingStaff(buildingId: number, enabled: boolean): BuildingStaff {
  const queries = useQueries({
    queries: STAFF_ROLES.map((role) =>
      getListBuildingUsersQueryOptions<listBuildingUsersResponse, ApiError>(
        buildingId,
        { role, per_page: PER_PAGE },
        {
          query: {
            enabled: Number.isInteger(buildingId) && enabled,
            retry: false,
          },
        },
      ),
    ),
  })

  const groups: StaffGroup[] = []
  let total = 0
  for (const [index, query] of queries.entries()) {
    const response = query.data
    const people = response !== undefined && response.status === 200 ? response.data.data : []
    total += people.length
    if (people.length > 0) {
      groups.push({ role: STAFF_ROLES[index], people })
    }
  }

  return {
    groups,
    total,
    isPending: enabled && queries.some((query) => query.isPending),
    error: queries.find((query) => query.isError)?.error ?? null,
  }
}
