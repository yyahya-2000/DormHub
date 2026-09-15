<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceRequestStatus;
use App\Models\Builders\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MAINTENANCE_WORK_LOG of the ER model (§3.4.3), append-only by §3.4.1's fifth
 * decision and by §4.4.4.
 *
 * The class is `AuditLog` with a different set of columns, and that is the
 * point: the same three mechanisms hold it. The `booted` hooks refuse the
 * single-record path — `$row->update(...)`, `$row->delete()` — and see nothing
 * else; a mass update never loads a record and never fires an event, so the
 * builder has to refuse it; and what neither can see, `DB::table(...)`, raw SQL
 * and TRUNCATE, is what the revoked privilege of the migration is there for.
 *
 * `from_status` null means the row records a submission rather than a move,
 * and `actor_id` null means nobody decided — the scheduled closure of FR-39.
 * Both are read by `describe()`, which is the one place that has to make a
 * sentence out of them.
 */
#[Fillable(['maintenance_request_id', 'actor_id', 'from_status', 'to_status', 'comment', 'created_at'])]
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class MaintenanceWorkLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'maintenance_request_id' => 'integer',
            'actor_id' => 'integer',
            'from_status' => MaintenanceRequestStatus::class,
            'to_status' => MaintenanceRequestStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MaintenanceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    /**
     * The person who moved the request. Null for the scheduled closure of
     * FR-39, which is the log's way of saying that the calendar decided.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Whether this is the row the request was born with.
     */
    public function isTheSubmission(): bool
    {
        return $this->from_status === null;
    }

    /**
     * The entry as a sentence, for the history shown beside the request.
     *
     * It is a method here rather than a string built in the API resource
     * because the console commands print it too, and a log whose wording
     * depends on which caller asked is a log two readers describe differently.
     */
    public function describe(): string
    {
        if ($this->isTheSubmission()) {
            return sprintf('Filed as «%s»', $this->to_status?->label() ?? 'unknown');
        }

        return sprintf(
            '«%s» → «%s»',
            $this->from_status?->label() ?? 'unknown',
            $this->to_status?->label() ?? 'unknown',
        );
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }
}
