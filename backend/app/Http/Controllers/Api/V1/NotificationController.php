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
 */
final class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $request->user()
            ->notifications()
            ->when(
                $request->boolean('unread'),
                fn ($query) => $query->whereNull('read_at'),
            )
            ->paginate((int) config('dormitory.notifications.page_size'));

        return NotificationResource::collection($notifications);
    }

    public function read(Request $request, string $notification): NotificationResource
    {
        /** @var DatabaseNotification $message */
        $message = $request->user()->notifications()->findOrFail($notification);

        $message->markAsRead();

        return NotificationResource::make($message->refresh());
    }
}
