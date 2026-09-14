<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\LostFoundClaim;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-26, first criterion: «a warden's decision on a referred claim».
 *
 * The second of §2.5.4's two occasions for a member of staff, and the only
 * decision in the module taken over the head of the person holding the object.
 * A class of its own for the reason the two beside it are: the authorisation
 * question — does this account hold `DecideLostFoundDisputes` in this
 * dormitory — is neither of the other two, and a single class choosing between
 * three is the arrangement a later edit gets wrong silently.
 *
 * **`upheld` is a required boolean and not two routes**, which is the one
 * place this module departs from the shape the guest and maintenance modules
 * use for a decision. There, `approve` and `reject` are separate routes
 * because they are separate acts with separate bodies. Here they are one act —
 * the warden read the claim, the refusal and the marks, and says which of the
 * two residents is right — and the body is the same either way. A reason is
 * required in both directions for the same reason FR-37 requires one on a
 * refusal: this decision overrides a person who was entitled to make it, and
 * an override nobody explained is one nobody can answer.
 */
final class JudgeLostFoundClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $claim = $this->route('lostFoundClaim');

        return $claim instanceof LostFoundClaim
            && $this->user()?->can('judge', $claim) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'upheld' => ['required', 'boolean'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
            // §2.4.4: an acceptance names the handover point, whoever made it.
            'handover_point' => ['required_if:upheld,1,true', 'nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'A decision that overrides the person holding the object is stated with a reason.',
            'handover_point.required_if' => 'Say where the owner should collect it: the claimant is told this and nothing else.',
        ];
    }

    public function upheld(): bool
    {
        return (bool) $this->validated('upheld');
    }

    public function handoverPoint(): ?string
    {
        $point = $this->validated('handover_point');

        return is_string($point) && trim($point) !== '' ? trim($point) : null;
    }

    public function note(): string
    {
        return trim((string) $this->validated('note'));
    }
}
