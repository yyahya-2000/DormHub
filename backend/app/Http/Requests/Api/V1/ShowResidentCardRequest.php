<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-06: the resident card. The subject is bound from the path, so the policy
 * is asked about the person and not about a role in the abstract — which is
 * what makes the warden of block 1 fail on a resident of block 2.
 */
final class ShowResidentCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resident = $this->route('resident');

        return $resident instanceof User
            && $this->user()?->can('viewCard', $resident) === true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
