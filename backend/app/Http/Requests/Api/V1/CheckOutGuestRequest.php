<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\GuestVisit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-19, `POST /api/v1/checkpoint/check-out`.
 *
 * The visit and not the request, because by now the two have parted company:
 * the thing being closed is the record of an entry, and it is the row the
 * register keeps. The time is the server's and is never taken from the body —
 * an exit time a client could name is an exit time a client could choose, and
 * §3.9.6 has the register standing as evidence of what happened rather than of
 * what was typed.
 */
final class CheckOutGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visit = $this->visit();

        if ($visit === null) {
            return true;
        }

        return $this->user()?->can('checkOut', $visit) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'guest_visit_id' => ['required', 'integer', 'exists:guest_visits,id'],
        ];
    }

    public function visit(): ?GuestVisit
    {
        $id = $this->input('guest_visit_id');

        return is_numeric($id)
            ? GuestVisit::query()->with('request.building')->find((int) $id)
            : null;
    }
}
