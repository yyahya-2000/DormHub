import {
  useListAnnouncementCategories,
  type listAnnouncementCategoriesResponse,
} from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'

/** One ready-made category: the code that is stored, the name that is shown. */
export type AnnouncementCategoryOption = {
  value: string
  label: string
}

/**
 * The ready-made announcement categories, named by the server.
 *
 * The same arrangement as `GET /citizenships`: the list the field offers comes
 * from the source the rule validates against, so a category added to the
 * server appears in the filter and in the publication form without a release
 * of this client. Unlike citizenships the list is not closed — the form lets a
 * category be typed — so an empty answer is not an error here, only a form with
 * nothing pre-filled.
 */
export function useAnnouncementCategories(enabled = true): AnnouncementCategoryOption[] {
  const list = useListAnnouncementCategories<listAnnouncementCategoriesResponse, ApiError>({
    query: { enabled, retry: false },
  })

  return list.data?.status === 200 ? list.data.data.data : []
}
