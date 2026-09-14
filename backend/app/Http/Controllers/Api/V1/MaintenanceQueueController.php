<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShowMaintenanceQueueRequest;
use App\Models\Building;
use App\Services\MaintenanceQueue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-40, «Building maintenance queue».
 *
 * A sub-resource of the building, and that is the whole of the horizontal
 * boundary: the dormitory is a path parameter, `ShowMaintenanceQueueRequest`
 * decides on the object, and `MaintenanceQueue` begins every query from
 * `inBuilding()`. A warden of block 1 asking for block 2 is refused at the
 * form request with 403, and the refusal is written to the audit log as
 * `access.denied` by the handler in bootstrap/app.php. There is no code path
 * in which a request of another dormitory is in the result set to be filtered
 * out afterwards.
 *
 * The export is this route with `format=csv` rather than a route of its own,
 * for the reason `VisitRegisterController` gives: two routes would be two
 * queries with the same chance of disagreeing about what «open» means.
 */
final class MaintenanceQueueController extends Controller
{
    public function index(
        ShowMaintenanceQueueRequest $request,
        Building $building,
        MaintenanceQueue $queue,
    ): JsonResponse|Response {
        $now = CarbonImmutable::now();
        $format = $request->exportFormat();
        $page = $request->page();
        $perPage = $queue->pageSize();

        $requests = $queue->page(
            building: $building,
            status: $request->status(),
            category: $request->category(),
            minimumAgeDays: $request->minimumAgeDays(),
            onlyOverdue: $request->onlyOverdue(),
            from: $request->from(),
            until: $request->until(),
            page: $page,
            perPage: $perPage,
        );

        $rows = $queue->rows($requests, $now);

        $total = $queue->count(
            building: $building,
            status: $request->status(),
            category: $request->category(),
            minimumAgeDays: $request->minimumAgeDays(),
            onlyOverdue: $request->onlyOverdue(),
            from: $request->from(),
            until: $request->until(),
        );

        /*
         * The period the export is recorded against. Absent bounds mean «the
         * whole queue», and the log says so by naming the dormitory's own
         * span rather than inventing a month nobody asked for.
         */
        $from = $request->from() ?? ($requests->last()?->created_at ?? $now);
        $until = $request->until() ?? $now;

        $queue->recordExport(
            viewer: $request->user(),
            building: $building,
            from: $from,
            until: $until,
            format: $format,
            rows: $rows->count(),
            ipAddress: $request->ip(),
        );

        if ($format === 'csv') {
            return response(
                $queue->toCsv($rows),
                200,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => sprintf(
                        'attachment; filename="maintenance-queue-%d-%s.csv"',
                        $building->getKey(),
                        $now->toDateString(),
                    ),
                ],
            );
        }

        return response()->json([
            'data' => $rows->values()->all(),
            'meta' => [
                'building_id' => $building->getKey(),
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'overdue_after_days' => $queue->overdueAfterDays(),
                'columns' => MaintenanceQueue::COLUMNS,
            ],
        ]);
    }
}
