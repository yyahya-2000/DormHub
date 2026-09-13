<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExportVisitRegisterRequest;
use App\Http\Requests\Api\V1\StoreVisitCorrectionRequest;
use App\Models\Building;
use App\Models\GuestVisit;
use App\Services\CheckpointService;
use App\Services\VisitRegister;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-21, «Visitor register», and §3.9.6's period export.
 *
 * Constraint C-04 makes this export the substitute for the access-control
 * integration this iteration does not build, which is why it carries the
 * seven columns rather than a summary: what leaves here is meant to be the
 * document somebody reads instead of the journal.
 *
 * The format is a presentational template and carries no claim to satisfy any
 * particular reporting obligation (§3.9.6). It is stated here as it is stated
 * there, because a CSV with the right column names is exactly the artefact
 * somebody will assume otherwise about.
 */
final class VisitRegisterController extends Controller
{
    public function index(
        ExportVisitRegisterRequest $request,
        Building $building,
        VisitRegister $register,
    ): JsonResponse|Response {
        $from = $request->from();
        $until = $request->until();
        $format = $request->exportFormat();

        $pageSize = (int) config('dormitory.guests.register_page_size');
        $page = $request->page();

        $rows = $register->rows(
            building: $building,
            from: $from,
            until: $until,
            limit: $pageSize,
            offset: ($page - 1) * $pageSize,
        );

        $register->recordExport(
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
                $register->toCsv($rows),
                200,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => sprintf(
                        'attachment; filename="visit-register-%d-%s-%s.csv"',
                        $building->getKey(),
                        $from->toDateString(),
                        $until->toDateString(),
                    ),
                ],
            );
        }

        return response()->json([
            'data' => $rows->values()->all(),
            'meta' => [
                'building_id' => $building->getKey(),
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
                'page' => $page,
                'per_page' => $pageSize,
                'total' => $register->count($building, $from, $until),
                'columns' => VisitRegister::COLUMNS,
            ],
        ]);
    }

    /**
     * FR-21, second criterion. Writes nothing to the visit; the correcting
     * entry is a row in the audit log naming it, and the export carries the
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
                'note' => 'The visit itself is unchanged. The correction is an entry of its own, '
                    .'recorded against it and shown beside it in the register.',
            ],
        ], 201);
    }
}
