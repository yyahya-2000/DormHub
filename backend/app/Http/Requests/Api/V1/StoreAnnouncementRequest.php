<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AnnouncementCategory;
use App\Models\Announcement;
use App\Models\Building;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-09: what a publishable announcement has to carry.
 *
 * **`building_id` is the whole of the addressee, and its absence is
 * meaningful.** One field, an identifier or null; null addresses every
 * dormitory (§3.4.2). There is no separate «all buildings» flag, and there
 * must not be one — two fields could disagree, and the disagreement would be
 * a notice addressed to nobody. Omitting the identifier is a right and not a
 * default, so the authorisation below passes the resolved building — null
 * included — to the policy rather than checking a capability and then reading
 * the field. Asked the other way round, an administrator's «all buildings» and
 * a warden's missing field would look identical at the point of decision.
 *
 * **`category` is a free label, bounded and trimmed.** The client draws the
 * catalogue of `GET /announcement-categories` and lets the author type
 * something else; the server takes whatever comes, up to the 32 characters the
 * column holds. Trimming happens here rather than being left to the framework
 * so that «  safety » and «safety» are one heading in the filter of FR-11 and
 * not two.
 *
 * **`published_at` is not accepted.** Publication happens now; see
 * `AnnouncementService::publish`.
 */
final class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('publish', [Announcement::class, $this->building()]) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'category' => ['required', 'string', 'max:'.AnnouncementCategory::MAX_LENGTH],
            /*
             * FR-09 calls this the validity period. An expiry already in the
             * past would produce a notice that is in the archive on the second
             * it is written and in no feed at all — the database refuses it
             * too, by `announcements_expiry_follows_publication`, and 422 here
             * is the answer the warden can act on.
             */
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_at.after' => 'An announcement cannot expire before it is published.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $category = $this->input('category');

        if (is_string($category)) {
            $this->merge(['category' => trim($category)]);
        }
    }

    public function building(): ?Building
    {
        $id = $this->input('building_id');

        return is_numeric($id) ? Building::query()->find((int) $id) : null;
    }

    public function category(): string
    {
        return trim((string) $this->input('category'));
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = $this->input('expires_at');

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
