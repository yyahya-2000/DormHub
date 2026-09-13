<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AnnouncementCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-11: the feed, and the two things a reader may say about it.
 *
 * The route carries no policy and needs none. There is no parameter through
 * which one person could ask for another's feed: the audience is computed from
 * the token's own grants, exactly as the notification routes compute their
 * scope, so the filter that keeps the boundary is not one a caller can reach.
 */
final class ListAnnouncementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'in:'.implode(',', AnnouncementCategory::values())],
            /*
             * FR-09's «moves to the archive», asked for explicitly. The
             * archive is not a table anybody moves rows into — it is the other
             * side of the `expires_at` comparison the feed already makes — so
             * it is a flag on this route rather than a route of its own.
             */
            'archived' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function category(): ?AnnouncementCategory
    {
        $category = $this->query('category');

        return is_string($category) ? AnnouncementCategory::tryFrom($category) : null;
    }

    public function archived(): bool
    {
        return $this->boolean('archived');
    }
}
