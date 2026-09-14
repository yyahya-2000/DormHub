<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Files\PhotoStore;
use App\Http\Controllers\Api\V1\Concerns\ServesPhotographs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfirmMaintenanceRequestRequest;
use App\Http\Requests\Api\V1\ListMaintenanceRequestsRequest;
use App\Http\Requests\Api\V1\StoreMaintenanceRequestRequest;
use App\Http\Requests\Api\V1\TriageMaintenanceRequestRequest;
use App\Http\Resources\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FR-36 … FR-39 at the protocol boundary.
 *
 * The controller does three things and no fourth: it takes an already
 * authorised and already validated request, calls the one service method that
 * owns the scenario, and shapes the answer (§3.3.3). Every rule the module has
 * is either in a form request — because it is a property of the input — or in
 * `MaintenanceService` — because it is a property of the register. None is
 * here, and in particular no method below looks at a status: the transition
 * table is the one place that decides what may follow what, and a controller
 * that checked first would be enforcing what the table already refuses, in a
 * second place that could come to disagree with it.
 *
 * **The photographs are stored before the service is called and that is the
 * one piece of sequencing the controller owns.** `MaintenanceService` takes
 * paths, because §3.3.1 keeps HTTP objects out of the application layer; the
 * upload is therefore this layer's business and `PhotoStore` is the adapter.
 * A file stored for a submission the service then refuses is an orphan in the
 * object store — acceptable, and the alternative is worse: a database
 * transaction held open across a network write to S3.
 *
 * **Every transition is a sub-resource and not a PATCH of a status.**
 * `POST …/accept`, `…/reject`, `…/confirmation` name the act; a client that
 * could PUT a status could put any status, and the transition table of FR-38
 * would be enforcing what the client had already assumed.
 */
final class MaintenanceRequestController extends Controller
{
    use ServesPhotographs;

    /**
     * The caller's own requests, newest first, a page at a time.
     *
     * Filtered by the identifier of the token and never by one taken from the
     * query: there is no parameter through which one resident could ask about
     * another's defects. The warden's queue is a different route, decided on
     * the building object — see `MaintenanceQueueController`.
     *
     * `scope=archive` reads the finished requests; anything else reads the
     * open ones, which is the list the screen opens on.
     */
    public function index(ListMaintenanceRequestsRequest $request): AnonymousResourceCollection
    {
        $query = MaintenanceRequest::query()
            ->with(['building', 'room', 'reporter', 'assignee'])
            ->where('reporter_id', $request->user()?->getKey());

        $request->archived() ? $query->archived() : $query->open();

        return MaintenanceRequestResource::collection(
            $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($request->perPage())
        );
    }

    /**
     * FR-36.
     */
    public function store(
        StoreMaintenanceRequestRequest $request,
        MaintenanceService $maintenance,
        PhotoStore $photos,
    ): JsonResponse {
        $filed = $maintenance->submit(
            reporter: $request->user(),
            building: $request->building(),
            category: $request->category(),
            location: $request->location(),
            description: $request->description(),
            urgency: $request->urgency(),
            locationNote: $request->locationNote(),
            title: $request->title(),
            photoPaths: $photos->store($request->photos()),
            ipAddress: $request->ip(),
        );

        return MaintenanceRequestResource::make(
            $filed->load(['building', 'room', 'reporter', 'workLog.actor'])
        )->response()->setStatusCode(201);
    }

    public function show(Request $request, MaintenanceRequest $maintenanceRequest): MaintenanceRequestResource
    {
        Gate::authorize('view', $maintenanceRequest);

        return MaintenanceRequestResource::make(
            $maintenanceRequest->load(['building', 'room', 'reporter', 'assignee', 'workLog.actor'])
        );
    }

