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
            /*
             * The consent history is loaded beside the grants so that the
             * sign-in answer can say which consent is outstanding (FR-35,
             * first criterion). One query, at the one moment a client needs to
             * know whether to draw the consent screen next.
             */
            'user' => new UserResource($this->user->load(['roleGrants.role', 'consentRecords'])),
        ];
    }
}
