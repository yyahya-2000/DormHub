<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnnouncementCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAnnouncementsRequest;
use App\Http\Requests\Api\V1\StoreAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Services\AnnouncementQuery;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FR-09 and FR-11 at the protocol boundary.
 *
 * The controller takes an already authorised and already validated request,
 * calls the one service method that owns the scenario and shapes the answer
 * (§3.3.3). Every rule the module has is either in a form request, because it
 * is a property of the input, or in `AnnouncementService` and
 * `AnnouncementQuery`, because it is a property of the register. None is here.
 *
 * FR-10, pinning an important announcement, is Could priority and outside the
 * MVP (§4.6.1): there is no route for it and no column behind one. FR-12, the
 * acknowledgement and the report on who has read, has been withdrawn from the
 * MVP altogether — see
 * `2026_09_14_200000_drop_the_acknowledgement_of_announcements`.
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
            expiresAt: $request->expiresAt(),
            ipAddress: $request->ip(),
        );

        return AnnouncementResource::make($published->load(['building', 'author']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The catalogue of categories the publishing form offers before it lets
     * the author type one of their own.
     *
     * Open and not closed: `announcements.category` is a free label of at most
     * 32 characters, and this list is a convenience so that five wardens
     * announcing a water shutoff agree on the heading without being forced to.
     * The shape is `GET /citizenships`'s, deliberately — both are «the values
     * a form offers», and a client should not have to learn two shapes for
     * one idea.
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => AnnouncementCategory::options(),
            'max_length' => AnnouncementCategory::MAX_LENGTH,
        ]);
    }
}
