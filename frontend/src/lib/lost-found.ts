import {
  LostFoundClaimStatus,
  LostFoundCustody,
  LostFoundItemKind,
  LostFoundItemStatus,
  ListLostFoundStatus,
  type LostFoundClaim,
  type LostFoundItem,
} from '@/api/generated/model'

/**
 * The vocabulary of the lost-and-found module, in one file so that the feed,
 * the card and the claims panel draw the same words in the same colours.
 *
 * **Nothing here is a decision.** §2.5.4 makes the module peer-to-peer: the
 * resident who found the object keeps it, publishes it and answers the claims
 * himself, and a member of staff appears on exactly two occasions — an object
 * deposited with the administration, and a claim the two sides could not
 * settle. The functions below say which controls are worth drawing and are
 * allowed to be wrong in the visible direction; the server decides, and
 * answers 403, 409 or 422 with the reason on it.
 */

export const LOST_FOUND_KINDS: LostFoundItemKind[] = Object.values(LostFoundItemKind)

/**
 * The two readings of the feed the route admits. `resolved` is deliberately
 * not among them: FR-26's third criterion is «after closure the record
 * disappears from the public list», so the route answers a request for it with
 * 422 naming the field rather than with an empty page.
 */
export const LOST_FOUND_LIST_STATUSES: ListLostFoundStatus[] =
  Object.values(ListLostFoundStatus)

/**
 * The tone of an entry's state. Three, and only one of them is an ending:
 * published is the feed, claimed is somebody waiting for an answer, resolved
 * is the object back with its owner and the entry out of the list.
 */
export const ITEM_STATUS_TONE: Record<LostFoundItemStatus, string> = {
  published: 'border-prussian/30 bg-prussian-wash text-prussian',
  claimed: 'border-brass/45 bg-brass-wash text-brass',
  resolved: 'border-rule bg-paper text-steel',
}

/**
 * The tone of a claim. `referred` is drawn apart from the other three because
 * it is the one state that is nobody's to leave alone: somebody has already
 * said no, the claimant did not accept it, and the warden owes an answer.
 */
export const CLAIM_STATUS_TONE: Record<LostFoundClaimStatus, string> = {
  new: 'border-brass/45 bg-brass-wash text-brass',
  accepted: 'border-prussian bg-prussian text-white',
  declined: 'border-rule bg-paper text-steel',
  referred: 'border-brick/50 bg-brick-wash text-brick',
}

/**
 * A find and a loss are two directions of one notice and are told apart by a
 * mark rather than by a colour: the difference is which side is holding the
 * object, and neither side is an alarm.
 */
export const KIND_TONE: Record<LostFoundItemKind, string> = {
  found: 'border-prussian/25 bg-prussian-wash text-prussian',
  lost: 'border-rule bg-paper text-steel',
}

/** Only a find admits a claim: «that is mine» says nothing about a loss. */
export function admitsClaims(item: LostFoundItem): boolean {
  return item.kind === LostFoundItemKind.found
}

/** FR-26's third criterion: the entry has gone home and left the feed. */
export function isResolved(item: LostFoundItem): boolean {
  return item.status === LostFoundItemStatus.resolved
}

/**
 * Whether the reading account is the person who published this find.
 *
 * **There is no `reporter_id` to compare against, and that is FR-25's second
 * criterion** — the card carries neither the name nor the contacts of the
 * registering user, so a feed read by several hundred people is not a
 * directory of who found what and lives where (§3.5.3). What the response does
 * carry is `can_claim`, the three facts a claim would be refused on: it is a
 * find rather than a loss, it has not gone home, and it is not the reader's
 * own entry. Two of those three are on the row in plain sight, so the third
 * follows from them — a find still open whose `can_claim` is false is the
 * reader's own.
 *
 * The inference is exact where it answers and silent where it cannot: on a
 * resolved entry `can_claim` is false for everybody, so this reads false for
 * the finder too. Nothing is lost by that, because the only controls it gates
 * are the answers to an outstanding claim, and a closed entry has none.
 */
export function isTheFinder(item: LostFoundItem): boolean {
  return (
    item.kind === LostFoundItemKind.found &&
    item.status !== LostFoundItemStatus.resolved &&
    item.can_claim === false
  )
}

/**
 * Whether the claims on this entry are this account's to answer — the finder
 * on the ordinary path, the staff of the dormitory on the deposited one.
 *
 * The two halves are asked separately because the server asks them separately:
 * on the deposited path the decision belongs to the staff as a circle rather
 * than to one account, since the officer who took the object in on Friday is
 * not on shift on Monday.
 */
export function decidesClaimsOn(
  item: LostFoundItem,
  holdsHere: (buildingId: number) => boolean,
): boolean {
  if (item.custody === LostFoundCustody.administration) {
    return holdsHere(item.building_id)
  }
  return isTheFinder(item)
}

/** FR-26. A claim nobody has answered yet: the holder's to accept or decline. */
export function awaitsHolder(claim: LostFoundClaim): boolean {
  return claim.status === LostFoundClaimStatus.new
}

/** FR-26. A refusal the claimant did not accept: the warden's to settle. */
export function awaitsJudge(claim: LostFoundClaim): boolean {
  return claim.status === LostFoundClaimStatus.referred
}

/**
 * FR-26's «only», read off the claims rather than guessed at: a find closes as
 * returned on a claim somebody accepted, and a claim the finder accepted and a
 * claim the warden upheld both end at the same state.
 */
export function acceptedClaimOf(claims: LostFoundClaim[]): LostFoundClaim | null {
  return claims.find((claim) => claim.status === LostFoundClaimStatus.accepted) ?? null
}

/** How many claims on the entry are still waiting for an answer. */
export function outstandingClaims(claims: LostFoundClaim[]): number {
  return claims.filter((claim) => awaitsHolder(claim) || awaitsJudge(claim)).length
}

/**
 * FR-24: «not later than today». A find picked up in September and published
 * in December is fine and nothing refuses it; a find dated tomorrow is not.
 */
export function latestFindingDate(): string {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}
