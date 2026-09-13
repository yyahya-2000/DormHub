<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GuestVisitStatus;
use App\Exceptions\ImmutableRecordException;
use Carbon\CarbonInterface;
use Database\Factories\GuestVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GUEST_VISIT of the ER model (§3.4.3): what actually happened at the post.
 *
 * **Deletion is refused in three places and that is not excessive.** FR-21
 * says entries are immutable and a correction is a correcting entry; NFR-14
 * says the same about the log. So the model refuses a delete, the database
 * refuses it through a trigger, and the service never asks. The model's
 * refusal is the one a developer meets first and the only one that can say
 * why; the trigger is the one that holds when the developer is not in the
 * loop at all.
 *
 * The row is **not** fully append-only, and the difference from `audit_logs`
 * matters. Two later writes are legitimate and expected — the exit, and the
 * overdue mark — and each of them may happen exactly once. That is why the
 * mass-update builder of `AppendOnlyBuilder` is not used here: it would refuse
 * the two writes the register is for.
 */
#[Fillable([
    'guest_request_id',
    'checked_in_at',
    'checked_in_by',
    'checked_out_at',
    'checked_out_by',
    'status',
    'due_at',
    'overdue_notified_at',
    'admitted_on_decision',
    'admission_note',
])]
class GuestVisit extends Model
{
    /** @use HasFactory<GuestVisitFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'in_building',
        'admitted_on_decision' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guest_request_id' => 'integer',
            'checked_in_by' => 'integer',
            'checked_out_by' => 'integer',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'due_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'admitted_on_decision' => 'boolean',
            'status' => GuestVisitStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (GuestVisit $visit): void {
            throw ImmutableRecordException::for(self::class, 'a delete');
        });
    }

    /**
     * @return BelongsTo<GuestRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(GuestRequest::class, 'guest_request_id');
    }

    /**
     * The operator who recorded the entry — the seventh field of FR-21, the
     * one clause 2.1.2 does not ask for and an electronic register cannot do
     * without.
     *
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function isOpen(): bool
    {
        return $this->checked_out_at === null;
    }

    /**
     * Whether the control time has passed with no exit recorded. The question
     * FR-20 asks; `overdue_notified_at` is the separate question of whether
     * anybody has been told.
     */
    public function isOverdueAt(CarbonInterface $moment): bool
    {
        return $this->isOpen()
            && $this->due_at !== null
            && $this->due_at->lessThanOrEqualTo($moment);
    }

    /**
     * Visits with an entry and no exit.
     *
     * @param  Builder<GuestVisit>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('checked_out_at');
    }

    /**
     * @param  Builder<GuestVisit>  $query
     */
    public function scopeInBuilding(Builder $query, Building|int $building): void
    {
        $buildingId = $building instanceof Building ? $building->getKey() : $building;

        $query->whereHas(
            'request',
            fn (Builder $request) => $request->where('building_id', $buildingId)
        );
    }
}
