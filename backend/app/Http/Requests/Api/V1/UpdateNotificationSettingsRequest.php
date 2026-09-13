<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\NotificationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * FR-34: `PUT /api/v1/notification-settings`.
 *
 * The payload is a map of category to boolean. Its **keys** are the part
 * ordinary rules cannot describe, so they are checked against the enumeration
 * in `after()` — which also means a category added by a later increment is
 * accepted here on the day it is added, with no edit to this class.
 *
 * A partial map is accepted on purpose. The screen sends what it changed, and
 * a client that sent only `{"maintenance_status": false}` must not thereby
 * switch everything else back on.
 *
 * Whether a mandatory category may be switched off is **not** decided here. It
 * is one rule and it lives in `App\Services\NotificationPreferences`, where a
 * console command, a seeder and any future import path reach it as well; a
 * copy of it in a form request would be a second place to forget.
 */
final class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The settings of one's own account. Nothing belonging to anyone else
        // appears in this request: the person comes from the token, never from
        // the payload.
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $categories = $this->input('categories');

                if (! is_array($categories)) {
                    return;
                }

                foreach (array_keys($categories) as $key) {
                    if (NotificationCategory::tryFrom((string) $key) === null) {
                        $validator->errors()->add(
                            'categories.'.$key,
                            sprintf('«%s» is not a notification category.', $key),
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function choices(): array
    {
        /** @var array<string, mixed> $categories */
        $categories = $this->validated()['categories'] ?? [];

        return array_map(
            static fn (mixed $enabled): bool => filter_var($enabled, FILTER_VALIDATE_BOOL),
            $categories,
        );
    }
}
