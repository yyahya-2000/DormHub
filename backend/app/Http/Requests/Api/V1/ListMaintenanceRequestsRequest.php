<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\MaintenanceQueue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The resident's own list: `GET /api/v1/maintenance-requests`.
 *
 * **The route carries no building parameter and no person parameter, and the
 * absence is the protection.** The list is filtered by the identifier of the
 * token, so there is no way to phrase a request for somebody else's defects —
 * the boundary is a missing parameter rather than a policy somebody has to
 * remember to call.
 *
 * The parameters are the same two the warden's queue takes, and deliberately:
 * the resident's screen has the same two lists, his open requests and the ones
 * that are finished with.
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
            'scope' => ['sometimes', 'string', 'in:open,archive'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.MaintenanceQueue::MAX_PAGE_SIZE],
        ];
    }

    public function archived(): bool
    {
        return $this->query('scope') === 'archive';
    }

    public function perPage(): int
    {
        $perPage = $this->query('per_page');

        return is_numeric($perPage)
            ? min(MaintenanceQueue::MAX_PAGE_SIZE, max(1, (int) $perPage))
            : (int) config('dormitory.maintenance.queue_page_size');
    }
}
