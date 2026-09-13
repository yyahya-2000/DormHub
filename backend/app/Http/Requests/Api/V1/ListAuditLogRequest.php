<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-33: the log is read by the administrator and by nobody else.
 */
final class ListAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AuditLog::class) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'nullable', Rule::enum(AuditAction::class)],
        ];
    }

    public function action(): ?AuditAction
    {
        $value = $this->query('action');

        return is_string($value) && $value !== ''
            ? AuditAction::from($value)
            : null;
    }
}
