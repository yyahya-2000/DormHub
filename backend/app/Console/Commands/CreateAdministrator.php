<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RoleCode;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The first account of a deployment.
 *
 * `db:seed` is how a local environment gets an administrator, and it also
 * plants the demonstration accounts whose password is `password`. A server
 * reachable from the internet needs the first half without the second.
 *
 * The password is generated rather than asked for, so it never travels through
 * a shell history, and `password_change_required` is set, so it is replaced on
 * first sign-in.
 */
final class CreateAdministrator extends Command
{
    protected $signature = 'dormitory:create-administrator {email} {name}';

    protected $description = 'Create an administrator account with a generated one-time password';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (User::query()->where('email', $email)->exists()) {
            $this->error(sprintf('An account for %s already exists.', $email));

            return self::FAILURE;
        }

        $role = Role::query()->where('code', RoleCode::Administrator->value)->first();

        if ($role === null) {
            $this->error('The role table is empty. Run: php artisan db:seed --class=RoleSeeder --database=pgsql_owner');

            return self::FAILURE;
        }

        $password = Str::password(20, symbols: false);

        $user = User::query()->create([
            'email' => $email,
            'full_name' => (string) $this->argument('name'),
            'password_hash' => $password,
            'password_change_required' => true,
            'status' => UserStatus::Active,
        ]);

        // The administrator holds the role over the system, so no building.
        $user->roleGrants()->create([
            'role_id' => $role->getKey(),
            'building_id' => null,
            'granted_at' => now(),
        ]);

        $this->info(sprintf('Administrator %s created. One-time password: %s', $email, $password));

        return self::SUCCESS;
    }
}
