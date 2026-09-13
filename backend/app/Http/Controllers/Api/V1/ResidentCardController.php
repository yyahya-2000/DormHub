<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShowResidentCardRequest;
use App\Http\Resources\ResidentCardResource;
use App\Models\User;
use App\Services\ResidentDirectory;

/**
 * FR-06. One route, and the whole of the authorisation already settled by the
 * form request that reached it.
 */
final class ResidentCardController extends Controller
{
    public function show(
        ShowResidentCardRequest $request,
        User $resident,
        ResidentDirectory $residents,
    ): ResidentCardResource {
        return ResidentCardResource::make(
            $residents->card($request->user(), $resident, $request->ip())
        );
    }
}
