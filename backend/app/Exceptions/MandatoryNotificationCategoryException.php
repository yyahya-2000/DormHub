<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\NotificationCategory;
use RuntimeException;

/**
 * FR-34, second criterion, read the strict way: the switch exists for the
 * categories that are **not** mandatory, and only for those.
 *
 * A request to switch off a mandatory one is refused rather than quietly
 * dropped. The difference matters to the person at the screen: a silent drop
 * answers «saved» and goes on sending, and they are left believing they turned
 * something off. The refusal names the category so the interface can say which
 * switch was not theirs to move.
 */
final class MandatoryNotificationCategoryException extends RuntimeException
{
    public function __construct(public readonly NotificationCategory $category)
    {
        parent::__construct(sprintf(
            'The «%s» notifications cannot be switched off: they carry what the accommodation '
            .'contract and the rules of internal order oblige the dormitory to tell you.',
            $this->category->label(),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
        ];
    }
}
