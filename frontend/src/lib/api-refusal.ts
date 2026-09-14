import type {
  CapacityExceeded,
  ConfirmationWindowClosedError,
  ConsentRequiredError,
  DeletionBlocked,
  EntryNotPermittedError,
  IllegalTransitionError,
  MandatoryCategory,
  OfficerMarkRequiredError,
  QuotaError,
  ResidencyConflict,
  ValidationError,
  VisitAlreadyClosedError,
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

/**
 * FR-34. The refusal of an attempt to switch off a category that carries what
 * the dormitory is obliged to tell the person.
 *
 * It shares 422 with an ordinary validation failure and is told apart by its
 * shape: a category and the server's own name for it, and no `errors` map. The
 * distinction is worth making on the screen — «the form is wrong» and «that one
 * cannot be switched off, and here is why» are different sentences, and only
 * the second one answers what the person just tried to do.
 */
export function asMandatoryCategory(error: unknown): MandatoryCategory | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 422) {
    return null
  }
  return typeof body.category === 'string' && typeof body.category_label === 'string'
    ? (body as unknown as MandatoryCategory)
    : null
}

/**
 * FR-19 and §2.4.2, second scenario: the rules admit no entry as things stand.
 *
 * `reason_code` says which refusal it is and `override_available` whether the
 * officer's decision may set it aside. Both are read out of the body and never
 * worked out here — the card the officer reads and the rule the entry is
 * refused by are the same call on the server, and a client that recomputed the
 * verdict would be a second, disagreeing copy of it.
 */
export function asEntryNotPermitted(error: unknown): EntryNotPermittedError | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 422) {
    return null
  }
  return typeof body.reason_code === 'string'
    ? (body as unknown as EntryNotPermittedError)
    : null
}

/**
 * FR-35 at the point it bites: no consent from the guest is on record, and the
 * entry is therefore not written. 409 and not 403 — the officer's role covers
 * the post perfectly well, and what stands in the way is a missing document.
 *
 * The body names the revision, and the revision is what the consent step then
 * records. It is never a constant in the client: a record naming a wording the
 * repository cannot produce would prove nothing (art. 9 part 3 of Federal Law
 * No. 152-FZ).
 */
export function asConsentRequired(
  error: unknown,
): (ConsentRequiredError & { revision: string }) | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 409) {
    return null
  }
  // The revision is optional in the schema and load bearing here: the consent
  // step records the one the server named, so a body without it is not a
  // refusal this screen can answer.
  return typeof body.document === 'string' && typeof body.revision === 'string'
    ? (body as unknown as ConsentRequiredError & { revision: string })
    : null
}

/** §3.5.4: the request is no longer in the state the action asked for. */
export function asIllegalTransition(error: unknown): IllegalTransitionError | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 409) {
    return null
  }
  return typeof body.attempted_status === 'string'
    ? (body as unknown as IllegalTransitionError)
    : null
}

/**
 * FR-39, and the one refusal of the maintenance module a transition table
 * cannot express: the request is still `completed`, `completed → accepted` is a
 * move the table admits, and what has run out is the clock rather than the
 * state.
 *
 * It shares 409 with the illegal transition and is told apart by its shape —
 * a confirmation window and the day it closed, and no `attempted_status`. The
 * order matters where both are read, because the window is the more specific
 * answer and the only one that tells the resident why the same button worked
 * yesterday.
 */
export function asConfirmationWindowClosed(
  error: unknown,
): ConfirmationWindowClosedError | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 409) {
    return null
  }
  return typeof body.confirmation_window_days === 'number'
    ? (body as unknown as ConfirmationWindowClosedError)
    : null
}

/** FR-17: the daily ceiling of approved visits is spent, per resident or per building. */
export function asQuotaSpent(error: unknown): QuotaError | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 422) {
    return null
  }
  return typeof body.quota_scope === 'string' ? (body as unknown as QuotaError) : null
}

/**
 * FR-23, second criterion: a foreign document and an interval running past
 * midnight, approved without the mark of the officer responsible for migration
 * registration.
 */
export function asOfficerMarkRequired(error: unknown): OfficerMarkRequiredError | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 422) {
    return null
  }
  return body.required_field === 'responsible_officer_mark'
    ? (body as unknown as OfficerMarkRequiredError)
    : null
}

/** FR-21: the exit on this visit is already written, and is written once. */
export function asVisitAlreadyClosed(error: unknown): VisitAlreadyClosedError | null {
  const body = bodyOf(error)
  if (body === null || statusOf(error) !== 409) {
    return null
  }
  return typeof body.checked_out_at === 'string'
    ? (body as unknown as VisitAlreadyClosedError)
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
