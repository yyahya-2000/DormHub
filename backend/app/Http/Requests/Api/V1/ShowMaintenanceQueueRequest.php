<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use App\Services\MaintenanceQueue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-40, `GET /api/v1/buildings/{building}/maintenance-queue`.
 *
 * Two parameters beside the page, and both of them earn their place. `scope`
 * chooses between the open requests and the archive of finished ones, which is
 * the one division the screen has. `per_page` is capped, because a dormitory
 * accumulates thousands of requests over an academic year and a client that
 * asked for all of them at once would get a timeout rather than an answer.
 *
 * The status, category, age, overdue and period filters are gone with the CSV
 * export they were read by. None of them was the question a warden asks, and
 * six filters on a screen with two useful lists is what the MVP was told to
 * stop doing.
 */
final class ShowMaintenanceQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('viewMaintenanceRequests', $building) === true;
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

    public function perPage(): ?int
    {
        $perPage = $this->query('per_page');

        return is_numeric($perPage)
            ? min(MaintenanceQueue::MAX_PAGE_SIZE, max(1, (int) $perPage))
            : null;
    }
}
