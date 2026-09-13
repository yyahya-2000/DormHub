<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Identity\Credentials;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of the sign-in payload (§3.3.3). Whether the pair is correct is
 * not decided here — that belongs to the identity provider — only whether it
 * is well formed enough to be worth asking about.
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function credentials(): Credentials
    {
        return new Credentials(
            login: (string) $this->string('email'),
            secret: (string) $this->string('password'),
        );
    }
}
