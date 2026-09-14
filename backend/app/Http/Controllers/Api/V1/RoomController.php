<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListBuildingRoomsRequest;
use App\Http\Requests\Api\V1\ShowBuildingFloorsRequest;
use App\Http\Requests\Api\V1\ShowRoomRequest;
use App\Http\Requests\Api\V1\StoreRoomRequest;
use App\Http\Requests\Api\V1\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Building;
use App\Models\Room;
use App\Services\RoomQuery;
use App\Services\RoomRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FR-02, the register of rooms. Every action is a form request, one service
 * call and a resource — no `if` that depends on a business rule lives here
 * (§3.3.3).
 */
final class RoomController extends Controller
{
    public function index(
        ListBuildingRoomsRequest $request,
        Building $building,
        RoomQuery $rooms,
    ): AnonymousResourceCollection {
        return RoomResource::collection(
            $rooms->ofBuilding(
                viewer: $request->user(),
                building: $building,
                search: $request->search(),
                onlyFree: $request->onlyFree(),
                perPage: $request->perPage(),
                ipAddress: $request->ip(),
            )
        );
    }

    /**
     * FR-02: the floors of this dormitory, each with its rooms and places
     * counted. One aggregate query, so the summary of a twelve-storey block
     * costs what the summary of a single floor does.
     *
     * @return array{data: list<array<string, int>>}
     */
    public function floors(
        ShowBuildingFloorsRequest $request,
        Building $building,
        RoomQuery $rooms,
    ): array {
        return ['data' => $rooms->floorsOf($building)];
    }

    public function store(
        StoreRoomRequest $request,
        Building $building,
        RoomRegistry $registry,
    ): JsonResponse {
        $room = $registry->createRoom($request->user(), $building, $request->payload(), $request->ip());

        return RoomResource::make($room->load('beds'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ShowRoomRequest $request, Room $room, RoomQuery $rooms): RoomResource
    {
        return RoomResource::make($rooms->card($room));
    }

    public function update(UpdateRoomRequest $request, Room $room, RoomRegistry $registry): RoomResource
    {
        return RoomResource::make(
            $registry->updateRoom($request->user(), $room, $request->payload(), $request->ip())->load('beds')
        );
    }
}
