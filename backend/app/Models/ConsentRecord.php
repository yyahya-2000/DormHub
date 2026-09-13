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
    'guest_request_id',
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
            'user_id' => 'integer',
            'guest_request_id' => 'integer',
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
     * The other subject a consent can have (§2.7.1, FR-35).
     *
     * A guest has no account, and the consent taken from them at the post
     * therefore hangs on the request they arrived on. The CHECK constraint
     * `consent_records_one_subject` makes this relation and `user()` mutually
     * exclusive: exactly one of them is ever set.
     *
     * @return BelongsTo<GuestRequest, $this>
     */
    public function guestRequest(): BelongsTo
    {
        return $this->belongsTo(GuestRequest::class, 'guest_request_id');
    }

    /**
     * Whether this is a guest's consent rather than an account holder's. The
     * difference is not bookkeeping: for a guest, consent is the sole ground
     * for the processing (§2.7.1), so its absence stops the entry being
     * recorded at all.
     */
    public function belongsToAGuest(): bool
    {
        return $this->guest_request_id !== null;
    }

    /**
     * @param  Builder<ConsentRecord>  $query
     * @return Builder<ConsentRecord>
     */
    public function scopeForGuestRequest(Builder $query, GuestRequest|int $request): Builder
    {
        return $query->where(
            'guest_request_id',
            $request instanceof GuestRequest ? $request->getKey() : $request,
        );
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
