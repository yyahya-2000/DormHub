<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ANNOUNCEMENT_ACK of the ER model (§3.4.3): one person, one announcement, one
 * moment (FR-12).
 *
 * The class is as thin as the table, and that is the whole of §3.4.1's fifth
 * decision. There is no state to move and nothing to recompute: a row exists
 * or it does not, the announcement's unread mark is the absence of one, and
 * the warden's list of those who have not read is the residents for whom the
 * left join found nothing.
 *
 * `timestamps` are off because the row is written once. `acknowledged_at`
 * carries the moment FR-12 asks for, and there is no second moment to record.
 */
#[Fillable([
    'announcement_id',
    'user_id',
    'acknowledged_at',
])]
class AnnouncementAck extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'announcement_id' => 'integer',
            'user_id' => 'integer',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Announcement, $this>
     */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
