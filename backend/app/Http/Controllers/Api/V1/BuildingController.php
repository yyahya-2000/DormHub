<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteBuildingRequest;
use App\Http\Requests\Api\V1\ListBuildingsRequest;
use App\Http\Requests\Api\V1\ShowBuildingPeopleRequest;
use App\Http\Requests\Api\V1\ShowBuildingRequest;
use App\Http\Requests\Api\V1\StoreBuildingRequest;
use App\Http\Requests\Api\V1\UpdateBuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Http\Resources\UserResource;
use App\Models\Building;
use App\Services\BuildingDirectory;
use App\Services\BuildingRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The register of dormitories (FR-01) and the two routes FR-07 is demonstrated
 * on. Authorisation is settled by the form request that reached each method,
 * so no action contains a check of its own.
 */
final class BuildingController extends Controller
{
    /**
     * FR-01 read side, scoped by FR-07: the administrator sees the register,
     * everybody else sees the buildings their grants name.
     */
    public function index(
        ListBuildingsRequest $request,
        BuildingRegistry $registry,
    ): AnonymousResourceCollection {
        return BuildingResource::collection($registry->visibleTo($request->user()));
    }

    /**
     * FR-01, first criterion. The administrator alone reaches this method; a
     * warden's token is turned away by the form request with 403.
     */
    public function store(StoreBuildingRequest $request, BuildingRegistry $registry): JsonResponse
    {
        $building = $registry->create($request->user(), $request->payload(), $request->ip());

        return BuildingResource::make($building)
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateBuildingRequest $request,
        Building $building,
        BuildingRegistry $registry,
    ): BuildingResource {
        return BuildingResource::make(
            $registry->update($request->user(), $building, $request->payload(), $request->ip())
        );
    }

    /**
     * FR-01, second criterion. A dormitory holding rooms is refused by the
     * foreign key, and the refusal reaches the client as 409 carrying the
     * reason — see bootstrap/app.php.
     */
    public function destroy(
        DeleteBuildingRequest $request,
        Building $building,
        BuildingRegistry $registry,
    ): Response {
        $registry->delete($request->user(), $building, $request->ip());

        return response()->noContent();
    }

    public function show(
        ShowBuildingRequest $request,
        Building $building,
        BuildingDirectory $directory,
    ): BuildingResource {
        return new BuildingResource(
            $directory->card($request->user(), $building, $request->ip())
        );
    }

    public function people(
        ShowBuildingPeopleRequest $request,
        Building $building,
        BuildingDirectory $directory,
    ): AnonymousResourceCollection {
        return UserResource::collection(
            $directory->people(
                viewer: $request->user(),
                building: $building,
                search: $request->search(),
                role: $request->role(),
                perPage: $request->perPage(),
                ipAddress: $request->ip(),
            )
        );
    }
}
