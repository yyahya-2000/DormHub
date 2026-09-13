<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One announcement as the resident reads it in the feed (FR-09, FR-11, FR-12).
 *
 * **`is_unread` is computed and not stored.** The feed left-joins
 * `announcement_acks` on the reader and selects `acknowledged_at` as an alias
 * (§4.6.1), so the mark FR-11 asks for travels with the row. On an
 * announcement fetched without that join the attribute is absent, and the
 * resource says «unread» rather than inventing a date — a response shaped from
 * a row that was never asked the question must not claim it was answered.
 *
 * **`building_id` null is shown as an audience and not as a missing field.**
 * The client draws «all dormitories», which is what NULL means (§3.4.2), so
 * `addresses_every_building` carries the meaning explicitly instead of leaving
 * every interface to infer it from a null.
 *
 * @mixin Announcement
 */
final class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $acknowledgedAt = $this->resource->getAttribute('acknowledged_at');

        return [
            'id' => $this->id,

            'building_id' => $this->building_id,
            'building_name' => $this->whenLoaded('building', fn () => $this->building?->name),
            'addresses_every_building' => $this->addressesEveryBuilding(),

            'author_id' => $this->author_id,
            'author_name' => $this->whenLoaded('author', fn () => $this->author?->full_name),

            'title' => $this->title,
            'body' => $this->body,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),

            // FR-12 turns on this flag: a mandatory announcement is one the
            // reader is asked to acknowledge and one the warden may then run a
            // readers report on.
            'is_mandatory' => (bool) $this->is_mandatory,

            'published_at' => $this->published_at?->toIso8601String(),
            // Null: the announcement does not expire. Otherwise this is the
            // moment it leaves the feed for the archive (FR-09).
            'expires_at' => $this->expires_at?->toIso8601String(),

            'acknowledged_at' => $acknowledgedAt?->toIso8601String(),
            'is_unread' => $acknowledgedAt === null,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
