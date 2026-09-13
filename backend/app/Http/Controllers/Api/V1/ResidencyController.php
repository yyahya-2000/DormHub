<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreResidencyRequest;
use App\Http\Requests\Api\V1\TerminateResidencyRequest;
use App\Http\Resources\ResidencyResource;
use App\Models\Residency;
use App\Services\ResidencyService;
use Illuminate\Http\JsonResponse;

/**
 * FR-03 and FR-05. `POST /api/v1/residencies` is the route §3.3.6 assigns to
 * `ResidencyService::assign`; the termination sits beside it as a sub-resource
 * of the residency it ends, because eviction is a change to that record and
 * not the creation of a different one.
 */
final class ResidencyController extends Controller
{
    public function store(StoreResidencyRequest $request, ResidencyService $residencies): JsonResponse
    {
        $bed = $request->bed();

        $residency = $residencies->assign(
            actor: $request->user(),
            resident: $request->resident(),
            bed: $bed,
            contractNumber: $request->contractNumber(),
            movedInAt: $request->movedInAt(),
            ground: $request->ground(),
            ipAddress: $request->ip(),
        );

        return ResidencyResource::make($residency->load(['user', 'bed.room.building']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * FR-05. The row is not deleted: the ground and the date are written, the
     * bed is freed, and the history stays readable on the card of FR-06.
     */
    public function terminate(
        TerminateResidencyRequest $request,
        Residency $residency,
        ResidencyService $residencies,
    ): ResidencyResource {
        $terminated = $residencies->terminate(
            actor: $request->user(),
            residency: $residency,
            ground: $request->ground(),
            movedOutAt: $request->movedOutAt(),
            ipAddress: $request->ip(),
        );

        return ResidencyResource::make($terminated->load(['user', 'bed.room.building']));
    }
}
