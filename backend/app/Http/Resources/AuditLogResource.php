<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The record format of §3.9.6 as it leaves the API: who, what, over which
 * object, when, from which address, with what result.
 *
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'user' => [
                'id' => $this->user_id,
                'full_name' => $this->whenLoaded('user', fn () => $this->user?->full_name),
            ],
            'subject' => [
                'type' => $this->subject_type !== null ? class_basename($this->subject_type) : null,
                'id' => $this->subject_id,
            ],
            'payload' => $this->payload,
            'ip_address' => $this->ip_address,
            'result' => $this->result->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
