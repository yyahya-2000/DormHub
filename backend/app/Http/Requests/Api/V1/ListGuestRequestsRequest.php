<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\GuestRequestStatus;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-17: the duty officer's queue, and the resident's own list.
 *
 * **Two different lists behind one route, and the scope decides which.** With
 * `building` named, this is the queue of a dormitory and the caller needs the
 * capability that reads it; without it, this is «my own requests» and needs
 * nothing beyond a session. Splitting them into two routes would mean two
 * places where the filter could be got wrong, and the filter is the thing that
 * keeps one resident from reading another's guests.
 */
final class ListGuestRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->building();

        if ($building === null) {
            // The caller's own requests. There is no parameter through which
            // one person could ask about another's — see the controller.
            return true;
        }

        return $this->user()?->can('viewGuestRequests', $building) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['sometimes', 'integer', 'exists:buildings,id'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', GuestRequestStatus::values())],
            'visit_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function building(): ?Building
    {
        $id = $this->query('building_id');

        return is_numeric($id) ? Building::query()->find((int) $id) : null;
    }

    public function status(): ?GuestRequestStatus
    {
        $status = $this->query('status');

        return is_string($status) ? GuestRequestStatus::tryFrom($status) : null;
    }

    public function visitDate(): ?string
    {
        $date = $this->query('visit_date');

        return is_string($date) && $date !== '' ? $date : null;
    }
}
