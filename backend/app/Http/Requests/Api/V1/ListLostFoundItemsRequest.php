<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\LostFoundItemKind;
use App\Enums\LostFoundItemStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-25: `GET /api/v1/lost-found`.
 *
 * **The route carries no building parameter, and the absence is the first
 * acceptance criterion.** «Residents see the list of finds for their own
 * dormitory» — the dormitories are computed from the grants of the token by
 * `LostFoundFeed`, so there is no way to phrase a request for another one. The
 * boundary is a missing parameter rather than a policy somebody has to
 * remember to call, which is the arrangement the announcement feed and the
 * notification routes are built on.
 *
 * **The status filter cannot reach `resolved`, and that is FR-26's third
 * criterion rather than an omission.** «After closure the record disappears
 * from the public list», so the value is refused at the boundary with a 422
 * naming the field instead of being accepted and quietly answered with an
 * empty page — a client that asked and got nothing back would have no way to
 * tell «none today» from «not available here».
 */
final class ListLostFoundItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The caller's own dormitories, and nothing beyond a session is needed
        // to read them.
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:'.implode(',', $this->feedStatuses())],
            'kind' => ['sometimes', 'string', 'in:'.implode(',', LostFoundItemKind::values())],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'The public list holds the entries that are still open; a closed one leaves it.',
        ];
    }

    public function status(): ?LostFoundItemStatus
    {
        $status = $this->query('status');

        return is_string($status) ? LostFoundItemStatus::tryFrom($status) : null;
    }

    public function kind(): ?LostFoundItemKind
    {
        $kind = $this->query('kind');

        return is_string($kind) ? LostFoundItemKind::tryFrom($kind) : null;
    }

    public function search(): ?string
    {
        $search = $this->query('search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }

    /**
     * @return list<string>
     */
    private function feedStatuses(): array
    {
        return array_map(
            static fn (LostFoundItemStatus $status): string => $status->value,
            LostFoundItemStatus::visibleInTheFeed(),
        );
    }
}
