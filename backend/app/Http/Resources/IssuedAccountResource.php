<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Identity\IssuedAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-42: the answer to `POST /buildings/{building}/residents`, and the one
 * place a generated password is ever readable.
 *
 * It carries what the office has to print and hand over: the person, the
 * address they sign in with, the password, the dormitory, and the role the
 * account was given. The client renders the sheet; nothing here is drawn by
 * the server, and no copy of this answer is kept anywhere.
 *
 * Reading the account afterwards — `GET /buildings/{building}/users` and every
 * other route — answers with `UserResource`, which has no password field at
 * all. There is no second chance to see it.
 *
 * @mixin IssuedAccount
 */
final class IssuedAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'password' => $this->password,
            'user' => new UserResource($this->user),
            'building' => [
                'id' => $this->building->getKey(),
                'name' => $this->building->name,
            ],
        ];
    }
}
