<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\LostFoundClaim;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-26: the holder of the object answers a claim — `accept` or `decline`.
 *
 * One request class for two routes, because the authorisation question is the
 * same one — is this account holding the object (§2.5.4) — and asking it twice
 * is how the two answers come to differ. What is not the same is the body, so
 * `rules()` reads the route it is serving; it is the arrangement
 * `TriageMaintenanceRequestRequest` uses for the same reason.
 *
 * **The handover point is required on the acceptance**, because §2.4.4 ends
 * that scenario with «the claimant is notified with the handover point». It is
 * stated here for the sake of the 422, as a required argument of
 * `LostFoundService::accept()` so that no other caller can walk past it, and
 * as a CHECK constraint for the sake of everything that is neither.
 *
 * **The reason for a refusal is optional, and that is a decision.** FR-37
 * makes a refusal without a reason impossible for a maintenance request and
 * FR-26 makes no such demand of a claim; the Gherkin of §2.4.4 has the finder
 * decline «because the stated marks do not match» and says nothing about
 * typing. A required field here would be a rule the requirement does not
 * carry, put in front of a resident who is doing the dormitory a favour by
 * answering at all. The claimant is told either way, and the offer of a
 * referral is what the requirement puts in its place.
 */
final class DecideLostFoundClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $claim = $this->route('lostFoundClaim');

        return $claim instanceof LostFoundClaim
            && $this->user()?->can('decide', $claim) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if ($this->isAcceptance()) {
            return [
                'handover_point' => ['required', 'string', 'min:3', 'max:255'],
                'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ];
        }

        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'handover_point.required' => 'Say where the owner should collect it: the claimant is told this and nothing else.',
        ];
    }

    public function isAcceptance(): bool
    {
        return $this->routeIs('lost-found.claims.accept');
    }

    public function handoverPoint(): string
    {
        return trim((string) $this->validated('handover_point'));
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
