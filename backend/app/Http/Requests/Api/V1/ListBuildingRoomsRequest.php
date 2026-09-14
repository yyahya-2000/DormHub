<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AcceptsBooleanQueryFlags;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-02: `GET /api/v1/buildings/{id}/rooms`, the route §3.3.6 assigns to
 * `RoomQuery`. The building comes from the path and the policy decides on that
 * object, so a warden asking about a neighbouring dormitory is refused before
 * a single row is read.
 *
 * Three filters and a page. `q` matches part of a room number, `free=1` keeps
 * the rooms somebody could still be moved into, and `floor` keeps one storey;
 * all three are asked of the database, so a client never pages through a
 * dormitory looking for the rooms it wanted.
 *
 * `floor` is the one the floor card of FR-02 needs. Without it that screen read
 * the whole register a hundred rows at a time and threw away everything on the
 * other storeys, which is a query per hundred rooms to answer a question the
 * `(building_id, floor)` index answers in one.
 */
final class ListBuildingRoomsRequest extends FormRequest
{
    use AcceptsBooleanQueryFlags;

    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('viewRooms', $building) === true;
    }

    /**
     * `free=true` is the spelling the generated client sends, and it used to
     * be a 422 — the same defect acceptance found on the announcement feed.
     * See `AcceptsBooleanQueryFlags`.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseBooleanFlags('free');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:32'],
            'free' => ['sometimes', 'boolean'],
            // The same bounds the room form accepts, so a storey that could
            // never have been created cannot be asked for either.
            'floor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
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

    /**
     * The storey asked for, or null for the whole dormitory. An empty value is
     * the same as an absent one: a client that clears the filter sends the
     * parameter back empty rather than dropping it from the query string.
     */
    public function floor(): ?int
    {
        $floor = $this->query('floor');

        return is_numeric($floor) ? (int) $floor : null;
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
