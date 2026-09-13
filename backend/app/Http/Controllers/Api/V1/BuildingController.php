<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShowBuildingPeopleRequest;
use App\Http\Requests\Api\V1\ShowBuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Http\Resources\UserResource;
use App\Models\Building;
use App\Services\BuildingDirectory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The two routes FR-07 is demonstrated on. Authorisation is already settled by
 * the form request that reached this method, so neither action contains a
 * check of its own.
 */
final class BuildingController extends Controller
{
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
            $directory->people($request->user(), $building, $request->ip())
        );
    }
}
