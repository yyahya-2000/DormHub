<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateNotificationSettingsRequest;
use App\Http\Resources\NotificationSettingResource;
use App\Services\NotificationPreferences;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FR-34, second criterion: the switch.
 *
 * The listing is built from the enumeration and not from the table, so a
 * category nobody has decided about still appears — switched on, which is the
 * default — and the screen never has rows missing. The update returns the
 * whole state rather than what was sent, so the client redraws from one answer
 * and cannot end up showing a switch the server did not accept.
 */
final class NotificationSettingController extends Controller
{
    public function index(Request $request, NotificationPreferences $preferences): AnonymousResourceCollection
    {
        return NotificationSettingResource::collection(
            $preferences->stateFor($request->user())
        );
    }

    public function update(
        UpdateNotificationSettingsRequest $request,
        NotificationPreferences $preferences,
    ): AnonymousResourceCollection {
        $user = $request->user();

        $preferences->apply($user, $request->choices());

        return NotificationSettingResource::collection($preferences->stateFor($user));
    }
}
