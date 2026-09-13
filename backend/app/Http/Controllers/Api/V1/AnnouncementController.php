<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAnnouncementsRequest;
use App\Http\Requests\Api\V1\StoreAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Services\AnnouncementQuery;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * FR-09, FR-11 and FR-12 at the protocol boundary.
 *
 * The controller takes an already authorised and already validated request,
 * calls the one service method that owns the scenario and shapes the answer
 * (§3.3.3). Every rule the module has is either in a form request, because it
 * is a property of the input, or in `AnnouncementService` and
 * `AnnouncementQuery`, because it is a property of the register. None is here.
 *
 * FR-10, pinning an important announcement, is Could priority and outside the
 * MVP (§4.6.1): there is no route for it and no column behind one.
 */
final class AnnouncementController extends Controller
{
    /**
     * FR-11: the caller's own feed.
     *
     * No policy and no building parameter. The audience is computed from the
     * grants of the token, so there is no way to phrase a request for somebody
     * else's feed — the same principle the notification routes are built on,
     * and a stronger guarantee than a policy that could be forgotten.
     */
    public function index(ListAnnouncementsRequest $request, AnnouncementQuery $feed): AnonymousResourceCollection
    {
        return AnnouncementResource::collection(
            $feed->feed(
                reader: $request->user(),
                moment: now(),
                category: $request->category(),
                archived: $request->archived(),
            )
        );
    }

    /**
     * FR-09.
     */
    public function store(StoreAnnouncementRequest $request, AnnouncementService $announcements): JsonResponse
    {
        $published = $announcements->publish(
            author: $request->user(),
            building: $request->building(),
            title: (string) $request->input('title'),
            body: (string) $request->input('body'),
            category: $request->category(),
            mandatory: $request->isMandatory(),
            expiresAt: $request->expiresAt(),
            ipAddress: $request->ip(),
        );

        return AnnouncementResource::make($published->load(['building', 'author']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * FR-12, first criterion.
     *
     * 200 and not 201, on the first call as on the twentieth. The
     * acknowledgement is idempotent (`UNIQUE (announcement_id, user_id)`), and
     * a status that told the first call from a repeat would invite a client to
     * treat the repeat as an error — which is the opposite of what idempotence
     * is for.
     */
    public function acknowledge(
        Request $request,
        Announcement $announcement,
        AnnouncementService $announcements,
    ): AnnouncementResource {
        Gate::authorize('acknowledge', $announcement);

        $ack = $announcements->acknowledge($request->user(), $announcement);

        // The announcement comes back carrying the mark, so a client that
        // acknowledges from the feed can redraw the one row rather than
        // reloading the page.
        $announcement->setAttribute('acknowledged_at', $ack->acknowledged_at);

        return AnnouncementResource::make($announcement->load(['building', 'author']));
    }

    /**
     * FR-12, second criterion: the acknowledged share and the named list of
     * those who have not read.
     *
     * Not an `AnnouncementResource`: the answer is a report about people and
     * not an announcement, and shaping it as one would have put two lists of
     * residents inside an object whose every other field is about a notice.
     */
    public function readers(
        Request $request,
        Announcement $announcement,
        AnnouncementQuery $feed,
    ): JsonResponse {
        Gate::authorize('viewReaders', $announcement);

        $report = $feed->readers(
            viewer: $request->user(),
            announcement: $announcement,
            ipAddress: $request->ip(),
        );

        return response()->json([
            'data' => [
                'announcement_id' => $announcement->getKey(),
                'title' => $announcement->title,
                'is_mandatory' => (bool) $announcement->is_mandatory,
                // The dormitories the figures below are about. For an
                // announcement addressed to every building this is the
                // caller's own scope and not the whole register — see
                // AnnouncementQuery::readers().
                'building_ids' => $report['building_ids'],
                'audience_size' => $report['audience_size'],
                'acknowledged_count' => $report['acknowledged_count'],
                'acknowledged_share' => $report['acknowledged_share'],
                'acknowledged' => $report['acknowledged'],
                'not_acknowledged' => $report['not_acknowledged'],
            ],
        ]);
    }
}
