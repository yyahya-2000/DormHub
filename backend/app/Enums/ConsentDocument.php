<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The documents a person is asked to consent to, as separate acts (FR-35).
 *
 * There are two, and they are two rather than one for a reason that comes
 * straight out of §2.7.1. A resident's data is processed on the ground of the
 * accommodation contract (art. 6 part 1 cl. 5 of Federal Law No. 152-FZ), and
 * only the part of it that goes beyond the contract — the notifications to a
 * personal address, the guest-visiting service — rests on consent. A guest's
 * data has no contract behind it at all: consent (art. 6 part 1 cl. 1) is the
 * **only** ground there is, which is why the same withdrawal has a mild
 * consequence on one side of this enumeration and an absolute one on the other.
 *
 * Nothing here decides that; `App\Services\ConsentRegistry` does. What this
 * enumeration fixes is that the two are separate documents with separate
 * revisions and separate records, which is art. 9 part 1 read literally:
 * consent is «executed separately from other documents».
 *
 * The codes carry no dot. They are configuration keys in
 * `config/dormitory.php` and path segments in `resources/consent`, and a dot
 * is the separator in both — `personal_data.resident` would have been read as
 * two nested keys and found nothing.
 */
enum ConsentDocument: string
{
    /** The resident's own consent, asked for at first sign-in. */
    case ResidentPersonalData = 'resident_personal_data';

    /**
     * The guest's consent, asked for at the security post before the entry is
     * recorded (FR-18, FR-19, increment 1).
     *
     * The case exists here in increment 0 because the mechanism is what
     * increment 0 owes: the text, its revision, the record and the refusal are
     * all in place, and what is missing is only the route that calls them. See
     * `ConsentRegistry::requireGranted()`.
     */
    case GuestPersonalData = 'guest_personal_data';

    public function title(): string
    {
        return match ($this) {
            self::ResidentPersonalData => 'Consent to the processing of personal data — resident',
            self::GuestPersonalData => 'Consent to the processing of personal data — guest',
        };
    }

    /**
     * Whether the document is one the system asks an account holder for when
     * they first sign in (FR-35, first criterion).
     *
     * The guest's document is not: a guest holds no account and is asked at the
     * post, in person, by the security officer.
     */
    public function isAskedAtFirstLogin(): bool
    {
        return $this === self::ResidentPersonalData;
    }

    /**
     * Whether the processing this document covers can proceed at all without
     * it.
     *
     * False for the resident: the housing register rests on the accommodation
     * contract, so a resident who withdraws consent keeps their room, their
     * card and every notification the contract obliges the dormitory to send.
     * True for the guest: there is no other ground, so with no consent on
     * record there is nothing lawful to record.
     */
    public function isSoleGroundForProcessing(): bool
    {
        return $this === self::GuestPersonalData;
    }

    /**
     * @return list<self>
     */
    public static function askedAtFirstLogin(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $document): bool => $document->isAskedAtFirstLogin(),
        ));
    }
}
