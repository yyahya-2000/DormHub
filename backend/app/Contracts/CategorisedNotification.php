<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\NotificationCategory;

/**
 * A notification that says which of FR-34's categories it belongs to.
 *
 * This one method is the whole extension point. FR-34 asks for a set of
 * occasions of which several did not exist when the dispatch was written, and
 * none of them is named in it: the message says what category it is and the
 * word is stored beside the row, so the personal account draws an icon and a
 * caption from `App\Enums\NotificationCategory` without knowing a single
 * notification class.
 *
 * A later increment adds its notification, makes it implement this interface,
 * and is done — `App\Services\Notifier` is not touched.
 */
interface CategorisedNotification
{
    public function category(): NotificationCategory;
}
