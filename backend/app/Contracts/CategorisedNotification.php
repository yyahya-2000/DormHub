<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\NotificationCategory;

/**
 * A notification that says which of FR-34's categories it belongs to.
 *
 * This one method is the whole extension point of the requirement. FR-34 asks
 * for a set of occasions of which most do not exist yet, and for a switch that
 * turns off the ones that are not mandatory. Both are satisfied without the
 * dispatch knowing a single notification class: it asks the message what
 * category it is, and `App\Enums\NotificationCategory` answers whether that
 * category may be switched off and whether this person has switched it off.
 *
 * A later increment adds its notification, makes it implement this interface,
 * and is done — `App\Models\User::notify()` and `App\Services\Notifier` are
 * not touched.
 *
 * A notification that does not implement this interface is delivered
 * unconditionally. That is the safe default: an uncategorised message is one
 * nobody has decided is optional, and a switch nobody set must not silence it.
 */
interface CategorisedNotification
{
    public function category(): NotificationCategory;
}
