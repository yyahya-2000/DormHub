<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LostFoundClaimStatus;
use Database\Factories\LostFoundClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LOST_FOUND_CLAIM of the ER model (§3.4.3): one resident's «that is mine».
 *
 * The model owns two questions, and both of them are about whether a further
 * act is still open rather than about what has already happened.
 *
 * `mayBeReferred()` is FR-26's «the claimant is offered the option of
 * referring the decision to the warden», asked of a row. It is here and not in
 * the policy because the answer is a fact about the claim — declined once, not
 * yet referred — and the policy's question is a different one, whether *this
 * account* is the claimant. Two questions, two places, and the API resource
 * reads the first so a client can draw the offer without guessing.
 *
 * `isOutstanding()` delegates to the status, which is where the definition
 * belongs: the entry's own state is computed from it (§3.5.5's note), and a
 * second definition here would be the one that disagreed.
 */
#[Fillable([
    'lost_found_item_id',
    'claimant_id',
    'message',
    'status',
    'handover_point',
    'decided_by',
    'decided_at',
    'decision_note',
    'referred_at',
])]
class LostFoundClaim extends Model
{
    /** @use HasFactory<LostFoundClaimFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lost_found_item_id' => 'integer',
            'claimant_id' => 'integer',
            'decided_by' => 'integer',
            'status' => LostFoundClaimStatus::class,
            'decided_at' => 'datetime',
            'referred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LostFoundItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(LostFoundItem::class, 'lost_found_item_id');
    }

    /**
     * The person claiming the object.
     *
     * Their name is serialised and their contacts are not (§3.5.3). The
     * asymmetry with the entry's own author is deliberate: a claim is read by
     * the handful of people party to it — the claimant, whoever is holding the
     * object, and the warden on a referral — and the person handing an object
     * over has to know who to hand it to. The feed is read by the whole
     * dormitory, which is why the entry carries nobody at all.
     *
     * @return BelongsTo<User, $this>
     */
    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_id');
    }

    /**
     * Whoever answered it: the holder of the object, or the warden on a
     * referral. FR-26's first criterion is the difference between those two,
     * and this column is where it is recorded.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOutstanding(): bool
    {
        return $this->status?->isOutstanding() === true;
    }

    /**
     * FR-26: a refusal the claimant did not accept may go to the warden, once.
     */
    public function mayBeReferred(): bool
    {
        return $this->status === LostFoundClaimStatus::Declined
            && $this->referred_at === null;
    }

    /**
     * @param  Builder<LostFoundClaim>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (LostFoundClaimStatus $status): string => $status->value,
            LostFoundClaimStatus::outstanding(),
        ));
    }

    /**
     * @param  Builder<LostFoundClaim>  $query
     */
    public function scopeWithStatus(Builder $query, LostFoundClaimStatus ...$statuses): void
    {
        $query->whereIn('status', array_map(
            static fn (LostFoundClaimStatus $status): string => $status->value,
            $statuses,
        ));
    }
}
