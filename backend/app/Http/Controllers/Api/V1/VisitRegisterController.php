<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListVisitRegisterRequest;
use App\Http\Requests\Api\V1\StoreVisitCorrectionRequest;
use App\Models\Building;
use App\Models\GuestVisit;
use App\Services\CheckpointService;
use App\Services\VisitRegister;
use Illuminate\Http\JsonResponse;

/**
 * FR-21, «Visitor register».
 *
 * One page of the journal of clause 2.1.2 over a period, read on the screen.
 * There is no file: the register is looked at where it lives, and a download
 * was a second copy of personal data leaving the system for no scenario the
 * MVP has.
 */
final class VisitRegisterController extends Controller
{
    public function index(
        ListVisitRegisterRequest $request,
        Building $building,
        VisitRegister $register,
    ): JsonResponse {
        $from = $request->from();
        $until = $request->until();

        $page = $register->page(
            building: $building,
            from: $from,
            until: $until,
            perPage: $request->perPage(),
        );

        $register->recordRead(
            viewer: $request->user(),
            building: $building,
            from: $from,
            until: $until,
            rows: $page->count(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'building_id' => $building->getKey(),
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * FR-21, second criterion. Writes nothing to the visit; the correcting
     * entry is a row in the audit log naming it, and the register carries the
     * two together.
     */
    public function correct(
        StoreVisitCorrectionRequest $request,
        GuestVisit $guestVisit,
        CheckpointService $checkpoint,
    ): JsonResponse {
        $checkpoint->recordCorrection(
            author: $request->user(),
            visit: $guestVisit,
            correction: $request->correction(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => [
                'guest_visit_id' => $guestVisit->getKey(),
                'correction' => $request->correction(),
            ],
        ], 201);
    }
}
