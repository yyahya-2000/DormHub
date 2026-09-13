<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-42: `POST /api/v1/buildings/{building}/residents/{resident}/credential`.
 *
 * The same gate as issuing the account, and deliberately the same one. Sending
 * a resident a fresh one-time code is the same act as sending them the first —
 * the warden or the manager of this dormitory does it, the code goes to the
 * address on the account and to nowhere else, and the person who asks for it
 * never sees it.
 *
 * Both refusals that are particular to this route — the account is not a
 * resident here, the account already has a password — are the service's, not
 * this class's: they are questions about the state of the account rather than
 * about who is asking, and §3.3.3 keeps those apart.
 */
final class ReissueResidentCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('issueResidentAccount', $building) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
