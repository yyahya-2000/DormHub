<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an announcement is about (FR-09, FR-11).
 *
 * FR-11 asks for a feed «filterable by category», which only means something
 * if the categories are a closed list somebody can draw as a row of tabs. A
 * free-text label would give every warden a vocabulary of his own and the
 * filter would stop being a filter.
 *
 * **The category is not the mandatory flag, and the two must not be merged.**
 * FR-12 speaks of «announcements of category mandatory», and the ER model of
 * §3.4.3 nevertheless carries `category` and `is_mandatory` as separate
 * columns. That is deliberate: the subject of a notice and the obligation to
 * read it vary independently. A water shutoff is `utilities` and is normally
 * mandatory; a fire drill is `safety` and always is; a film evening is
 * `events` and never is. Folding the obligation into the list would mean a
 * warden could not announce a shutoff that nobody has to acknowledge, and the
 * filter of FR-11 would be answering a question about duty rather than about
 * subject.
 *
 * The five are the subjects a warden actually posts, read off the notice
 * boards described in §2.3.2. `general` is the residue and exists so that
 * nothing has to be miscategorised to be published.
 */
enum AnnouncementCategory: string
{
    /** Orders and instructions of the administration; changes to the regime. */
    case HouseRules = 'house_rules';

    /** Planned works: water, power, heating, lifts, the laundry. */
    case Utilities = 'utilities';

    /** Fire drills, evacuation, security, epidemic measures. */
    case Safety = 'safety';

    /** The social life of the dormitory. */
    case Events = 'events';

    /** Everything that fits none of the four above. */
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::HouseRules => 'Rules and orders',
            self::Utilities => 'Planned works and utilities',
            self::Safety => 'Safety',
            self::Events => 'Events',
            self::General => 'General',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
