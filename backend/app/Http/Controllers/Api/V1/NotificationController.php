<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * FR-34, the in-app half: a person's own messages.
 *
 * Every action is scoped to `$request->user()` and there is no route by which
 * one account asks for another's messages — not because a policy refuses it,
 * but because there is no parameter for it. Marking one read looks the object
 * up **through the relation**, so a message belonging to somebody else is a
 * 404 and not a 403: a 403 would confirm that the identifier exists, which is
 * more than a stranger should learn.
 *
 * **One list and no filter.** The listing used to take an `unread` parameter.
 * It is gone with the settings screen and for the same reason: a person with a
 * dozen messages does not sort them, they read down the page. Unread is a
 * colour on the row and reading the message clears it, which is the whole of
 * the interaction the MVP needs.
 *
 * `meta.unread_count` stays, because the number in the menu badge is a
 * different question from «what is on this page» and the badge is drawn on
 * every screen. It is one `count` over the rows the listing already scopes
 * itself to — the same person, the same table, the same request — so it costs
 * no round trip of its own.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->paginate((int) config('dormitory.notifications.page_size'));

        return NotificationResource::collection($notifications)
            ->additional([
                'meta' => [
                    'unread_count' => $user->unreadNotifications()->count(),
                ],
            ]);
    }

    public function read(Request $request, string $notification): NotificationResource
    {
        /** @var DatabaseNotification $message */
        $message = $request->user()->notifications()->findOrFail($notification);

        $message->markAsRead();

        return NotificationResource::make($message->refresh());
    }
}
