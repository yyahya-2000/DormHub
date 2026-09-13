<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditResult;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the audit record (FR-33, NFR-14).
 *
 * Two rules from §3.9.6 are realised here. The record format is
 * «who, what, over which object, when, from which IP, result», and every one
 * of the six is a column rather than a phrase inside a message. And the record
 * is written **inside the transaction of the change it describes**: this class
 * opens no transaction of its own, so a caller that wraps the change in
 * DB::transaction gets the log entry committed or rolled back with it.
 *
 * Nothing here ever updates or deletes. The log is append-only in code, and
 * the migration of §4.4.4 revokes the same two rights in the database.
 */
final class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        AuditAction $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $payload = [],
        AuditResult $result = AuditResult::Success,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'payload' => $payload === [] ? null : $payload,
            'ip_address' => $ipAddress,
            'result' => $result,
        ]);
    }
}
