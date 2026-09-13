<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `GUEST_REQUEST.guest_doc_type` of §3.4.3, and the switch FR-23 turns on.
 *
 * The list is short on purpose. Clause 2.1.2 of the rules of internal order
 * asks security to write down «the details of the document» and names no
 * catalogue, so the values here are the documents a security post in Russia
 * actually sees at the desk. What matters to the program is one question —
 * `isForeign()` — because that is what FR-23 and Federal Law No. 109-FZ hang
 * on, and everything else about the document is data the register carries
 * without interpreting.
 */
enum GuestDocumentType: string
{
    /** The internal passport of a citizen of the Russian Federation. */
    case InternalPassport = 'internal_passport';

    /** A passport of a foreign state, or a foreign-travel passport. */
    case ForeignPassport = 'foreign_passport';

    /** A residence permit or a temporary-residence permit. */
    case ResidencePermit = 'residence_permit';

    /** A student card of the university or of another institution. */
    case StudentCard = 'student_card';

    /** A driving licence, which the post accepts for a short visit. */
    case DrivingLicence = 'driving_licence';

    public function label(): string
    {
        return match ($this) {
            self::InternalPassport => 'Internal passport',
            self::ForeignPassport => 'Foreign passport',
            self::ResidencePermit => 'Residence permit',
            self::StudentCard => 'Student card',
            self::DrivingLicence => 'Driving licence',
        };
    }

    /**
     * Whether the document marks the guest as a foreign national (FR-23).
     *
     * Two cases and not one. A residence permit is issued to a foreign
     * national as well, and the warning FR-23 attaches is about the migration
     * procedure of the university rather than about the particular booklet in
     * the guest's hand — so leaving it out would have made the flag depend on
     * which of two documents the guest happened to bring.
     *
     * The flag does **not** mean the system performs migration registration.
     * Art. 2 cl. 4 of Federal Law No. 109-FZ makes a place of stay premises
     * the person «regularly uses for sleep and rest», and a guest who leaves
     * before the control time creates none (§2.7.4). What the flag does is
     * raise the warning and demand a mark by the responsible officer before an
     * overnight interval can be approved.
     */
    public function isForeign(): bool
    {
        return $this === self::ForeignPassport || $this === self::ResidencePermit;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
