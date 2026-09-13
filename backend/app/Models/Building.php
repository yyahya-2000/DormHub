<?php

declare(strict_types=1);

namespace App\Models;

use App\Guests\TimeWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BUILDING of the ER model (§3.4.3). The three time columns are the
 * per-building regime NFR-09 asks for; they are settings rather than
 * constants precisely because the rules they mirror change between academic
 * years.
 */
#[Fillable([
    'name',
    'address',
    'floors_count',
    'visiting_from',
    'visiting_to',
    'curfew_at',
    'guest_lead_time_hours',
    'is_active',
])]
class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory;

    /**
     * The same defaults the migration writes, repeated here for the same
     * reason `Room` and `Residency` repeat theirs: a row created without them
     * is complete in memory and not only after a round trip to the database.
     *
     * Without this, `POST /buildings` answered with `visiting_from`,
     * `visiting_to`, `curfew_at` and `is_active` all null — the model had
     * never been told what the column defaults are, and the response is built
     * from the instance that was just saved rather than from a re-read. The
     * contract declares `is_active` a required boolean, so a generated client
     * typed it `boolean` and received null on the one response that creates
     * the object.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'floors_count' => 1,
        'visiting_from' => '08:00:00',
        'visiting_to' => '23:00:00',
        'curfew_at' => '23:00:00',
        'guest_lead_time_hours' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'floors_count' => 'integer',
            'guest_lead_time_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The regime of clause 2.2, as it falls on one day (NFR-09, FR-16).
     *
     * The window is read from the row and nowhere else. That is the whole of
     * NFR-09 as far as the guest module is concerned: a university whose rules
     * close the dormitory at 22:00 changes a column, and the interval the
     * validator accepts, the deadline the card shows and the hour the sweep
     * runs at all move together. A constant named `CURFEW_HOUR` would have to
     * be found in three files and changed in all of them, and would make this
     * deployment's value the sector's.
     */
    public function visitingWindowOn(CarbonInterface $date): TimeWindow
    {
        return TimeWindow::on($date, (string) $this->visiting_from, (string) $this->visiting_to);
    }

    /**
     * The control time of FR-20, on a given day: an instant rather than a
     * window, and the moment the quarter-hourly sweep starts caring about
     * this building's open visits.
     */
    public function curfewOn(CarbonInterface $date): CarbonImmutable
    {
        return TimeWindow::at(
            CarbonImmutable::parse($date->toDateString(), $date->getTimezone()),
            (string) $this->curfew_at,
        );
    }

    /**
     * FR-16, first criterion: the notice this dormitory wants before a guest
     * arrives. Zero — the default — means none, which is what the HSE rules of
     * internal order actually say.
     */
    public function guestLeadTime(): CarbonInterval
    {
        return CarbonInterval::hours(max(0, (int) $this->guest_lead_time_hours));
    }

    /**
     * @return HasMany<RoleUser, $this>
     */
    public function roleGrants(): HasMany
    {
        return $this->hasMany(RoleUser::class);
    }

    /**
     * @return HasMany<GuestRequest, $this>
     */
    public function guestRequests(): HasMany
    {
        return $this->hasMany(GuestRequest::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
