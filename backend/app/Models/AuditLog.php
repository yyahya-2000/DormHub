<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Models\Builders\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AUDIT_LOG of the ER model (§3.4.3), append-only by §4.4.4.
 *
 * The table has a `created_at` and no `updated_at`, and the model refuses an
 * update or a delete outright. The database revokes the same two rights from
 * the application role, so the guarantee does not depend on this class being
 * correct — this is the first of the two levels, not the only one.
 *
 * The refusal is stated twice inside this level as well, because the two
 * statements catch different things. The `booted` hooks below cover the
 * single-record path and see nothing else; a mass update never loads a record
 * and never fires an event, so it is the builder that has to refuse it.
 */
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'payload', 'ip_address', 'result'])]
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'result' => AuditResult::class,
            'payload' => 'array',
            'subject_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }
}
