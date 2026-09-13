<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the notification settings screen (FR-34, second criterion).
 *
 * `mandatory` is in the answer rather than left to the client to infer from a
 * list it keeps of its own. The set of mandatory categories is a matter of the
 * legal ground a message rests on, it is decided in
 * `App\Enums\NotificationCategory`, and a client that duplicated the decision
 * would eventually draw a switch the server refuses to move.
 *
 * @property array{category: NotificationCategory, enabled: bool, mandatory: bool} $resource
 */
final class NotificationSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var NotificationCategory $category */
        $category = $this->resource['category'];

        return [
            'category' => $category->value,
            'label' => $category->label(),
            'description' => $category->description(),
            'enabled' => (bool) $this->resource['enabled'],
            'mandatory' => (bool) $this->resource['mandatory'],
        ];
    }
}
