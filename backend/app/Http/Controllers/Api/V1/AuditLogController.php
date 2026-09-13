<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogReader;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FR-33. Read only: the log has no create, update or delete route, and could
 * not have one — the database has revoked those rights from the application
 * role (§4.4.4).
 */
final class AuditLogController extends Controller
{
    public function index(ListAuditLogRequest $request, AuditLogReader $reader): AnonymousResourceCollection
    {
        return AuditLogResource::collection(
            $reader->page($request->user(), $request->action(), $request->ip())
        );
    }
}
