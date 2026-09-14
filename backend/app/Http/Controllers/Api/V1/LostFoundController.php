<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Files\PhotoStore;
use App\Http\Controllers\Api\V1\Concerns\ServesPhotographs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListLostFoundItemsRequest;
use App\Http\Requests\Api\V1\StoreLostFoundItemRequest;
use App\Http\Resources\LostFoundClaimResource;
use App\Http\Resources\LostFoundItemResource;
use App\Models\LostFoundItem;
use App\Services\LostFoundFeed;
use App\Services\LostFoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FR-24, FR-25 and the closure of FR-26 at the protocol boundary.
 *
 * The controller takes an already authorised and already validated request,
 * calls the one service method that owns the scenario and shapes the answer
 * (§3.3.3). Every rule the module has is either in a form request — because it
 * is a property of the input — or in `LostFoundService` and `LostFoundFeed` —
 * because it is a property of the register. None is here, and in particular no
 * method below looks at a status: the transition tables are the one place that
 * decides what may follow what.
 *
 * **`store()` returns 201 and the entry is in the feed, with nothing in
 * between.** FR-24's fourth criterion is «publication passes through no staff
 * approval step», and there is no branch here that could add one: no
 * moderation state to set, nobody to notify, no queue to enter.
 *
 * **The photograph is stored before the service is called**, which is the one
 * piece of sequencing this layer owns. `LostFoundService` takes a path,
 * because §3.3.1 keeps HTTP objects out of the application layer; the upload
 * is therefore the controller's business and `PhotoStore` is the adapter. A
 * file stored for a publication the service then refuses is an orphan in the
 * object store — acceptable, and the alternative is worse: a database
 * transaction held open across a network write.
 *
 * **The store arrives through the constructor and not through a method
 * parameter**, unlike `MaintenanceRequestController`. The two modules keep
 * their photographs on different disks and in different directories, and a
 * contextual binding is what tells them apart; contextual bindings apply to
 * constructor injection and not to the method injection the router does.
 */
final class LostFoundController extends Controller
{
    use ServesPhotographs;

    public function __construct(private readonly PhotoStore $photos) {}

    /**
     * FR-25: the finds of the caller's own dormitory.
     *
     * No policy and no building parameter. The dormitories are computed from
     * the grants of the token, so there is no way to phrase a request for
     * somebody else's feed — the same principle the announcement feed is built
     * on, and a stronger guarantee than a policy that could be forgotten.
     */
    public function index(ListLostFoundItemsRequest $request, LostFoundFeed $feed): AnonymousResourceCollection
    {
        return LostFoundItemResource::collection(
            $feed->feed(
                reader: $request->user(),
                status: $request->status(),
                kind: $request->kind(),
                search: $request->search(),
            )
        );
    }

    /**
     * FR-24.
     */
    public function store(StoreLostFoundItemRequest $request, LostFoundService $lostFound): JsonResponse
    {
        $published = $lostFound->publish(
            reporter: $request->user(),
            building: $request->building(),
            title: $request->title(),
            place: $request->place(),
            happenedOn: $request->happenedOn(),
            kind: $request->kind(),
            custody: $request->custody(),
            description: $request->description(),
            declaredOn: $request->declaredOn(),
            photoPath: $this->photos->storeOne($request->photo()),
            ipAddress: $request->ip(),
        );

        return LostFoundItemResource::make($published->load('building')->loadCount('claims'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * FR-25: one card, without the person who published it.
     */
    public function show(Request $request, LostFoundItem $lostFoundItem): LostFoundItemResource
    {
        Gate::authorize('view', $lostFoundItem);

        return $this->card($lostFoundItem);
    }

    /**
     * FR-24's photograph, read back.
     *
     * **The route the schema always described and nobody had written**
     * (acceptance of 15.09.2026). `photo_path` carries a path and not a URL,
     * and the client is supposed to ask for the image when it is about to draw
     * it; until now there was nothing to ask. The answer is the file itself —
     * see `ServesPhotographs` for why it is not a signed link.
     *
     * No index, unlike the maintenance module's: FR-24 says «the photograph»
     * in the singular and the column holds one path. An entry published
     * without one is a 404 — there is no photograph — and not an empty answer
     * a client would have to tell apart from a link.
     *
     * The authorisation is `view` on the entry, which is the feed's own
     * question: a find of another dormitory is a 403 before the store is
     * touched. The picture carries no more than the card does, and the card
     * has never said who published it.
     */
    public function photo(
        Request $request,
        LostFoundItem $lostFoundItem,
    ): StreamedResponse {
        Gate::authorize('view', $lostFoundItem);

        $path = $lostFoundItem->photo_path;

        if (! is_string($path) || $path === '') {
            abort(404, 'This entry carries no photograph.');
        }

        return $this->photographResponse($this->photos, $path);
    }

    /**
     * FR-26: the claims on one entry, for the person who has to answer them
     * and for the warden who may be asked to review one.
     *
     * A route of its own rather than a relation on the card, because the card
     * is read by the whole dormitory and the marks on a claim are read by
     * three people. A resident scrolling the feed sees `claim_count` and
     * nothing else.
     */
    public function claims(Request $request, LostFoundItem $lostFoundItem): AnonymousResourceCollection
    {
        Gate::authorize('viewClaims', $lostFoundItem);

        return LostFoundClaimResource::collection(
            $lostFoundItem->claims()->with(['claimant', 'decider'])->get()
        );
    }

    /**
     * FR-26, §2.4.4: «when the finder then marks the item returned, the status
     * becomes resolved with the time and the record no longer appears in the
     * public list of available finds».
     */
    public function resolve(
        Request $request,
        LostFoundItem $lostFoundItem,
        LostFoundService $lostFound,
    ): LostFoundItemResource {
        Gate::authorize('resolve', $lostFoundItem);

        return $this->card($lostFound->resolve(
            actor: $request->user(),
            item: $lostFoundItem,
            ipAddress: $request->ip(),
        ));
    }

    private function card(LostFoundItem $item): LostFoundItemResource
    {
        return LostFoundItemResource::make($item->load('building')->loadCount('claims'));
    }
}
