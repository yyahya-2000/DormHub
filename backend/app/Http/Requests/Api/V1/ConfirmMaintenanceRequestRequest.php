<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-39: the reporter's two answers to «the work is done».
 *
 * A class of its own beside `TriageMaintenanceRequestRequest` because the
 * authorisation question is a different one, and that difference is the point
 * of the module (§3.5.2). Triage asks whether the account holds a capability
 * in a building; this asks whether the account is the person who filed the
 * request. A single class covering both would have had to choose one of them
 * per route, which is the arrangement a later edit gets wrong silently.
 *
 * **The reason for a reopening is optional, and that is a decision.** FR-37
 * makes a refusal without a reason impossible and FR-39 makes no such demand
 * of the reopening; the Gherkin of §2.4.3 has the resident «select “not
 * fixed”» and says nothing about typing. A required field here would be a rule
 * the requirement does not carry, and the resident who has already waited
 * through one repair is the wrong person to put a form in front of. The work
 * log gets a sentence either way — `MaintenanceService::reopen()` supplies
 * «the reporter says the defect is not fixed» when the body is empty — so the
 * history never has a blank row.
 */
final class ConfirmMaintenanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('maintenanceRequest');

        return $request instanceof MaintenanceRequest
            && $this->user()?->can('confirm', $request) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function isReopening(): bool
    {
        return $this->routeIs('maintenance-requests.reopen');
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) && trim($comment) !== '' ? trim($comment) : null;
    }
}
