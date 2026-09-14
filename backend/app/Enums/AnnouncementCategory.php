<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The catalogue of announcement subjects the client offers first (FR-09, FR-11).
 *
 * **This is a list of suggestions and no longer the vocabulary of the column.**
 * `announcements.category` is a free string of at most 32 characters, checked
 * by `StoreAnnouncementRequest` and by nothing else; the CHECK constraint that
 * used to close the list was dropped by
 * `2026_09_14_200100_open_the_announcement_category_to_a_free_label`.
 *
 * The reason for the change is the one FR-11's filter actually has. A closed
 * list keeps two wardens from writing «Ремонт» and «ремонт» and getting two
 * headings, which is worth something; it also forces every subject the five
 * cases did not foresee into `general`, which is worth less than nothing,
 * because a residual heading that collects half the feed is a filter that
 * filters nothing. Offering the five first and admitting a typed label keeps
 * most of the agreement and loses none of the subjects.
 *
 * The cases are the subjects a warden actually posts, read off the notice
 * boards described in §2.3.2. `general` is the residue. The list is served to
 * the client at `GET /announcement-categories`.
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

    /** The longest label the column will hold, and what the request validates. */
    public const MAX_LENGTH = 32;

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

    /**
     * The list the client draws the dropdown from before the field is opened
     * for typing: a stored value and the name beside it, in the order the
     * cases are declared. Shaped exactly as `Citizenship::options()`, because
     * `GET /announcement-categories` and `GET /citizenships` are the same kind
     * of answer and a client that reads one should not have to learn a second
     * shape for the other.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /**
     * The name to show beside a stored category, which for a label somebody
     * typed is the label itself.
     */
    public static function labelFor(string $category): string
    {
        return self::tryFrom($category)?->label() ?? $category;
    }
}
