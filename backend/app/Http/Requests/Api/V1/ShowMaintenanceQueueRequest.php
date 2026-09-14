<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceRequestStatus;
use App\Models\Building;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-40, `GET /api/v1/buildings/{building}/maintenance-queue`.
 *
 * The three filters of the first acceptance criterion — status, category and
 * age — and the arbitrary period of the third, in one request class, because
 * the export is the queue with a period and a format rather than a screen of
 * its own. A second route would have been a second query with the same chance
 * of disagreeing with the first about what «open» means.
 *
 * *Arbitrary* is taken literally: any two dates, as long as the first is not
 * after the second. What is not arbitrary is the page — a dormitory
 * accumulates thousands of requests over an academic year, and a client that
 * asked for all of them at once would get a timeout rather than an answer.
 *
 * The default period is the whole of the queue rather than the current month,
 * and that is the difference from `ExportVisitRegisterRequest`. The register is
 * read by date because a journal is; a queue is read by what is still open, and
 * defaulting to this month would hide exactly the requests FR-40 exists to
 * surface — the old ones.
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
            'status' => ['sometimes', 'string', 'in:'.implode(',', MaintenanceRequestStatus::values())],
            'category' => ['sometimes', 'string', 'in:'.implode(',', MaintenanceCategory::values())],
            'min_age_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'overdue' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'until' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['sometimes', 'string', 'in:json,csv'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function status(): ?MaintenanceRequestStatus
    {
        $status = $this->query('status');

        return is_string($status) ? MaintenanceRequestStatus::tryFrom($status) : null;
    }

    public function category(): ?MaintenanceCategory
    {
        $category = $this->query('category');

        return is_string($category) ? MaintenanceCategory::tryFrom($category) : null;
    }

    public function minimumAgeDays(): ?int
    {
        $days = $this->query('min_age_days');

        return is_numeric($days) ? max(0, (int) $days) : null;
    }

    public function onlyOverdue(): bool
    {
        return filter_var($this->query('overdue'), FILTER_VALIDATE_BOOL);
    }

    public function from(): ?CarbonInterface
    {
        $from = $this->query('from');

        return is_string($from) && $from !== '' ? CarbonImmutable::parse($from)->startOfDay() : null;
    }

    public function until(): ?CarbonInterface
    {
        $until = $this->query('until');

        return is_string($until) && $until !== '' ? CarbonImmutable::parse($until)->endOfDay() : null;
    }

    /**
     * Named `exportFormat` and not `format`: `Illuminate\Http\Request::format()`
     * already exists, answers the content type the caller will accept, and has
     * a signature of its own. Overriding it produces a fatal at class load —
     * see `ExportVisitRegisterRequest`, where that was found out the hard way.
     */
    public function exportFormat(): string
    {
        return $this->query('format') === 'csv' ? 'csv' : 'json';
    }

    public function page(): int
    {
        $page = $this->query('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }
}
