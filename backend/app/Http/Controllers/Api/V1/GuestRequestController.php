<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DecideGuestRequestRequest;
use App\Http\Requests\Api\V1\ListGuestRequestsRequest;
use App\Http\Requests\Api\V1\StoreGuestRequestRequest;
use App\Http\Resources\GuestRequestResource;
use App\Models\GuestRequest;
use App\Services\GuestRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * FR-16, FR-17 and FR-23 at the protocol boundary.
 *
 * The controller does three things and no fourth: it takes an already
 * authorised and already validated request, calls the one service method that
 * owns the scenario, and shapes the answer (§3.3.3). Every rule the module has
 * is either in the form request — because it is a property of the input — or
 * in `GuestRequestService` — because it is a property of the register. None is
 * here.
 *
 * The three routes that carry no body ask `Gate::authorize()` instead of
 * arriving through a form request. A form request whose only content is an
 * `authorize()` method is a file that says nothing the one line says, and the
 * refusal is identical either way — a 403 the handler records as
 * `access.denied`.
 */
final class GuestRequestController extends Controller
{
    /**
     * The duty officer's queue when `building_id` is given, and the caller's
     * own requests when it is not.
     *
     * The own-requests branch filters by the identifier of the token, never by
     * one taken from the query: there is no parameter through which one
     * resident could ask about another's guests, which is the same principle
     * the notification routes are built on.
     */
    public function index(ListGuestRequestsRequest $request): AnonymousResourceCollection
    {
        $query = GuestRequest::query()->with(['building', 'student', 'visit']);

        $building = $request->building();

        if ($building !== null) {
            $query->inBuilding($building);
        } else {
            $query->where('student_id', $request->user()?->getKey());
        }

        if (($status = $request->status()) !== null) {
            $query->withStatus($status);
        }

        if (($date = $request->visitDate()) !== null) {
            $query->whereDate('visit_date', $date);
        }

        return GuestRequestResource::collection(
            $query->orderByDesc('visit_date')->orderByDesc('id')->get()
        );
    }

    /**
     * FR-16.
     */
    public function store(StoreGuestRequestRequest $request, GuestRequestService $requests): JsonResponse
    {
        $submitted = $requests->submit(
            student: $request->user(),
            building: $request->building(),
            guestFullName: $request->guestFullName(),
            documentType: $request->documentType(),
            documentNumber: $request->documentNumber(),
            visitDate: $request->visitDate(),
            plannedFrom: $request->plannedFrom(),
            plannedTo: $request->plannedTo(),
            purpose: $request->purpose(),
            ipAddress: $request->ip(),
        );

        return GuestRequestResource::make($submitted->load(['building', 'student']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, GuestRequest $guestRequest): GuestRequestResource
    {
        Gate::authorize('view', $guestRequest);

        return GuestRequestResource::make($guestRequest->load(['building', 'student', 'visit']));
    }

    /**
     * FR-17, and FR-23's second criterion through the same door: the mark of
     * the responsible officer travels in this body and the service decides
     * whether it was needed.
     */
    public function approve(
        DecideGuestRequestRequest $request,
        GuestRequest $guestRequest,
        GuestRequestService $requests,
    ): GuestRequestResource {
        $approved = $requests->approve(
            officer: $request->user(),
            request: $guestRequest,
            comment: $request->comment(),
            responsibleOfficerMark: $request->responsibleOfficerMark(),
            ipAddress: $request->ip(),
        );

        return GuestRequestResource::make($approved->load(['building', 'student']));
    }

    /**
     * FR-17, second criterion. The reason is required by the form request, so
     * a refusal with an empty body never reaches the service.
     */
    public function reject(
        DecideGuestRequestRequest $request,
        GuestRequest $guestRequest,
        GuestRequestService $requests,
    ): GuestRequestResource {
        $rejected = $requests->reject(
            officer: $request->user(),
            request: $guestRequest,
            reason: $request->reason(),
            ipAddress: $request->ip(),
        );

        return GuestRequestResource::make($rejected->load(['building', 'student']));
    }

    public function cancel(
        Request $request,
        GuestRequest $guestRequest,
        GuestRequestService $requests,
    ): GuestRequestResource {
        Gate::authorize('cancel', $guestRequest);

        $cancelled = $requests->cancel(
            student: $request->user(),
            request: $guestRequest,
            ipAddress: $request->ip(),
        );

        return GuestRequestResource::make($cancelled->load(['building', 'student']));
    }

    /**
     * NFR-06: the document number in full, once, to somebody who may, and
     * recorded as an event of its own.
     *
     * A route rather than a field. A field that appeared for privileged
     * readers would be sent on every list and every refresh, and the log would
     * fill with readings nobody performed — which would make the record
     * useless for the question it exists to answer.
     */
    public function documentNumber(
        Request $request,
        GuestRequest $guestRequest,
        GuestRequestService $requests,
    ): JsonResponse {
        Gate::authorize('viewDocumentNumber', $guestRequest);

        return response()->json([
            'data' => [
                'guest_request_id' => $guestRequest->getKey(),
                'guest_doc_type' => $guestRequest->guest_doc_type?->value,
                'guest_doc_number' => $requests->revealDocumentNumber(
                    viewer: $request->user(),
                    request: $guestRequest,
                    ipAddress: $request->ip(),
                ),
            ],
        ]);
    }
}
