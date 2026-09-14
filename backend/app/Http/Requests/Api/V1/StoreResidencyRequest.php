<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Bed;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-03: `POST /api/v1/residencies`, the route §3.3.6 assigns to
 * `ResidencyService::assign`.
 *
 * The scope is read off the bed. A residency names no building in its payload,
 * and taking one from the request body would let the caller nominate the scope
 * their own authorisation is then checked against. The bed is loaded, its room
 * gives the building, and the warden of **that** building is the one allowed
 * to write the row.
 */
final class StoreResidencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bed = $this->bed();

        if ($bed === null) {
            // Nothing to decide on. Validation reports the missing bed as a
            // 422, which is the honest answer: the request is malformed, not
            // forbidden.
            return true;
        }

        $room = $bed->room()->first();

        return $room !== null && $this->user()?->can('assignResidency', $room) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'bed_id' => ['required', 'integer', 'exists:beds,id'],
            'contract_number' => ['required', 'string', 'max:64'],
            'moved_in_at' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function bed(): ?Bed
    {
        $id = $this->input('bed_id');

        return is_numeric($id) ? Bed::query()->find((int) $id) : null;
    }

    public function resident(): User
    {
        return User::query()->findOrFail((int) $this->validated('user_id'));
    }

    public function movedInAt(): CarbonInterface
    {
        return CarbonImmutable::parse((string) $this->validated('moved_in_at'))->startOfDay();
    }

    public function contractNumber(): string
    {
        return (string) $this->validated('contract_number');
    }
}
