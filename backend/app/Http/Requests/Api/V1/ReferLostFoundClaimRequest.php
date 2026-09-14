<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\LostFoundClaim;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-26, §2.4.4: «the claimant is offered the option of referring the decision
 * to the warden».
 *
 * A class of its own beside `DecideLostFoundClaimRequest` because the
 * authorisation question is a different one, and the difference is the module
 * (§2.5.4). Deciding a claim asks whether the account is holding the object;
 * referring one asks whether the account is the person who filed it. A single
 * class choosing between the two per route is the arrangement a later edit
 * gets wrong silently.
 *
 * The note is optional. The referral is the claimant repeating a claim the
 * warden can read in full on the row, so a second statement of it is a
 * courtesy rather than a requirement, and FR-26 asks for neither.
 */
final class ReferLostFoundClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $claim = $this->route('lostFoundClaim');

        return $claim instanceof LostFoundClaim
            && $this->user()?->can('refer', $claim) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
