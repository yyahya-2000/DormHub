<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DecideLostFoundClaimRequest;
use App\Http\Requests\Api\V1\JudgeLostFoundClaimRequest;
use App\Http\Requests\Api\V1\ReferLostFoundClaimRequest;
use App\Http\Requests\Api\V1\StoreLostFoundClaimRequest;
use App\Http\Resources\LostFoundClaimResource;
use App\Models\LostFoundClaim;
use App\Models\LostFoundItem;
use App\Services\LostFoundService;
use Illuminate\Http\JsonResponse;

/**
 * FR-26 at the protocol boundary: the claim, the holder's answer, the referral
 * and the warden's decision.
 *
 * **Four routes and four form requests, because four different authorisation
 * questions are being asked** — and that they differ is the module rather than
 * a detail of it (§2.5.4). May this account claim this entry; is this account
 * holding the object; is this account the person who filed the claim; does
 * this account hold the capability that settles a dispute in this dormitory. A
 * single class choosing between the four per route is the arrangement a later
 * edit gets wrong silently, and the edit that got it wrong would be the one
 * that let a member of staff answer an ordinary claim.
 *
 * **Every act is a sub-resource and not a PATCH of a status.**
 * `POST …/accept`, `…/decline`, `…/referral`, `…/decision` name the act; a
 * client that could PUT a status could put any status, and the transition
 * table of `LostFoundClaimStateMachine` would be enforcing what the client had
 * already assumed. It is the argument the guest module's `approve` and the
 * maintenance module's `accept` rest on.
 */
final class LostFoundClaimController extends Controller
{
    /**
     * FR-26: «the resident submits a claim describing identifying features».
     */
    public function store(
        StoreLostFoundClaimRequest $request,
        LostFoundItem $lostFoundItem,
        LostFoundService $lostFound,
    ): JsonResponse {
        $claim = $lostFound->claim(
            claimant: $request->user(),
            item: $lostFoundItem,
            marks: $request->message(),
            ipAddress: $request->ip(),
        );

        return LostFoundClaimResource::make($claim->load('claimant'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * FR-26, §2.4.4, first scenario: the person holding the object says the
     * marks match, and names where to collect it.
     */
    public function accept(
        DecideLostFoundClaimRequest $request,
        LostFoundClaim $lostFoundClaim,
        LostFoundService $lostFound,
    ): LostFoundClaimResource {
        return $this->card($lostFound->accept(
            actor: $request->user(),
            claim: $lostFoundClaim,
            handoverPoint: $request->handoverPoint(),
            note: $request->note(),
            ipAddress: $request->ip(),
        ));
    }

    /**
     * FR-26, §2.4.4, second scenario: the marks do not match. The entry
     * returns to the published list once nothing is outstanding on it, and the
     * claimant is offered the warden.
     */
    public function decline(
        DecideLostFoundClaimRequest $request,
        LostFoundClaim $lostFoundClaim,
        LostFoundService $lostFound,
    ): LostFoundClaimResource {
        return $this->card($lostFound->decline(
            actor: $request->user(),
            claim: $lostFoundClaim,
            reason: $request->reason(),
            ipAddress: $request->ip(),
        ));
    }

    /**
     * FR-26: the claimant puts the refusal to the warden.
     */
    public function refer(
        ReferLostFoundClaimRequest $request,
        LostFoundClaim $lostFoundClaim,
        LostFoundService $lostFound,
    ): LostFoundClaimResource {
        return $this->card($lostFound->refer(
            claimant: $request->user(),
            claim: $lostFoundClaim,
            note: $request->note(),
            ipAddress: $request->ip(),
        ));
    }

    /**
     * FR-26, first criterion: «a warden's decision on a referred claim».
     */
    public function decide(
        JudgeLostFoundClaimRequest $request,
        LostFoundClaim $lostFoundClaim,
        LostFoundService $lostFound,
    ): LostFoundClaimResource {
        return $this->card($lostFound->decide(
            staff: $request->user(),
            claim: $lostFoundClaim,
            upheld: $request->upheld(),
            handoverPoint: $request->handoverPoint(),
            note: $request->note(),
            ipAddress: $request->ip(),
        ));
    }

    private function card(LostFoundClaim $claim): LostFoundClaimResource
    {
        return LostFoundClaimResource::make($claim->load(['claimant', 'decider']));
    }
}
