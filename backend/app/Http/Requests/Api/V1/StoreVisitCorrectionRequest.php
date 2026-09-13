<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\GuestVisit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-21, second criterion: «entries are immutable; a correction is made as a
 * correcting entry».
 *
 * The route writes nothing to `guest_visits`. It writes a row to `audit_logs`
 * naming the visit, and the register export carries it alongside the entry it
 * corrects — so an evening that was written down wrongly reads as «this is
 * what was recorded, and this is what was said about it afterwards, by whom
 * and when», which is what a numbered paper journal gives and an editable row
 * does not.
 *
 * The correction is free text and is required to say something. There is no
 * structured «change this field to that value», and that is the point: a
 * correction that could be applied would be an edit with extra steps.
 */
final class StoreVisitCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visit = $this->route('guestVisit');

        return $visit instanceof GuestVisit
            && $this->user()?->can('correct', $visit) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'correction' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function correction(): string
    {
        return trim((string) $this->validated('correction'));
    }
}
