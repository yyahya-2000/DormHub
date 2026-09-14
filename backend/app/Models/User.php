<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\CategorisedNotification;
use App\Enums\Citizenship;
use App\Enums\ConsentDocument;
use App\Enums\NotificationCategory;
use App\Enums\Permission;
use App\Enums\RoleCode;
use App\Enums\UserStatus;
use App\Services\ConsentRegistry;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * USER of the ER model (§3.4.3).
 *
 * The model carries the one domain invariant FR-07 rests on: a role is held
 * either over the system as a whole or inside a single building, and the
 * question a policy asks is always the second form — «does this user hold this
 * role **in this building**» (§3.4.1, decision 1).
 */
#[Fillable([
    'external_id',
    'full_name',
    'email',
    'phone',
    'citizenship',
    'password_hash',
    'password_change_required',
    'status',
])]
#[Hidden(['password_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    /*
     * The trait's own `notify()` is kept under a second name, because the
     * method below has to wrap it: FR-34's second criterion is enforced on
     * the way in, before anything is queued, and a trait method cannot be
     * called with `parent::`.
     */
    use Notifiable {
        notify as private dispatchNotification;
        notifyNow as private dispatchNotificationNow;
    }

    /**
     * The ER model names the column `password_hash`, so the authentication
     * guard is pointed at it instead of the framework default.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'password_change_required' => 'boolean',
            'status' => UserStatus::class,
            'citizenship' => Citizenship::class,
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->using(RoleUser::class)
            ->withPivot(['id', 'building_id', 'granted_by', 'granted_at']);
    }

    /**
     * @return HasMany<RoleUser, $this>
     */
    public function roleGrants(): HasMany
    {
        return $this->hasMany(RoleUser::class);
    }

    /**
     * FR-35. Every act of consent this person has performed, newest first,
     * withdrawn ones included — the history is the evidence and is never
     * pruned (see the migration).
     *
     * @return HasMany<ConsentRecord, $this>
     */
    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id');
    }

    /**
     * Whether consent to this document stands for this person today.
     */
    public function hasConsentedTo(ConsentDocument $document): bool
    {
        if ($this->relationLoaded('consentRecords')) {
            return $this->consentRecords->contains(
                fn (ConsentRecord $record): bool => $record->document_code === $document
                    && $record->isInForce()
            );
        }

        return app(ConsentRegistry::class)->hasInForce($this, $document);
    }

    /**
     * The one road a notification takes to this person, and the gate on it.
     *
     * FR-35's fourth criterion is what the gate now enforces, and it is the
     * model rather than a service so that no caller can go around it:
     * `$user->notify(...)`, a queued job, a console command and the existing
     * `ResidentAccountIssuer` all pass through this method unchanged.
     * `App\Services\Notifier` fans out to several people by calling it, and
     * the `Notification` facade's own fan-out is not used anywhere for exactly
     * this reason — it dispatches to channels directly and would step over the
     * gate.
     *
     * The question asked does not name a notification class: that is what lets
     * a later increment add a category without touching this method.
     *
     * @param  mixed  $instance
     */
    public function notify($instance): void
    {
        if ($instance instanceof CategorisedNotification
            && ! $this->receivesNotificationsOf($instance->category())) {
            return;
        }

        $this->dispatchNotification($instance);
    }

    /**
     * The same gate on the trait's other door.
     *
     * `notifyNow()` sends outside the queue and is what a console command or a
     * test reaches for. It is overridden for one reason: the claim above —
     * that this is the only road — has to be true, and a second entrance that
     * skipped the gate would make the criterion hold everywhere except where
     * somebody used the other method.
     *
     * @param  mixed  $instance
     * @param  array<int, string>|null  $channels
     */
    public function notifyNow($instance, $channels = null): void
    {
        if ($instance instanceof CategorisedNotification
            && ! $this->receivesNotificationsOf($instance->category())) {
            return;
        }

        $this->dispatchNotificationNow($instance, $channels);
    }

    /**
     * Whether this person is to be told about events of this category.
     *
     * One question, and it is about the legal ground rather than about a
     * preference. The per-category switch of FR-34's second criterion is
     * withdrawn from the MVP, so nothing a person sets stops a message any
     * more; what stops one is the ground for it being gone.
     *
     * A category that rests on consent is processing of personal data whose
     * only ground is that consent (§2.7.1), so when the resident withdraws it
     * the message stops — this application's answer to the question FR-35's
     * fourth criterion leaves open, «and then what». A category that does not
     * rest on consent rests on the accommodation contract or on the rules of
     * internal order, and there is nothing for it to depend on.
     */
    public function receivesNotificationsOf(NotificationCategory $category): bool
    {
        return ! $category->restsOnConsent()
            || $this->hasConsentedTo(ConsentDocument::ResidentPersonalData);
    }

    /**
     * The whole occupancy history, newest first. Nothing is ever removed from
     * it (§3.4.1, decision 3), which is what lets FR-06's card show where a
     * person lived and not only where they live.
     *
     * @return HasMany<Residency, $this>
     */
    public function residencies(): HasMany
    {
        return $this->hasMany(Residency::class)->orderByDesc('moved_in_at')->orderByDesc('id');
    }

    /**
     * The residency with no recorded end, if there is one.
     * `residencies_user_no_overlap` is why the singular is safe.
     *
     * @return HasOne<Residency, $this>
     */
    public function openResidency(): HasOne
    {
        return $this->hasOne(Residency::class)->whereNull('moved_out_at');
    }

    /**
     * FR-16. The guest requests this person has filed, newest first.
     *
     * @return HasMany<GuestRequest, $this>
     */
    public function guestRequests(): HasMany
    {
        return $this->hasMany(GuestRequest::class, 'student_id')
            ->orderByDesc('visit_date')
            ->orderByDesc('id');
    }

    /**
     * FR-20, third criterion: «the fact is visible on the inviting resident's
     * card».
     *
     * The visits of this person's guests that the sweep has reported overdue.
     * The relation travels USER → GUEST_REQUEST → GUEST_VISIT, which is why it
     * is a `HasManyThrough`: the resident invites, the request yields the
     * visit, and the answerability clause 3.4 of the Model Rules puts on the
     * inviting resident follows the same path.
     *
     * `overdue_notified_at` rather than the status is the condition, and the
     * difference matters: a visit that has since been closed is `closed_late`
     * and no longer `overdue`, and the fact that it *was* overdue is exactly
     * what the card has to keep showing. A status test would have cleared the
     * card the moment the guest finally left.
     *
     * @return HasManyThrough<GuestVisit, GuestRequest, $this>
     */
    public function overdueGuestVisits(): HasManyThrough
    {
        return $this->hasManyThrough(
            GuestVisit::class,
            GuestRequest::class,
            'student_id',
            'guest_request_id',
            'id',
            'id',
        )->whereNotNull('guest_visits.overdue_notified_at')
            ->orderByDesc('guest_visits.due_at');
    }

    /**
     * Whether the person is resident in this building on the given day.
     *
     * A termination dated in the future leaves this true until that day
     * arrives, which is the whole content of FR-05's third criterion: access
     * to building-bound functions ends **no later than** the stated date.
     */
    public function residesIn(Building|int $building, ?CarbonInterface $on = null): bool
    {
        return Residency::query()
            ->where('user_id', $this->getKey())
            ->inBuilding($building)
            ->currentOn($on ?? now())
            ->exists();
    }

    /**
     * Whether the housing register says this person has left the building.
     *
     * The question is narrower than the negation of the one above, and the
     * difference matters. A person with no residency row at all has not been
     * evicted: the register simply has nothing to say about them, and their
     * role grant stands on its own. Only a person the register knows — one
     * with a residency history in this building — and who holds none of it
     * today has left, and it is only then that the building-bound functions
     * close (FR-05).
     */
    public function hasMovedOutOf(Building|int $building, ?CarbonInterface $on = null): bool
    {
        $known = Residency::query()
            ->where('user_id', $this->getKey())
            ->inBuilding($building)
            ->exists();

        return $known && ! $this->residesIn($building, $on);
    }

    /**
     * Whether the user holds the role at all, in any scope. Answering only
     * this question is the mistake §3.3.3 warns against; it is used for the
     * system-wide roles and as the first half of the scoped check below.
     */
    public function hasRole(RoleCode $code): bool
    {
        return $this->grants()->contains(
            fn (RoleUser $grant): bool => $grant->role?->code === $code
        );
    }

    /**
     * Whether the user holds the role **over this building**. A system-wide
     * grant, which carries no building, satisfies the question for every
     * building; a scoped grant satisfies it for its own building only.
     */
    public function hasRoleInBuilding(RoleCode $code, Building|int|null $building): bool
    {
        $buildingId = $building instanceof Building ? $building->getKey() : $building;

        return $this->grants()->contains(function (RoleUser $grant) use ($code, $buildingId): bool {
            if ($grant->role?->code !== $code) {
                return false;
            }

            return $grant->building_id === null || $grant->building_id === $buildingId;
        });
    }

    /**
     * Whether the user may do this **in this building**.
     *
     * The same shape as `hasRoleInBuilding()` above, asked one step further
     * back: not «which role is this» but «what does the role let its holder
     * do». A policy phrased this way survives a new role — the building
     * manager was added to `RoleCode::permissions()` and to nothing else — and
     * it keeps the horizontal boundary of FR-07 in the one place that has ever
     * enforced it, the `building_id` of the grant.
     */
    public function hasPermissionInBuilding(Permission $permission, Building|int|null $building): bool
    {
        $buildingId = $building instanceof Building ? $building->getKey() : $building;

        return $this->grants()->contains(function (RoleUser $grant) use ($permission, $buildingId): bool {
            if ($grant->role?->code?->grants($permission) !== true) {
                return false;
            }

            return $grant->building_id === null || $grant->building_id === $buildingId;
        });
    }

    /**
     * FR-41: whether the user may grant or revoke this role in this building.
     *
     * Two conditions in one question, and they must stay in one question. The
     * role has to be one the holder's own role may hand out
     * (`RoleCode::grantableRoles()`), and the grant that carries that right
     * has to name the building being written into. Asked separately, a warden
     * of block 1 holding a duty officer's grant in block 2 could appoint staff
     * in block 2, which is precisely the leak FR-07 exists to close.
     */
    public function mayGrantInBuilding(RoleCode $code, Building|int|null $building): bool
    {
        $buildingId = $building instanceof Building ? $building->getKey() : $building;

        return $this->grants()->contains(function (RoleUser $grant) use ($code, $buildingId): bool {
            $holder = $grant->role?->code;

            if ($holder === null || ! in_array($code, $holder->grantableRoles(), true)) {
                return false;
            }

            return $grant->building_id === null || $grant->building_id === $buildingId;
        });
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(RoleCode::Administrator);
    }

    /**
     * The buildings the user holds any staff role in. An administrator holds
     * a system-wide grant and is therefore not confined to this list; every
     * other role is.
     *
     * @return array<int, int>
     */
    public function scopedBuildingIds(): array
    {
        return $this->grants()
            ->pluck('building_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function isActive(): bool
    {
        return $this->status->canSignIn();
    }

    /**
     * @return Collection<int, RoleUser>
     */
    private function grants(): Collection
    {
        if (! $this->relationLoaded('roleGrants')) {
            $this->load('roleGrants.role');
        }

        return $this->roleGrants;
    }
}
