<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShowMaintenanceQueueRequest;
use App\Models\Building;
use App\Services\MaintenanceQueue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

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
 * One route and two lists. `scope=archive` reads the finished requests and
 * everything else reads the open ones, so the two cannot come to disagree
 * about what «open» means — which is the argument that used to keep the CSV
 * export on this route, and the export itself is gone.
 */
final class MaintenanceQueueController extends Controller
{
    public function index(
        ShowMaintenanceQueueRequest $request,
        Building $building,
        MaintenanceQueue $queue,
    ): JsonResponse {
        $page = $queue->page(
            building: $building,
            archived: $request->archived(),
            perPage: $request->perPage(),
        );

        $rows = $queue->rows(
            new Collection($page->items()),
            CarbonImmutable::now(),
        );

        return response()->json([
            'data' => $rows->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
