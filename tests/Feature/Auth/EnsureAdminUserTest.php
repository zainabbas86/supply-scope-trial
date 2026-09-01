<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates the account from the environment', function () {
    config()->set('access.admin', [
        'username' => 'admin',
        'name' => 'Admin',
        'password' => 'a-sufficiently-long-password',
        'min_password_length' => 12,
    ]);

    $this->artisan('app:ensure-admin')->assertSuccessful();

    $user = User::sole();
    expect($user->email)->toBe('admin')
        ->and($user->name)->toBe('Admin')
        // Never stored in the clear.
        ->and($user->password)->not->toBe('a-sufficiently-long-password')
        ->and(Hash::check('a-sufficiently-long-password', $user->password))->toBeTrue();
});

it('is idempotent and rotates the password rather than duplicating the account', function () {
    // Run alongside migrations on every deploy, so running twice must be
    // harmless — and changing ADMIN_PASSWORD must actually take effect.
    config()->set('access.admin', [
        'username' => 'admin', 'name' => 'Admin',
        'password' => 'first-password-here', 'min_password_length' => 12,
    ]);
    $this->artisan('app:ensure-admin')->assertSuccessful();

    config()->set('access.admin.password', 'second-password-here');
    $this->artisan('app:ensure-admin')->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(Hash::check('second-password-here', User::sole()->password))->toBeTrue()
        ->and(Hash::check('first-password-here', User::sole()->password))->toBeFalse();
});

it('creates nothing when no password is configured', function () {
    // The most important branch. A default like "password" on a publicly
    // reachable host is strictly worse than having no way in at all.
    config()->set('access.admin', [
        'username' => 'admin', 'name' => 'Admin',
        'password' => null, 'min_password_length' => 12,
    ]);

    $this->artisan('app:ensure-admin')->assertSuccessful();

    expect(User::count())->toBe(0);
});

it('creates nothing when no username is configured', function () {
    config()->set('access.admin', [
        'username' => null, 'name' => 'Admin',
        'password' => 'a-sufficiently-long-password', 'min_password_length' => 12,
    ]);

    // Succeeds rather than fails: an environment with no admin credentials is
    // a valid configuration (CI, the test image), and failing here would break
    // every pipeline that runs this defensively.
    $this->artisan('app:ensure-admin')->assertSuccessful();

    expect(User::count())->toBe(0);
});

it('fails loudly on a password that is too short', function () {
    // Credentials WERE supplied and were rejected. Succeeding quietly would
    // leave the operator believing the account exists.
    config()->set('access.admin', [
        'username' => 'admin', 'name' => 'Admin',
        'password' => 'short', 'min_password_length' => 12,
    ]);

    $this->artisan('app:ensure-admin')->assertFailed();

    expect(User::count())->toBe(0);
});
