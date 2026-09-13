<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Guests\AccessCodeGenerator;
use App\Models\Building;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-18, `POST /api/v1/checkpoint/verify`.
 *
 * **A POST that changes nothing**, which looks wrong and is not. The body
 * carries a guest's surname or the code of a visit, both of them personal
 * data of a person standing at the desk; a GET would put them in the query
 * string, and a query string is written to every access log between the
 * terminal and the application, cached by the browser and left in the address
 * bar of a screen that faces the lobby. §2.7.1's minimisation is about what is
 * stored as much as about what is collected. The route changes no state — the
 * entry is a second, separate call — and the contract says so in as many
 * words.
 *
 * **One of the two, and not both.** Searching by code and by surname at once
 * has no meaning: the code identifies one request and the surname is what you
 * fall back to when the guest has lost it.
 */
final class VerifyGuestAtCheckpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->building();

        return $building !== null
            && $this->user()?->can('operateCheckpoint', $building) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'surname' => ['sometimes', 'nullable', 'string', 'min:2', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->code() === null && $this->surname() === null) {
                $validator->errors()->add(
                    'code',
                    'Give the visit code or the guest\'s surname.',
                );
            }
        });
    }

    public function building(): ?Building
    {
        $id = $this->input('building_id');

        return is_numeric($id) ? Building::query()->find((int) $id) : null;
    }

    /**
     * Normalised before it is looked up: a code read back over a telephone
     * arrives in lower case and with the spaces the reader put in, and it is
     * the same code.
     */
    public function code(): ?string
    {
        $code = $this->input('code');

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $normalised = AccessCodeGenerator::normalise($code);

        return $normalised === '' ? null : $normalised;
    }

    public function surname(): ?string
    {
        $surname = $this->input('surname');

        return is_string($surname) && trim($surname) !== '' ? trim($surname) : null;
    }
}