    /**
     * FR-36's photographs, read back one at a time.
     *
     * **The route the schema always described and nobody had written**
     * (acceptance of 15.09.2026). `photo_paths` carries paths and not URLs, and
     * the client is supposed to ask for the image when it is about to draw it;
     * until now there was nothing to ask.
     *
     * The answer is the file itself and not a signed link — see
     * `ServesPhotographs` for the reason a link cannot be followed from a
     * browser on this deployment.
     *
     * By index and not by path: a path in the query would be a path a client
     * could edit, and the check that it belonged to this request would be
     * doing the work the index does by construction. An index outside the list
     * is a 404 — there is no such photograph — rather than a 422, because
     * nothing about the request is malformed.
     *
     * The authorisation is `view` on the request itself: whoever may read the
     * card may see the picture on it. A request of another dormitory is a 403
     * before the store is touched.
     */
    public function photo(
        Request $request,
        MaintenanceRequest $maintenanceRequest,
        int $index,
        PhotoStore $photos,
    ): StreamedResponse {
        Gate::authorize('view', $maintenanceRequest);

        $path = $maintenanceRequest->photoPaths()[$index] ?? null;

        if ($path === null) {
            abort(404, 'This request carries no photograph under that index.');
        }

        return $this->photographResponse($photos, $path);
    }

    /**
     * FR-37: accepted, with a planned completion date the form request makes
     * mandatory.
     */
    public function accept(
        TriageMaintenanceRequestRequest $request,
        MaintenanceRequest $maintenanceRequest,
        MaintenanceService $maintenance,
    ): MaintenanceRequestResource {
        $accepted = $maintenance->accept(
            actor: $request->user(),
            request: $maintenanceRequest,
            targetDate: $request->targetDate(),
            assignee: $request->assignee(),
            urgency: $request->urgency(),
            comment: $request->comment(),
            ipAddress: $request->ip(),
        );

        return $this->card($accepted);
    }

    /**
     * FR-37: refused, with a reason the form request makes mandatory — so a
     * refusal with an empty body never reaches the service.
     */
    public function reject(
        TriageMaintenanceRequestRequest $request,
        MaintenanceRequest $maintenanceRequest,
        MaintenanceService $maintenance,
    ): MaintenanceRequestResource {
        $rejected = $maintenance->reject(
            actor: $request->user(),
            request: $maintenanceRequest,
            reason: $request->reason(),
            ipAddress: $request->ip(),
        );

        return $this->card($rejected);
    }

    /**
     * FR-38: the work has begun.
     */
    public function start(
        TriageMaintenanceRequestRequest $request,
        MaintenanceRequest $maintenanceRequest,
        MaintenanceService $maintenance,
    ): MaintenanceRequestResource {
        $started = $maintenance->start(
            actor: $request->user(),
            request: $maintenanceRequest,
            comment: $request->comment(),
            ipAddress: $request->ip(),
        );

        return $this->card($started);
    }

    /**
     * FR-38: the work is reported done — which does **not** close the request.
     * §3.5.2: «the request is closed by the person who reported it, not by the
     * person who fixed it».
     */
    public function complete(
        TriageMaintenanceRequestRequest $request,
        MaintenanceRequest $maintenanceRequest,
        MaintenanceService $maintenance,
    ): MaintenanceRequestResource {
        $completed = $maintenance->complete(
            actor: $request->user(),
            request: $maintenanceRequest,
            comment: $request->comment(),
            ipAddress: $request->ip(),
        );

        return $this->card($completed);
    }

    /**
     * FR-39: the reporter confirms, and the request closes.
     */
    public function confirm(
        ConfirmMaintenanceRequestRequest $request,
        MaintenanceRequest $maintenanceRequest,
        MaintenanceService $maintenance,
    ): MaintenanceRequestResource {
        $closed = $maintenance->confirm(
            reporter: $request->user(),
            request: $maintenanceRequest,
            comment: $request->comment(),
            ipAddress: $request->ip(),
        );

        return $this->card($closed);
    }

    /**
     * FR-39: «not fixed», inside the window.
     */
    public function reopen(
        ConfirmMaintenanceRequestRequest $request,
        MaintenanceRequest $maintenanceRequest,
        MaintenanceService $maintenance,
    ): MaintenanceRequestResource {
        $reopened = $maintenance->reopen(
            reporter: $request->user(),
            request: $maintenanceRequest,
            reason: $request->comment(),
            ipAddress: $request->ip(),
        );

        return $this->card($reopened);
    }

    /**
     * The card every transition answers with: the request, and the history
     * that now has one more row in it.
     */
    private function card(MaintenanceRequest $request): MaintenanceRequestResource
    {
        return MaintenanceRequestResource::make(
            $request->load(['building', 'room', 'reporter', 'assignee', 'workLog.actor'])
        );
    }
}
