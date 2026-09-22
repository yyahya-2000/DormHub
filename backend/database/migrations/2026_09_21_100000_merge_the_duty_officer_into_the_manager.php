<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Revision 4 of the role model (21.09.2026): the duty officer is merged into
 * the manager, and the row that named him leaves `roles`.
 *
 * **Why the data move is safe.** Revision 3 of the guest module had already
 * given `guest_requests.decide` to the warden and the manager. What the duty
 * officer held afterwards — the building card, the register of rooms read, the
 * roll of the dormitory, the guest queue read, the decision on a request — is
 * five capabilities of which the manager's list carries every one. Rewriting a
 * duty officer's grant into a manager's therefore takes nothing away from
 * anybody, and the enumeration is what says so: there is no arm left to
 * compare against once this migration has run, so the comparison was made
 * while both arms still existed.
 *
 * It does hand something over, and the migration is the wrong place to hide
 * it. The manager reads the resident card (FR-06) and the duty officer was
 * deliberately kept away from it. Every account promoted below gains
 * citizenship, telephone and the rest of the card of the residents of that
 * one building. The reading is audited under §3.9.6, so it is visible
 * afterwards; an operator who does not want it has to revoke the grant this
 * migration writes, because the capability is no longer separable from it.
 *
 * **The duplicate.** Nothing ever stopped one person from holding both roles
 * in the same dormitory — the two were different rows and the partial unique
 * index on `(user_id, role_id, building_id)` had no opinion about the pair.
 * Promote such a duty officer's row and it collides with the manager's row
 * that is already there, and the index refuses the update. The duplicates are
 * therefore deleted before the promotion rather than merged into it: the
 * manager's grant already conveys everything the duty officer's did, so the
 * row that survives is the one that was already the wider of the two, and it
 * survives untouched — its `granted_by` and `granted_at` are the ones the
 * account keeps. What is lost is the date on which that person was also made
 * duty officer there, which is not lost at all: the appointment was written to
 * `audit_logs` as `staff.appointed` with the role code in its payload, and
 * that table is append-only by §4.4.4. The log is deliberately left alone —
 * it records what happened, and a duty officer was appointed.
 *
 * The promotion touches `role_id` only. On PostgreSQL the trigger
 * `role_user_fill_role_code_trg` fires on an update of that column and rewrites
 * the `role_code` copy the CHECK constraint judges, so the column is correct
 * without being named here; on a driver where that migration reported itself
 * skipped, the column does not exist. Neither case needs a branch.
 */
return new class extends Migration
{
    /**
     * The codes as they stood on 21.09.2026, written out rather than read from
     * `RoleCode`: the enumeration no longer has a case for the first of them,
     * and a migration describes the database at the moment it ran.
     */
    private const MERGED_ROLE = 'duty_officer';

    private const SURVIVING_ROLE = 'manager';

    public function up(): void
    {
        $merged = DB::table('roles')->where('code', self::MERGED_ROLE)->first();

        if ($merged === null) {
            // A database seeded after this migration, or one the migration has
            // already run against. Neither is an error.
            return;
        }

        $surviving = DB::table('roles')->where('code', self::SURVIVING_ROLE)->first();

        if ($surviving === null) {
            throw new RuntimeException(
                'The manager role is missing, so the duty officer has nowhere to be merged into.'
                .' Run the reference seeder before this migration.'
            );
        }

        DB::transaction(function () use ($merged, $surviving): void {
            /*
             * The grants that would collide. `building_id` is compared with a
             * plain equality and both sides are known to be filled: the CHECK
             * constraint `role_user_scope_matches_role` allows a NULL scope to
             * the administrator alone, and neither of these two roles is him.
             */
            DB::table('role_user')
                ->where('role_id', $merged->id)
                ->whereExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('role_user as held')
                    ->where('held.role_id', $surviving->id)
                    ->whereColumn('held.user_id', 'role_user.user_id')
                    ->whereColumn('held.building_id', 'role_user.building_id'))
                ->delete();

            // Everything that is left is somebody's only claim on that
            // dormitory, and it becomes a manager's claim.
            DB::table('role_user')
                ->where('role_id', $merged->id)
                ->update(['role_id' => $surviving->id]);

            // ON DELETE RESTRICT stands between the two statements above and
            // this one: the row goes only because nothing references it now.
            DB::table('roles')->where('id', $merged->id)->delete();
        });
    }

    /**
     * Puts the row back into `roles` and stops there.
     *
     * **The grants cannot be restored, and this method does not pretend
     * otherwise.** After `up()` a promoted grant is indistinguishable from a
     * manager's grant written last week: nothing in `role_user` records which
     * role a row used to name. Rolling back therefore returns an empty duty
     * officer role — appointable again once the enumeration has a case for it,
     * held by nobody.
     *
     * Whoever needs the old distribution back reads it out of `audit_logs`,
     * which kept every `staff.appointed` and `staff.revoked` entry with the
     * role code in its payload, and appoints from that. It is a manual repair
     * and it is the only honest one.
     */
    public function down(): void
    {
        if (DB::table('roles')->where('code', self::MERGED_ROLE)->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'code' => self::MERGED_ROLE,
            'name' => 'Duty officer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
