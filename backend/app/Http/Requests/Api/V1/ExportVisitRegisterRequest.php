<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-21, first criterion: «the register exports over an arbitrary period».
 *
 * *Arbitrary* is taken literally — any two dates, in either order of
 * magnitude, as long as the first is not after the second. What is not
 * arbitrary is the page: the register of a dormitory over an academic year is
 * tens of thousands of rows, and a client that asked for all of them at once
 * would get a timeout rather than an answer, so the page size is
 * configuration and the response says how many rows the period holds.
 *
 * The default period is the current month, which is what somebody opening the
 * screen without thinking about it wants, and is short enough that the default
 * can never be the expensive query.
 */
final class ExportVisitRegisterRequest extends FormRequest
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
            'format' => ['sometimes', 'string', 'in:json,csv'],
            'page' => ['sometimes', 'integer', 'min:1'],
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
     * Named `exportFormat` and not `format`, which is the obvious name and is
     * taken: `Illuminate\Http\Request::format()` already exists, answers the
     * content type the caller will accept, and has a signature of its own.
     * Overriding it produced a fatal at class load — a form request that could
     * not be constructed, reported by PHPUnit as a process that ended without
     * saying why.
     */
    public function exportFormat(): string
    {
        $format = $this->query('format');

        return $format === 'csv' ? 'csv' : 'json';
    }

    public function page(): int
    {
        $page = $this->query('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }
}
