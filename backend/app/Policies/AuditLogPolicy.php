<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * FR-33: the log belongs to the administrator alone, and nobody edits it.
 * The last two methods state that in the authorisation layer; §4.4.4 states
 * it again in the database, where it holds even if this class is wrong.
 */
final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, AuditLog $log): false
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): false
    {
        return false;
    }
}
