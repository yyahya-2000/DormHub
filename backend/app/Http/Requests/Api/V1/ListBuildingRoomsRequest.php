<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-02: `GET /api/v1/buildings/{id}/rooms`, the route §3.3.6 assigns to
 * `RoomQuery`. The building comes from the path and the policy decides on that
 * object, so a warden asking about a neighbouring dormitory is refused before
 * a single row is read.
 *
 * Two filters and a page. `q` matches part of a room number, `free=1` keeps the
 * rooms somebody could still be moved into; both are asked of the database, so
 * a client never pages through a dormitory looking for the rooms it wanted.
 */
final class ListBuildingRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('viewRooms', $building) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:32'],
            'free' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('dormitory.housing.max_page_size')],
        ];
    }

    public function search(): ?string
    {
        $needle = $this->query('q');

        return is_string($needle) && trim($needle) !== '' ? trim($needle) : null;
    }

    /**
     * Only the rooms with a place somebody could move into today.
     */
    public function onlyFree(): bool
    {
        return $this->boolean('free');
    }

    public function perPage(): int
    {
        $asked = $this->query('per_page');
        $max = (int) config('dormitory.housing.max_page_size');

        return is_numeric($asked)
            ? max(1, min($max, (int) $asked))
            : (int) config('dormitory.housing.page_size');
    }
}
