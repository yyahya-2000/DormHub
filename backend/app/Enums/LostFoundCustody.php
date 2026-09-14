<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who is physically holding the object: the one column that records which of
 * §2.5.4's two paths a find actually took.
 *
 * **The module is peer-to-peer by default and this enumeration is where that
 * is written down.** §2.5.4: «The finder keeps the item, publishes it and
 * decides on a claim; the warden enters in two cases only, a claim referred as
 * disputed and an item physically deposited with the administration for
 * safekeeping.» Routing every find through a member of staff would put back
 * the delay the module exists to remove, so `Finder` is the default of the
 * column and of the form.
 *
 * **The legal note, stated no wider than it goes.** Article 227 clause 1
 * paragraph 2 of the Civil Code covers one path: a thing found on premises and
 * *handed to the person representing the owner of those premises*, who thereby
 * acquires the rights and bears the duties of the finder. `Administration` is
 * that path and only that path. On the default path the object never leaves
 * the resident who found it, so no hand-over within the meaning of that
 * paragraph takes place; nothing in the provision requires one, and nothing
 * here should be read as saying that it does. The system records which path a
 * find took; it does not choose the path and does not displace anybody's
 * civil-law position (§2.7.5).
 *
 * The practical consequence is the one FR-26 needs: on the default path the
 * finder decides on claims, and on the deposited path the staff of that
 * dormitory decide, because the staff are holding the object.
 */
enum LostFoundCustody: string
{
    /**
     * The default. The object is with the person who published the entry, who
     * hands it over in person and decides on claims themselves.
     */
    case Finder = 'finder';

    /**
     * The object was handed in at the security post or deposited with the
     * administration for safekeeping — Civil Code art. 227 cl. 1 para. 2.
     *
     * Published on the same form by the security officer, the warden or the
     * manager (FR-24), and claimed against the same way. Whether a declaration
     * to the police or to a local self-government body has been filed is a
     * separate fact and lives in `declared_on`; it is not implied by this
     * value, because the process behind it is the university's to define
     * (§2.7.5).
     */
    case Administration = 'administration';

    public function label(): string
    {
        return match ($this) {
            self::Finder => 'With the finder',
            self::Administration => 'Deposited with the administration',
        };
    }

    /**
     * Whether a decision on a claim belongs to the staff of the dormitory
     * rather than to the person who published the entry.
     */
    public function restsWithTheAdministration(): bool
    {
        return $this === self::Administration;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $custody): string => $custody->value, self::cases());
    }
}
