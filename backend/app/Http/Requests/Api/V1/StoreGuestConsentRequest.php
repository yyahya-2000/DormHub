<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\GuestRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-35, first criterion, guest half: `POST /api/v1/checkpoint/guest-consent`.
 *
 * **A route of its own, at the post, and both halves of that are the
 * requirement.**
 *
 * *Of its own*, because art. 9 part 1 of Federal Law No. 152-FZ has consent
 * «executed separately from other documents» — a rule about the act rather
 * than about the layout of a page. There is no consent field on the check-in
 * body, exactly as there is none on the sign-in form; the only way a guest's
 * consent comes into being is a request whose entire subject is that consent.
 *
 * *At the post*, because the request was filed by the resident while the data
 * belong to the guest (§2.7.1). The officer puts the text in front of the
 * person standing at the desk, the person agrees, and the record names the
 * revision they were shown. Consent given «on the guest's behalf» at
 * submission would be the operator asserting something no guest ever did, and
 * art. 9 part 3 makes the operator the one who has to prove otherwise.
 *
 * The officer's own capability is what authorises the call — they are
 * operating the screen — and the consent recorded is the guest's.
 */
final class StoreGuestConsentRequest extends FormRequest
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
            // The revision the guest actually read. Checked against the
            // repository in `ConsentRegistry`: a record naming a wording
            // nobody can produce proves nothing.
            'revision' => ['required', 'string', 'max:32'],
        ];
    }

    public function guestRequest(): ?GuestRequest
    {
        $id = $this->input('guest_request_id');

        return is_numeric($id) ? GuestRequest::query()->with('building')->find((int) $id) : null;
    }

    public function revision(): string
    {
        return (string) $this->validated('revision');
    }
}
