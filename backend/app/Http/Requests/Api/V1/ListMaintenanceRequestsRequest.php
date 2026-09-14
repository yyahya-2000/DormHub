<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceRequestStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The resident's own list: `GET /api/v1/maintenance-requests`.
 *
 * **The route carries no building parameter and no person parameter, and the
 * absence is the protection.** The list is filtered by the identifier of the
 * token, so there is no way to phrase a request for somebody else's defects —
 * the boundary is a missing parameter rather than a policy somebody has to
 * remember to call. The warden's queue is a different route, a sub-resource of
 * the building, and it is decided on the building object; the guest module
 * puts both behind one route and this module deliberately does not, because
 * here the two lists have nothing in common but the table they read.
 */
final class ListMaintenanceRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The caller's own requests, and nothing beyond a session is needed to
        // read them.
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:'.implode(',', MaintenanceRequestStatus::values())],
            'category' => ['sometimes', 'string', 'in:'.implode(',', MaintenanceCategory::values())],
        ];
    }

    public function status(): ?MaintenanceRequestStatus
    {
        $status = $this->query('status');

        return is_string($status) ? MaintenanceRequestStatus::tryFrom($status) : null;
    }

    public function category(): ?MaintenanceCategory
    {
        $category = $this->query('category');

        return is_string($category) ? MaintenanceCategory::tryFrom($category) : null;
    }
}
