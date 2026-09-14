<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MaintenanceUrgency;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-37, the warden's triage: accept with a date, refuse with a reason, or
 * carry the work forward.
 *
 * **The two impossibilities of FR-37 are both here, and that is where they
 * belong.** «Rejection without a reason is impossible» and «acceptance without
 * a planned completion date is impossible» are properties of the input, and an
 * impossibility stated in a service answers 500 where the client deserves a
 * 422 naming the field. Each is also stated in `MaintenanceService` as a
 * required argument and in the table as a CHECK constraint — three statements
 * of one rule, none of them redundant: the form is what a client reads, the
 * signature is what another caller cannot walk past, and the constraint is
 * what neither of them can talk its way around.
 *
 * One request class for four routes, because the authorisation question is the
 * same one — may this account triage this request — and asking it four times
 * is how the four answers come to differ. What is not the same is the body, so
 * `rules()` reads the route it is serving. It is the arrangement
 * `DecideGuestRequestRequest` uses for the same reason.
 */
final class TriageMaintenanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('maintenanceRequest');

        return $request instanceof MaintenanceRequest
            && $this->user()?->can('triage', $request) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        if ($this->isAcceptance()) {
            return [
                /*
                 * FR-37, second criterion. `after_or_equal:today` and not a
                 * bare date: a planned completion date in the past is not a
                 * plan, and the resident is told a date the moment this
                 * succeeds.
                 */
                'target_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
                // FR-37 names a responsible party beside the date. Optional,
                // because a dormitory whose warden does the work himself has
                // nobody else to name, and a required field would be filled in
                // with his own name on every row.
                'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
                'urgency' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', MaintenanceUrgency::values())],
                'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ];
        }

        if ($this->isRejection()) {
            return [
                'reason' => ['required', 'string', 'min:3', 'max:1000'],
            ];
        }

        return [
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_date.required' => 'A request is accepted with a planned completion date, not without one.',
            'reason.required' => 'A request is refused with a stated reason, not without one.',
        ];
    }

    public function isAcceptance(): bool
    {
        return $this->routeIs('maintenance-requests.accept');
    }

    public function isRejection(): bool
    {
        return $this->routeIs('maintenance-requests.reject');
    }

    public function targetDate(): CarbonInterface
    {
        return CarbonImmutable::parse((string) $this->validated('target_date'))->startOfDay();
    }

    public function assignee(): ?User
    {
        $id = $this->validated('assigned_to');

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }

    public function urgency(): ?MaintenanceUrgency
    {
        $urgency = $this->validated('urgency');

        return is_string($urgency) ? MaintenanceUrgency::from($urgency) : null;
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
