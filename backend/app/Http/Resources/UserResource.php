<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Consent\ConsentText;
use App\Models\User;
use App\Services\ConsentRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The outward shape of a user. The password hash and the remember token are
 * hidden on the model and absent here; the JSON contract is written out field
 * by field rather than derived from the table, which is the point of a Data
 * Transfer Object (§3.3.3).
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            /*
             * FR-42. An account created for an incoming resident is waiting
             * for its first password, and the client has to know which screen
             * to draw. The flag says that much and no more: the secret itself
             * never appears in this resource, and there is no field here it
             * could appear in.
             */
            'password_change_required' => (bool) $this->password_change_required,
            /*
             * FR-35, first criterion: the consent has to be *displayed* at
             * first sign-in, and the client can only display it if the answer
             * to the sign-in says one is outstanding. The field carries the
             * document codes and not the texts: the texts are long, they are
             * fetched from `GET /consents/pending`, and putting them here
             * would mean every `GET /auth/me` shipped a legal document.
             *
             * It appears only where the history was loaded — the sign-in
             * answer and `GET /auth/me` — so the listings of people, which use
             * this same resource, ask the consent table nothing.
             */
            'consent_required' => $this->when(
                $this->resource->relationLoaded('consentRecords'),
                fn (): array => array_map(
                    static fn (ConsentText $text): string => $text->document->value,
                    app(ConsentRegistry::class)->pendingFor($this->resource),
                ),
            ),
            'roles' => RoleGrantResource::collection($this->whenLoaded('roleGrants')),
        ];
    }
}
