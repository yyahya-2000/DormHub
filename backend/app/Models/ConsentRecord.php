<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentDocument;
use Database\Factories\ConsentRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CONSENT_RECORD of the ER model (§3.4.3): one act of consent, with the text
 * revision it was given against and the withdrawal if there was one.
 *
 * The model carries no `delete` of its own and is never updated except to set
 * `revoked_at` — see the migration for why the history has to survive the
 * withdrawal.
 */
#[Fillable([
    'user_id',
    'document_code',
    'document_revision',
    'accepted_at',
    'revoked_at',
    'ip_address',
])]
class ConsentRecord extends Model
{
    /** @use HasFactory<ConsentRecordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_code' => ConsentDocument::class,
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Consent that stands today: given, and not withdrawn.
     *
     * @param  Builder<ConsentRecord>  $query
     * @return Builder<ConsentRecord>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @param  Builder<ConsentRecord>  $query
     * @return Builder<ConsentRecord>
     */
    public function scopeForDocument(Builder $query, ConsentDocument $document): Builder
    {
        return $query->where('document_code', $document->value);
    }

    public function isInForce(): bool
    {
        return $this->revoked_at === null;
    }
}
