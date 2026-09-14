<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Guests\TimeWindow;
use App\Models\Building;
use App\Models\GuestRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-16, `POST /api/v1/guest-requests`.
 *
 * **FR-16's acceptance criteria are settled here**, before the
 * service is reached, because every one of them is a property of the input and
 * 422 is the honest answer to input (§3.3.3). The service is left with what
 * only it can know — the state of the register, the quota, the decision.
 *
 * **The window is read from the BUILDING row and from nowhere else.** That is
 * NFR-09 in one method: a test that sets `visiting_from` to 10:00 changes what
 * this validator accepts without a line of code moving, and a university whose
 * rules close the dormitory at 22:00 edits a column. Writing `'23:00'` into a
 * rule here would have been shorter and would have made clause 2.2 of *this*
 * university's rules a property of the program.
 *
 * **The guest is a name and a time and nothing else.** No document, no purpose
 * of the visit: the paper is compared with the person at the desk (§2.7.1).
 */
final class StoreGuestRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->building();

        if ($building === null) {
            // Nothing to decide on; the missing building is reported as a 422
            // by the rules below, which is what it is.
            return true;
        }

        return $this->user()?->can('create', [GuestRequest::class, $building]) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'guest_full_name' => ['required', 'string', 'min:2', 'max:255'],
            'visit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'planned_from' => ['required', 'date_format:H:i'],
            'planned_to' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * The three rules that need two fields and a database row to decide, and
     * so cannot be written as a rule string.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $building = $this->building();

            if ($building === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $visitDate = $this->visitDate();
            $asked = TimeWindow::on(
                $visitDate,
                (string) $this->input('planned_from'),
                (string) $this->input('planned_to'),
            );

            if ($this->input('planned_from') === $this->input('planned_to')) {
                $validator->errors()->add(
                    'planned_to',
                    'The visit has to last some time: the end of the interval repeats its start.',
                );

                return;
            }

            $regime = $building->visitingWindowOn($visitDate);

            // FR-16, second criterion. The comparison is made on moments and
            // not on strings, so a dormitory whose window runs past midnight
            // is handled by the same line rather than by an exception to it.
            if (! $regime->covers($asked)) {
                $validator->errors()->add(
                    'planned_to',
                    sprintf(
                        'This dormitory admits guests %s. The interval asked for is %s.',
                        $regime->format(),
                        $asked->format(),
                    ),
                );
            }

            // FR-16, first criterion. Zero hours — the default — means the
            // dormitory asks for no notice, which is what the HSE rules of
            // internal order actually say; the test that exercises the
            // criterion sets the column.
            $leadTime = $building->guestLeadTime();
            $earliest = CarbonImmutable::now()->add($leadTime);

            if ($asked->from->lessThan($earliest)) {
                $validator->errors()->add(
                    'visit_date',
                    $leadTime->totalHours > 0
                        ? sprintf(
                            'This dormitory asks for %d hour(s) of notice: the earliest a visit can now begin is %s.',
                            (int) $leadTime->totalHours,
                            $earliest->format('Y-m-d H:i'),
                        )
                        : 'The interval has already begun. A visit cannot be asked for in the past.',
                );
            }
        });
    }

    public function building(): ?Building
    {
        $id = $this->input('building_id');

        return is_numeric($id) ? Building::query()->find((int) $id) : null;
    }

    public function visitDate(): CarbonInterface
    {
        return CarbonImmutable::parse((string) $this->input('visit_date'))->startOfDay();
    }

    public function guestFullName(): string
    {
        return trim((string) $this->validated('guest_full_name'));
    }

    public function plannedFrom(): string
    {
        return (string) $this->validated('planned_from').':00';
    }

    public function plannedTo(): string
    {
        return (string) $this->validated('planned_to').':00';
    }
}
