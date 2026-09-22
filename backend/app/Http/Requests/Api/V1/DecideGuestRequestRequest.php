<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\GuestRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-17: the decision on a guest request, both ways round.
 *
 * One request class for two routes, because the authorisation question is the
 * same one and asking it twice is how the two answers come to differ. What is
 * not the same is the body: the approval's comment is optional and the
 * refusal's reason is not — FR-17's second criterion says «rejection without a
 * reason is impossible» — so `rules()` reads the route it is serving.
 *
 * The reason is required in two places and that is deliberate rather than
 * duplicated: here, so that the client gets a 422 naming the field, and in
 * `GuestRequestService::reject()`, where it is a required argument and cannot
 * be omitted at all. A rule that lives only at the boundary is a rule any
 * other caller can walk past.
 */
final class DecideGuestRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('guestRequest');

        return $request instanceof GuestRequest
            && $this->user()?->can('decide', $request) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if ($this->isRejection()) {
            return [
                'reason' => ['required', 'string', 'min:3', 'max:1000'],
            ];
        }

        return [
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function isRejection(): bool
    {
        return $this->routeIs('guest-requests.reject');
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) && trim($comment) !== '' ? trim($comment) : null;
    }
}
