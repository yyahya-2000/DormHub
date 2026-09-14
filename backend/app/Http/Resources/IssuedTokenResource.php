<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Identity\IssuedToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IssuedToken
 */
final class IssuedTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'identity_provider' => $this->provider,
            'user' => new UserResource($this->user->load(['roleGrants.role'])),
        ];
    }
}
