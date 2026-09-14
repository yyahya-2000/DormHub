<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * FR-36's categories: «plumbing, electrical, furniture, heating, network,
 * other».
 *
 * Six values and not a lookup table, by §4.4.2's rule: the list is fixed by
 * the requirement, nobody administers it from a screen, and a category carries
 * no attributes of its own. `ROLE` is a table because `ROLE_USER` references
 * it and the grant carries a building; nothing references a category.
 *
 * **`Other` is deliberately last and deliberately present.** A resident who
 * cannot find their defect in five words abandons the form, and the queue then
 * holds a tidy taxonomy of the defects that were easy to classify. The warden
 * reclassifies on triage if it matters; the description is what they actually
 * read.
 *
 * The ER diagram of §3.4.3 lists five of the six — `network` is in FR-36 and
 * not in the diagram. The requirement is what the module is accepted against,
 * so the sixth is here, and a dormitory whose complaint about the wifi had
 * nowhere to go would be the diagram's omission become a defect.
 */
enum MaintenanceCategory: string
{
    case Plumbing = 'plumbing';
    case Electrical = 'electrical';
    case Furniture = 'furniture';
    case Heating = 'heating';
    case Network = 'network';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Plumbing => 'Plumbing',
            self::Electrical => 'Electrical',
            self::Furniture => 'Furniture',
            self::Heating => 'Heating',
            self::Network => 'Network and internet',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
