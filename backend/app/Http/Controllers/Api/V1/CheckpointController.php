<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckInGuestRequest;
use App\Http\Requests\Api\V1\CheckOutGuestRequest;
use App\Http\Requests\Api\V1\StoreGuestConsentRequest;
use App\Http\Requests\Api\V1\VerifyGuestAtCheckpointRequest;
use App\Http\Resources\ConsentRecordResource;
use App\Http\Resources\GuestVisitResource;
use App\Models\GuestRequest;
use App\Services\CheckpointService;
use App\Services\ConsentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * The security post: FR-18, FR-19, and the guest's consent between them.
 *
 * **Four routes, in the order the desk uses them.** Find the guest. Take their
 * consent. Record the entry. Record the exit. The order is not a convention —
 * it is §2.7.1 — and the application refuses to be used in any other: the
 * check-in asks `ConsentRegistry` first and answers 409 when the third step is
 * attempted before the second.
 *
 * **Verification and entry stay apart.** §3.5.1 splits them so the officer can
 * refuse entry without leaving a false record of an entry that never happened;
 * `verify` changes nothing at all, and the contract says so.
 */
final class CheckpointController extends Controller
{
    /**
     * FR-18. Search by code or by surname, and answer with the card for
     * comparison against the document in the officer's hand.
     */
    public function verify(VerifyGuestAtCheckpointRequest $request, CheckpointService $checkpoint): JsonResponse
    {
        $found = $checkpoint->search(
            officer: $request->user(),
            building: $request->building(),
            code: $request->code(),
            surname: $request->surname(),
            ipAddress: $request->ip(),
        );

        $cards = $found->map(
            fn (GuestRequest $guestRequest): array => $checkpoint->cardFor($guestRequest)->toArray()
        );

        $body = [
            'data' => $cards->values()->all(),
            'meta' => [
                'matches' => $cards->count(),
                // The route is a POST and changes nothing; the contract and
                // this line say the same thing to two different readers.
                'read_only' => true,
            ],
        ];

        if ($cards->isEmpty()) {
            // The contract declares a message on this answer and the body
            // carried none, so a terminal typed against it had an array of
            // zero cards and a field that was never sent. The message is the
            // sentence the officer reads off the screen; `data` and `meta`
            // stay, so one shape is parsed whatever the status.
            $body = ['message' => $this->nothingFound($request->code())] + $body;
        }

        return response()->json($body, $this->statusFor($cards));
    }

    /**
     * FR-35, guest half: the consent taken at the desk, before any entry is
     * recorded.
     */
    public function consent(StoreGuestConsentRequest $request, ConsentRegistry $consents): JsonResponse
    {
        $record = $consents->recordForGuest(
            request: $request->guestRequest(),
            revision: $request->revision(),
            operator: $request->user(),
            ipAddress: $request->ip(),
        );

        return ConsentRecordResource::make($record)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * FR-19: the entry.
     */
    public function checkIn(CheckInGuestRequest $request, CheckpointService $checkpoint): JsonResponse
    {
        $visit = $checkpoint->checkIn(
            officer: $request->user(),
            request: $request->guestRequest(),
            overrideReason: $request->overrideReason(),
            ipAddress: $request->ip(),
        );

        return GuestVisitResource::make($visit)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * FR-19: the exit.
     */
    public function checkOut(CheckOutGuestRequest $request, CheckpointService $checkpoint): GuestVisitResource
    {
        return GuestVisitResource::make($checkpoint->checkOut(
            officer: $request->user(),
            visit: $request->visit(),
            ipAddress: $request->ip(),
        ));
    }

    /**
     * A search that matched nothing is 404 and not an empty 200.
     *
     * The officer typed a code and there is no such visit in this dormitory —
     * that is a different answer from «here is the card», and a terminal that
     * had to inspect an array length to tell them apart would show the empty
     * state and the wrong-building state identically. A surname search that
     * matches nobody is the same situation from the officer's side.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     */
    private function statusFor(Collection $cards): int
    {
        return $cards->isEmpty() ? 404 : 200;
    }

    /**
     * The two misses read differently at the desk and are told apart here.
     *
     * A code that matches nothing is a wrong code or a code from another
     * dormitory; a surname that matches nothing means nobody of that name is
     * expected today, which is not the same news and not the same next action.
     */
    private function nothingFound(?string $code): string
    {
        return $code !== null && $code !== ''
            ? 'No visit in this dormitory answers to that code.'
            : 'No guest of that name is expected in this dormitory today.';
    }
}
