<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ConsentRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One act of consent as the personal account reads it (FR-35, third and fourth
 * criteria): the fact, the date, the text revision, and the withdrawal if
 * there was one.
 *
 * The withdrawn rows are shown beside the standing one rather than hidden.
 * They are the history of a legal act, they are what an operator produces when
 * asked on what ground data was processed on a given day, and the person whose
 * data it is has at least as much right to read that history as the operator.
 *
 * @mixin ConsentRecord
 */
final class ConsentRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document' => $this->document_code->value,
            'title' => $this->document_code->title(),
            'revision' => $this->document_revision,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'in_force' => $this->isInForce(),
            'ip_address' => $this->ip_address,
        ];
    }
}
