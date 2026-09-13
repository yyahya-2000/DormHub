<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConsentDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreConsentRequest;
use App\Http\Resources\ConsentRecordResource;
use App\Http\Resources\ConsentTextResource;
use App\Services\ConsentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * FR-35 at the protocol boundary.
 *
 * **Four routes, and the fact that consent has its own is the requirement.**
 * Art. 9 part 1 of Federal Law No. 152-FZ has consent «executed separately
 * from other documents», and that is a statement about the act rather than
 * about the layout of a page. So there is no consent field on the sign-in
 * request, none on the password request, and none on the account form: the
 * only way a consent record comes into being is a request whose entire subject
 * is that consent. A client cannot obtain it as a by-product of anything else,
 * and a test can state the criterion by showing that the other routes have no
 * field to carry it.
 *
 * `pending` is what makes «displayed on first login» true: the sign-in answer
 * says how many documents are outstanding, and this route hands over the texts
 * themselves. The session is not held hostage to it — see `ConsentRegistry` on
 * why a consent extracted by locking the account would not be consent at all.
 */
final class ConsentController extends Controller
{
    /**
     * The texts awaiting a decision, in full.
     */
    public function pending(Request $request, ConsentRegistry $consents): AnonymousResourceCollection
    {
        return ConsentTextResource::collection($consents->pendingFor($request->user()));
    }

    /**
     * The person's own history of consent: given, withdrawn, given again.
     */
    public function index(Request $request, ConsentRegistry $consents): AnonymousResourceCollection
    {
        return ConsentRecordResource::collection($consents->historyFor($request->user()));
    }

    public function store(StoreConsentRequest $request, ConsentRegistry $consents): JsonResponse
    {
        $record = $consents->record(
            user: $request->user(),
            document: $request->document(),
            revision: $request->revision(),
            ipAddress: $request->ip(),
        );

        return ConsentRecordResource::make($record)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * FR-35, fourth criterion: withdrawal, from the personal account.
     *
     * A withdrawal when nothing stands is 204 rather than 404. The caller
     * asked for a state and the state is what they get; answering «no such
     * consent» would make the interface show an error for a button that did
     * exactly what it says.
     */
    public function withdraw(
        Request $request,
        ConsentDocument $document,
        ConsentRegistry $consents,
    ): ConsentRecordResource|Response {
        $record = $consents->withdraw(
            user: $request->user(),
            document: $document,
            ipAddress: $request->ip(),
        );

        return $record === null
            ? response()->noContent()
            : ConsentRecordResource::make($record);
    }
}
