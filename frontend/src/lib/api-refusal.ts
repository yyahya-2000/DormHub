import type {
  CapacityExceeded,
  DeletionBlocked,
  ResidencyConflict,
  ValidationError,
} from '@/api/generated/model'
import { ApiError } from '@/api/http-client'

/**
 * Reading the refusals the contract describes.
 *
 * Three of the acceptance criteria of this slice are about a refusal that
 * carries figures: FR-01 blocks a deletion and says what is attached, FR-02
 * rejects a place and names the free remainder, FR-03 refuses a residency and
 * hands back the record standing in the way. The bodies arrive inside
 * `ApiError`, typed as `unknown`, and these guards are what turns them back
 * into something a screen can render.
 *
 * The figures are read out of the body and never recomputed. `free_places` in
 * particular is the number the register refused on; a client that subtracted
 * its own could print a different one.
 */

export function statusOf(error: unknown): number | null {
  return error instanceof ApiError ? error.status : null
}

function bodyOf(error: unknown): Record<string, unknown> | null {
  if (!(error instanceof ApiError)) {
    return null
  }
  const body = error.body
  return typeof body === 'object' && body !== null ? (body as Record<string, unknown>) : null
}

/** The `message` the API put on the refusal, in the server's own words. */
export function serverMessage(error: unknown): string | null {
  const body = bodyOf(error)
  return typeof body?.message === 'string' ? body.message : null
}

export function asValidationError(error: unknown): ValidationError | null {
  const body = bodyOf(error)
  if (body === null || typeof body.errors !== 'object' || body.errors === null) {
    return null
  }
  return body as unknown as ValidationError
}

export function asCapacityExceeded(error: unknown): CapacityExceeded | null {
  const body = bodyOf(error)
  if (body === null) {
    return null
  }
  const complete =
    typeof body.capacity === 'number' &&
    typeof body.beds === 'number' &&
    typeof body.free_places === 'number'
  return complete ? (body as unknown as CapacityExceeded) : null
}

export function asResidencyConflict(error: unknown): ResidencyConflict | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 409) {
    return null
  }
  return 'conflict' in body || 'bed_id' in body
    ? (body as unknown as ResidencyConflict)
    : null
}

export function asDeletionBlocked(error: unknown): DeletionBlocked | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 409) {
    return null
  }
  return typeof body.blocked_by === 'object' && body.blocked_by !== null
    ? (body as unknown as DeletionBlocked)
    : null
}

/** Field name → the messages the server attached to it, flattened for display. */
export function fieldMessages(error: unknown): { field: string; messages: string[] }[] {
  const validation = asValidationError(error)
  if (validation === null) {
    return []
  }
  return Object.entries(validation.errors).map(([field, messages]) => ({
    field,
    messages: Array.isArray(messages) ? messages.map(String) : [String(messages)],
  }))
}
