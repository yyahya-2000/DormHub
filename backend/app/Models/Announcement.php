<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementCategory;
use Carbon\CarbonInterface;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ANNOUNCEMENT of the ER model (§3.4.3): FR-09, FR-11, FR-12.
 *
 * Every column name below is qualified with the table in the scopes, and that
 * is not decoration. The feed of §4.6.1 computes the unread mark by a left
 * join against `announcement_acks`, which carries an `announcement_id`, an
 * `id` and a `user_id` of its own; an unqualified `where('id', …)` in a scope
 * would become ambiguous the moment the join is added, and PostgreSQL would
 * say so at run time rather than here.
 */
#[Fillable([
    'building_id',
    'author_id',
    'title',
    'body',
    'category',
    'is_mandatory',
    'published_at',
    'expires_at',
])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'category' => 'general',
        'is_mandatory' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'building_id' => 'integer',
            'author_id' => 'integer',
            'category' => AnnouncementCategory::class,
            'is_mandatory' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            /*
             * Not a column of this table. The feed selects it as an alias off
             * the left-joined `announcement_acks`, and the cast is declared so
             * that the resource reads a date rather than the string the driver
             * hands back. On a row fetched without the join the attribute is
             * simply absent, which is what the resource tests for.
             */
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * The dormitory the announcement is addressed to, or none at all — NULL
     * means every building (§3.4.2).
     *
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * FR-12. Who has acknowledged this announcement, and when.
     *
     * @return HasMany<AnnouncementAck, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(AnnouncementAck::class);
    }

    /** Whether this notice is addressed to every dormitory. */
    public function addressesEveryBuilding(): bool
    {
        return $this->building_id === null;
    }

    /**
     * Whether the announcement has left the feed by the given moment.
     *
     * The question is asked of the row and not of a status column, because
     * there is no status column: FR-09's «moves to the archive» is this
     * comparison and nothing else.
     */
    public function hasExpiredAt(CarbonInterface $moment): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($moment);
    }

    /**
     * The announcements a reader may see: those addressed to one of these
     * buildings, and those addressed to every building.
     *
     * An empty list is not «everything»: it is a reader attached to no
     * dormitory, who sees only the notices addressed to all of them. That is
     * the correct answer and the reason the null branch is written separately
     * rather than folded into the `whereIn`.
     *
     * @param  Builder<Announcement>  $query
     * @param  list<int>  $buildingIds
     */
    public function scopeAddressedTo(Builder $query, array $buildingIds): void
    {
        $query->where(function (Builder $inner) use ($buildingIds): void {
            $inner->whereNull('announcements.building_id');

            if ($buildingIds !== []) {
                $inner->orWhereIn('announcements.building_id', $buildingIds);
            }
        });
    }

    /**
     * FR-09, second criterion, and FR-11's feed: published, and not yet
     * expired.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeCurrentAt(Builder $query, CarbonInterface $moment): void
    {
        $query
            ->where('announcements.published_at', '<=', $moment)
            ->where(function (Builder $inner) use ($moment): void {
                $inner
                    ->whereNull('announcements.expires_at')
                    ->orWhere('announcements.expires_at', '>', $moment);
            });
    }

    /**
     * The other side of the same line: what the archive holds.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeArchivedAt(Builder $query, CarbonInterface $moment): void
    {
        $query
            ->where('announcements.published_at', '<=', $moment)
            ->whereNotNull('announcements.expires_at')
            ->where('announcements.expires_at', '<=', $moment);
    }

    /**
     * @param  Builder<Announcement>  $query
     */
    public function scopeOfCategory(Builder $query, AnnouncementCategory $category): void
    {
        $query->where('announcements.category', $category->value);
    }
}
