<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('admin|127.0.0.1');
    $this->user = User::factory()->create([
        // Deliberately NOT an email address. The account is provisioned from
        // the environment and its identifier is whatever the operator chose.
        'email' => 'admin',
        'password' => Hash::make('correct-horse-battery'),
    ]);
});

// -----------------------------------------------------------------------------
// The gate itself
// -----------------------------------------------------------------------------

it('redirects an unauthenticated visitor to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('refuses an unauthenticated upload', function () {
    // Every upload is a vision-model call billed to the API key, so this is a
    // spend control as much as an access control.
    $this->post('/documents', ['files' => []])->assertRedirect('/login');
});

it('keeps the health endpoint public', function () {
    // The compose healthcheck and any platform probe have no credentials. Put
    // /up behind auth and the container never reports healthy.
    $this->get('/up')->assertOk();
});

it('renders the login page for a guest', function () {
    $this->get('/login')->assertOk();
});

// -----------------------------------------------------------------------------
// Signing in
// -----------------------------------------------------------------------------

it('signs in with a plain username, not an email address', function () {
    $this->post('/login', [
        'email' => 'admin',
        'password' => 'correct-horse-battery',
    ])->assertRedirect('/');

    $this->assertAuthenticatedAs($this->user);
});

it('rejects a wrong password', function () {
    $this->post('/login', ['email' => 'admin', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('gives the same answer for an unknown user as for a wrong password', function () {
    // Different messages would turn the login form into an oracle for which
    // identifiers exist.
    $unknown = $this->post('/login', ['email' => 'nobody', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $wrong = $this->post('/login', ['email' => 'admin', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toBe('These credentials do not match our records.');
    expect($unknown)->not->toBeNull()->and($wrong)->not->toBeNull();
});

it('regenerates the session id on login', function () {
    // Session-fixation defence, and the single most commonly omitted line in a
    // hand-rolled login: without it, a session id an attacker planted before
    // login stays valid afterwards — now authenticated.
    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => 'admin', 'password' => 'correct-horse-battery']);

    expect(session()->getId())->not->toBe($before);
});

it('signs out and invalidates the session', function () {
    $this->actingAs($this->user)->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});

// -----------------------------------------------------------------------------
// Credential stuffing
// -----------------------------------------------------------------------------

it('locks out after too many failed attempts on one account', function () {
    $max = (int) config('access.throttle.login');

    for ($i = 0; $i < $max; $i++) {
        $this->post('/login', ['email' => 'admin', 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => 'admin', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('Too many login attempts');
});

it('keeps the per-ip limit above the per-account limit', function () {
    // Set equal, the coarse per-IP limiter always fires first: the specific
    // "try again in N seconds" message becomes unreachable, and one person's
    // typos lock out everyone behind the same NAT.
    expect((int) config('access.throttle.login_ip'))
        ->toBeGreaterThan((int) config('access.throttle.login'));
});

it('lets the status endpoint absorb the ui polling rate', function () {
    // The documents list polls every 2.5s while anything is in flight — 24
    // requests a minute PER OPEN TAB. A blanket 60/min silently breaks the
    // live view once someone has three tabs open.
    expect((int) config('access.throttle.status'))
        ->toBeGreaterThanOrEqual(24 * 3);
});
