<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * FR-42: `POST /api/v1/auth/password`.
 *
 * Behind the session. The account is created with a password the office hands
 * over on paper, so the person changing it signs in with it first; there is no
 * one-time code and nothing is sent anywhere.
 *
 * `current_password` is the framework's rule of that name and checks the value
 * against the signed-in account's own hash. It is what keeps a stolen token
 * from becoming a stolen account.
 */
final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Named guard: the default one is the session guard, which holds
            // nobody on a token-authenticated route.
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ];
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }
}
