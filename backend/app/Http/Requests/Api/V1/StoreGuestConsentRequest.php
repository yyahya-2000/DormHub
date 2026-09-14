<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ConsentDocument;
use App\Models\GuestRequest;
use App\Services\ConsentTexts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
 *
 * **The revision is checked here and not only in the registry.** Art. 9 part 3
 * of Federal Law No. 152-FZ puts on the operator the burden of proving that
 * consent was given, so a record naming a wording the repository cannot produce
 * proves nothing and `ConsentRegistry::recordForGuest()` refuses to write one.
 * Its refusal is a `RuntimeException` — a fault, not an answer — and it reached
 * the post as a 500 on a malformed field. The check is repeated at the boundary
 * for the same reason the resident half repeats it (`StoreConsentRequest`):
 * input is answered with 422, and the officer is told which field to correct.
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
            // The revision the guest actually read. The shape is asserted
            // before the repository is asked anything: the identifier becomes
            // a path under `resources/consent`, and `ConsentTexts` refuses a
            // malformed one by raising rather than by answering.
            'revision' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $document = ConsentDocument::GuestPersonalData;
                $texts = app(ConsentTexts::class);

                if ($texts->has($document, $this->revision())) {
                    return;
                }

                $validator->errors()->add('revision', sprintf(
                    'The repository holds no revision «%s» of «%s». '
                    .'The text in force is revision «%s»; show the guest that one.',
                    $this->revision(),
                    $document->title(),
                    $texts->currentRevision($document),
                ));
            },
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
