<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-01: the register, listed. The answer is scoped by the service rather than
 * refused here, which is the «empty scoped result» half of FR-07's criterion.
 */
final class ListBuildingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Building::class) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
