import { useEffect, useMemo } from 'react'

import {
  useShowLostFoundItemPhoto,
  useShowMaintenanceRequestPhoto,
  type showLostFoundItemPhotoResponse,
  type showMaintenanceRequestPhotoResponse,
} from '@/api/generated/dormitory'
import type { ApiError } from '@/api/http-client'

/** A photograph on its way to the screen: the link, and whether it is still coming. */
export type Photograph = {
  /** The address the browser may load, or null while there is none to load. */
  url: string | null
  /** True while the answer is on its way and nothing can be drawn yet. */
  isPending: boolean
}

/**
 * The link to one stored photograph, asked for at the moment it is drawn.
 *
 * **Why a request per photograph and not a field on the record.** What the
 * object store hands out is a temporary address, and a temporary address
 * embedded in a list is stale by the time somebody scrolls to it — which is why
 * `photo_paths` on a maintenance request carries paths and not URLs. The route
 * exchanges a path for a link under the same rule that governs reading the
 * request itself, so a photograph is never more readable than the card it hangs
 * on.
 *
 * **Two shapes of answer, told apart by the content type.** A deployment whose
 * store signs URLs answers with one, and the browser then fetches the image
 * from the store; a stand running on a local disk signs nothing and sends the
 * file. The cards must not know which kind of stand they are on, so both come
 * back from here as a `url` — in the second case an object URL over the bytes,
 * released when the card that asked for it goes away.
 *
 * **Why both routes live in this one file.** The cards call the two functions
 * below and never the generated hooks; `src/api/generated` is rewritten by
 * `npm run api:generate`, and an operation the contract renames then costs two
 * lines here instead of an edit in every card. The announcement categories are
 * arranged the same way and for the same reason.
 *
 * A refusal is not news a card can act on: the link never arrives, `url` stays
 * null, and nothing is drawn where the photograph would have been. `retry:
 * false` keeps a 403 from being asked three more times.
 */
export function useMaintenancePhotograph(requestId: number, index: number): Photograph {
  const query = useShowMaintenanceRequestPhoto<
    showMaintenanceRequestPhotoResponse,
    ApiError
  >(requestId, index, {
    query: { enabled: Number.isInteger(requestId), retry: false },
  })

  return usePhotographOf(
    query.data?.status === 200 ? query.data.data : null,
    query.isPending && !query.isError,
  )
}

/** The link to the single photograph of an entry of the bureau — FR-24. */
export function useLostFoundPhotograph(itemId: number): Photograph {
  const query = useShowLostFoundItemPhoto<showLostFoundItemPhotoResponse, ApiError>(
    itemId,
    { query: { enabled: Number.isInteger(itemId), retry: false } },
  )

  return usePhotographOf(
    query.data?.status === 200 ? query.data.data : null,
    query.isPending && !query.isError,
  )
}

/**
 * One answer of a photograph route, as something an `<img>` can be pointed at.
 *
 * The JSON body is read rather than destructured, for the reason the
 * notification payloads are read that way: a signed link is the whole of what
 * this client wants out of it, and an answer shaped differently should leave
 * the card without a photograph rather than without a screen.
 */
function usePhotographOf(body: unknown, isPending: boolean): Photograph {
  const file = body instanceof Blob ? body : null

  /*
   * An object URL is a handle on memory the tab holds until it is revoked, so
   * it is made when the bytes arrive and given back when the card that drew
   * them leaves the screen. Both are keyed on the identity of the blob: the
   * query cache hands back the same one on every re-render and a different one
   * only when the photograph itself has changed, so the address is made once
   * per picture and not once per render.
   */
  const objectUrl = useMemo(
    () => (file === null ? null : URL.createObjectURL(file)),
    [file],
  )
  useEffect(() => {
    if (objectUrl === null) {
      return
    }
    return () => {
      URL.revokeObjectURL(objectUrl)
    }
  }, [objectUrl])

  if (file !== null) {
    return { url: objectUrl, isPending: false }
  }
  return { url: signedLinkOf(body), isPending }
}

/** The address out of an answer that carries one, and null out of any other. */
function signedLinkOf(body: unknown): string | null {
  const envelope = body as { data?: { url?: unknown } } | null | undefined
  const url = envelope?.data?.url
  return typeof url === 'string' && url !== '' ? url : null
}
