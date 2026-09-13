<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The administrator's view of the log (FR-33, third criterion).
 *
 * §3.9.6 adds one turn of the screw: reading the log is itself a recorded
 * event, so an administrator cannot inspect the record of somebody else
 * without leaving a record of their own.
 */
final readonly class AuditLogReader
{
    public function __construct(
        private AuditRecorder $audit,
        private int $pageSize,
    ) {}

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function page(User $viewer, ?AuditAction $action = null, ?string $ipAddress = null): LengthAwarePaginator
    {
        $page = AuditLog::query()
            ->when($action !== null, fn ($query) => $query->where('action', $action?->value))
            ->with('user')
            ->orderByDesc('id')
            ->paginate($this->pageSize);

        $this->audit->record(
            action: AuditAction::AuditLogViewed,
            actor: $viewer,
            payload: array_filter([
                'filter_action' => $action?->value,
                'page' => $page->currentPage(),
            ], fn ($value) => $value !== null),
            ipAddress: $ipAddress,
        );

        return $page;
    }
}
