<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\NotificationCategory;

/**
 * A notification that says which of FR-34's categories it belongs to.
 *
 * This one method is the whole extension point. FR-34 asks for a set of
 * occasions of which several did not exist when the dispatch was written, and
 * none of them is named in it: the dispatch asks the message what category it
 * is, stores the word beside the row so the personal account can draw an icon
 * and a caption from it, and `App\Enums\NotificationCategory` answers whether
 * the category rests on a consent this person may have withdrawn.
 *
 * A later increment adds its notification, makes it implement this interface,
 * and is done — `App\Models\User::notify()` and `App\Services\Notifier` are
 * not touched.
 *
 * A notification that does not implement this interface is delivered
 * unconditionally. That is the safe default: an uncategorised message is one
 * nobody has decided rests on consent, and it must not be silenced by a
 * withdrawal nobody meant it to answer to.
 */
interface CategorisedNotification
{
    public function category(): NotificationCategory;
}
