<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Residency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-05: recording the end of a residency.
 *
 * The ground is `required`, not `nullable`, and that is the first acceptance
 * criterion taken literally — «termination of residency is recorded with a
 * ground and a date». A termination with an empty reason is the same defect
 * §2.4 catalogues for a rejected guest request, and it is refused in the same
 * place.
 *
 * The date defaults to today and may be set forward: a notice given on the
 * 1st for the 15th is normal, and FR-05's third criterion is written for
 * exactly that case. It may not be set before the move-in date; the database
 * says the same thing through `residencies_period_ordered`.
 */
final class TerminateResidencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $residency = $this->route('residency');

        return $residency instanceof Residency
            && $this->user()?->can('terminate', $residency) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $residency = $this->route('residency');

        return [
            'ground' => ['required', 'string', 'min:3', 'max:255'],
            'moved_out_at' => [
                'sometimes',
                'date_format:Y-m-d',
                ...($residency instanceof Residency && $residency->moved_in_at !== null
                    ? ['after_or_equal:'.$residency->moved_in_at->toDateString()]
                    : []),
            ],
        ];
    }

    public function ground(): string
    {
        return (string) $this->validated('ground');
    }

    public function movedOutAt(): CarbonInterface
    {
        $value = $this->validated('moved_out_at');

        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
    }
}
