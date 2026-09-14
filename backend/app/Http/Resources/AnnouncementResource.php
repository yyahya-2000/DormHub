<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AnnouncementCategory;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One announcement as the resident reads it in the feed (FR-09, FR-11).
 *
 * **`category` is whatever was stored and `category_label` is what to show.**
 * The column is a free label of at most 32 characters, so the label is the
 * name from `AnnouncementCategory` when the value is one of the catalogue's
 * and the value itself when the author typed their own. A client that simply
 * draws `category_label` is right in both cases.
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
        $category = (string) $this->category;

        return [
            'id' => $this->id,

            'building_id' => $this->building_id,
            'building_name' => $this->whenLoaded('building', fn () => $this->building?->name),
            'addresses_every_building' => $this->addressesEveryBuilding(),

            'author_id' => $this->author_id,
            'author_name' => $this->whenLoaded('author', fn () => $this->author?->full_name),

            'title' => $this->title,
            'body' => $this->body,
            'category' => $category,
            'category_label' => AnnouncementCategory::labelFor($category),

            'published_at' => $this->published_at?->toIso8601String(),
            // Null: the announcement does not expire. Otherwise this is the
            // moment it leaves the feed for the archive (FR-09).
            'expires_at' => $this->expires_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
