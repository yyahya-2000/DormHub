<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AnnouncementCategory;
use App\Http\Requests\Api\V1\Concerns\AcceptsBooleanQueryFlags;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-11: the feed, and the two things a reader may say about it.
 *
 * The route carries no policy and needs none. There is no parameter through
 * which one person could ask for another's feed: the audience is computed from
 * the token's own grants, exactly as the notification routes compute their
 * scope, so the filter that keeps the boundary is not one a caller can reach.
 *
 * **The category filter matches a stored label and no longer a closed list.**
 * A heading the register does not hold is now an empty feed rather than a 422:
 * the column admits anything up to 32 characters, so there is no such thing as
 * an unknown category to refuse — only one nobody has posted under yet.
 */
final class ListAnnouncementsRequest extends FormRequest
{
    use AcceptsBooleanQueryFlags;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `archived=true` is the spelling the generated client sends, and it used
     * to be a 422. See `AcceptsBooleanQueryFlags`.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseBooleanFlags('archived');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'max:'.AnnouncementCategory::MAX_LENGTH],
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

    public function category(): ?string
    {
        $category = $this->query('category');

        if (! is_string($category)) {
            return null;
        }

        $category = trim($category);

        return $category === '' ? null : $category;
    }

    public function archived(): bool
    {
        return $this->boolean('archived');
    }
}
