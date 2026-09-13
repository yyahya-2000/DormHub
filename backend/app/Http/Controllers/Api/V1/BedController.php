<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBedRequest;
use App\Http\Resources\BedResource;
use App\Models\Room;
use App\Services\RoomRegistry;
use Illuminate\Http\JsonResponse;

/**
 * FR-02: registering a place in a room. The capacity rule is the service's,
 * and the rejection it raises becomes a 422 naming the free remainder — see
 * bootstrap/app.php, which is the one place allowed to know both the domain
 * exception and the status code.
 */
final class BedController extends Controller
{
    public function store(StoreBedRequest $request, Room $room, RoomRegistry $registry): JsonResponse
    {
        $bed = $registry->addBed($request->user(), $room, $request->label(), $request->ip());

        return BedResource::make($bed)
            ->response()
            ->setStatusCode(201);
    }
}
