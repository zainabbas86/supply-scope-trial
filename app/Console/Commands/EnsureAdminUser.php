<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create or rotate the single application account from the environment.
 *
 * Run explicitly alongside migrations:
 *
 *     docker compose run --rm migrate      # then
 *     docker compose run --rm web php artisan app:ensure-admin
 *
 * Deliberately NOT run from the container entrypoint. Provisioning on every
 * boot races across replicas, for the same reason migrations do not run on
 * boot — two containers starting together would both try to create the row.
 *
 * Deliberately NOT a migration either: migrations are schema and run once, so
 * a changed password in the environment would never take effect.
 */
class EnsureAdminUser extends Command
{
    protected $signature = 'app:ensure-admin';

    protected $description = 'Create or update the seeded administrator from ADMIN_* environment variables';

    public function handle(): int
    {
        $email = config('access.admin.username');
        $name = config('access.admin.name');
        $password = config('access.admin.password');
        $minLength = (int) config('access.admin.min_password_length');

        if (blank($email)) {
            $this->components->warn('ADMIN_USERNAME is not set — no account created.');

            // Not a failure: an environment without admin credentials is a
            // valid configuration (CI, the test image). Returning FAILURE here
            // would break every pipeline that runs this defensively.
            return self::SUCCESS;
        }

        // The important branch. An unset password must create nothing at all.
        // Falling back to a default would put a guessable credential on a
        // public host, which is strictly worse than having no way in.
        if (blank($password)) {
            $this->components->warn('ADMIN_PASSWORD is not set — no account created. Set it and re-run.');

            return self::SUCCESS;
        }

        if (mb_strlen((string) $password) < $minLength) {
            $this->components->error("ADMIN_PASSWORD must be at least {$minLength} characters.");

            // This one IS a failure: credentials were supplied and rejected.
            // Silently continuing would leave the operator believing the
            // account exists.
            return self::FAILURE;
        }

        // updateOrCreate keyed on email makes this idempotent: running it twice
        // yields one account, and re-running with a new ADMIN_PASSWORD rotates
        // the credential rather than erroring or creating a second user.
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                // Hash::make explicitly rather than relying on the model's
                // 'hashed' cast, so this is obvious at the call site — the one
                // place in the app that writes a password.
                'password' => Hash::make((string) $password),
            ],
        );

        $this->components->info(
            $user->wasRecentlyCreated
                ? "Created administrator {$email}."
                : "Updated administrator {$email} (password rotated)."
        );

        return self::SUCCESS;
    }
}
