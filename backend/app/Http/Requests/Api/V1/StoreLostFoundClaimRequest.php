<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\LostFoundItemKind;
use App\Models\LostFoundItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-26: `POST /api/v1/lost-found/{lostFoundItem}/claims`.
 *
 * **«A validation rule refuses a claim on one's own entry»** (§4.6.2), and it
 * is a rule here rather than a check in the policy on purpose. A 403 would say
 * «you may not do this», which is untrue — the account may claim any number of
 * finds — and would be written to the audit log as `access.denied`, which this
 * is not. What is wrong is the object the request names, so the answer is a
 * 422 and the message says which.
 *
 * The same argument settles the other rule: only a **find** admits a claim. A
 * claim is the sentence «that is mine», and there is nothing to say it about a
 * notice whose author has lost something and is holding nothing.
 *
 * The identifying marks are required and have a floor. FR-26 is «a claim
 * describing identifying features», and a claim that says «it's mine» gives
 * the person holding the object nothing to judge — which makes the refusal
 * they then have to write arbitrary, and the whole peer-to-peer arrangement
 * of §2.5.4 rests on those refusals being answerable.
 */
final class StoreLostFoundClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->item();

        return $item !== null && $this->user()?->can('claim', $item) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * The two rules about the entry rather than about the body, phrased as
     * validation for the reason the class docblock gives.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $item = $this->item();

            if ($item === null) {
                return;
            }

            if ((int) $item->reporter_id === (int) $this->user()?->getKey()) {
                $validator->errors()->add(
                    'lost_found_item_id',
                    'This is your own entry; a claim on it would have nobody to answer it.',
                );
            }

            if ($item->kind !== LostFoundItemKind::Found) {
                $validator->errors()->add(
                    'lost_found_item_id',
                    'This entry is a loss and not a find: there is nothing here to claim.',
                );
            }

            /*
             * One person, one outstanding claim per entry. The partial unique
             * index says the same thing and is what a caller that is not this
             * form runs into; stated here as well so that the second press of
             * a button produces a sentence rather than a constraint violation.
             */
            $mine = $item->claims()
                ->where('claimant_id', $this->user()?->getKey())
                ->outstanding()
                ->exists();

            if ($mine) {
                $validator->errors()->add(
                    'lost_found_item_id',
                    'You already have a claim on this entry waiting for an answer.',
                );
            }
        });
    }

    public function item(): ?LostFoundItem
    {
        $item = $this->route('lostFoundItem');

        return $item instanceof LostFoundItem ? $item : null;
    }

    public function message(): string
    {
        return trim((string) $this->validated('message'));
    }
}
