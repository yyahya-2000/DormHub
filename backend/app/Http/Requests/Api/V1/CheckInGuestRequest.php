<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\GuestRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-19, `POST /api/v1/checkpoint/check-in`.
 *
 * The request is named by its identifier and not by its code. The officer has
 * just verified the guest, so the card — and the identifier on it — is on the
 * screen; a second lookup by code would be a second chance to find a different
 * request between the two calls.
 *
 * `override_reason` is §2.4.2's «admit on the responsible officer's decision»,
 * and it is optional **here** and mandatory **where it applies**: the rule is
 * «a reason is required when the entry is being admitted against a refusal
 * that may be set aside», and whether that is the case depends on the stored
 * request and the time of day, neither of which a rule string can see. So the
 * service decides, and refuses with 422 when the reason is missing.
 */
final class CheckInGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->guestRequest();

        if ($request === null) {
            return true;
        }

        $building = $request->building()->first();

        return $building !== null
            && $this->user()?->can('operateCheckpoint', $building) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'guest_request_id' => ['required', 'integer', 'exists:guest_requests,id'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function guestRequest(): ?GuestRequest
    {
        $id = $this->input('guest_request_id');

        return is_numeric($id) ? GuestRequest::query()->with('building')->find((int) $id) : null;
    }

    public function overrideReason(): ?string
    {
        $reason = $this->validated('override_reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
