<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-21, first criterion: the register over an arbitrary period.
 *
 * *Arbitrary* is taken literally — any two dates, as long as the first is not
 * after the second. What is not arbitrary is the page: the register of a
 * dormitory over an academic year is tens of thousands of rows, and a client
 * that asked for all of them at once would get a timeout rather than an
 * answer.
 *
 * The default period is the current month, which is what somebody opening the
 * screen without thinking about it wants, and is short enough that the default
 * can never be the expensive query.
 */
final class ListVisitRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building
            && $this->user()?->can('viewVisitRegister', $building) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'until' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('dormitory.guests.max_page_size')],
        ];
    }

    public function from(): CarbonInterface
    {
        $from = $this->query('from');

        return is_string($from) && $from !== ''
            ? CarbonImmutable::parse($from)->startOfDay()
            : CarbonImmutable::now()->startOfMonth();
    }

    public function until(): CarbonInterface
    {
        $until = $this->query('until');

        return is_string($until) && $until !== ''
            ? CarbonImmutable::parse($until)->endOfDay()
            : CarbonImmutable::now()->endOfDay();
    }

    /**
     * The page size asked for, capped — a list endpoint whose page size the
     * caller sets without a ceiling is a way to ask the database for the whole
     * table in one query.
     */
    public function perPage(): int
    {
        $asked = $this->query('per_page');
        $max = (int) config('dormitory.guests.max_page_size');

        return is_numeric($asked)
            ? max(1, min($max, (int) $asked))
            : (int) config('dormitory.guests.page_size');
    }
}
