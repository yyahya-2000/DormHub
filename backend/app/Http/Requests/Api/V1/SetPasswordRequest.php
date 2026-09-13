<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * FR-42: `POST /api/v1/auth/password`.
 *
 * Unauthenticated by necessity — the resident has no password yet, so there is
 * nothing to sign in with. What stands in place of a session is the one-time
 * token, which the account received on its own contact and nobody else has.
 *
 * `email` is validated for shape and not for existence. A rule that checked
 * the address against the table would answer, to an unauthenticated caller,
 * which addresses hold an account.
 */
final class SetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function email(): string
    {
        return (string) $this->validated('email');
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }
}
