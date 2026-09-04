<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| The site root, and where the app actually lives
|--------------------------------------------------------------------------
|
| The domain root is a public portfolio page; the extraction app sits beneath
| it at config('site.app_prefix').
|
| Every other test in the suite now builds URLs with route(), which is correct
| — a test should not break because a prefix moved. But that also means NOTHING
| in the suite would notice if the prefix silently changed or disappeared, and
| the whole point of it is that a specific public URL keeps working.
|
| So these tests assert the literal paths on purpose. They are the one place
| that does.
|
*/

it('serves the portfolio at the domain root without a login', function () {
    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('Portfolio')
    );
});

it('still shows the portfolio to a signed-in user', function () {
    // '/' is outside the auth middleware, not merely a redirect for guests.
    // If this ever redirected into the app, signing in would make the
    // portfolio unreachable for its owner.
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('Portfolio')
    );
});

it('passes the contact links the page renders', function () {
    $this->get('/')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('contact.email', config('site.contact.email'))
            ->where('contact.github', config('site.contact.github'))
            ->where('contact.linkedin', config('site.contact.linkedin'))
    );
});

it('mounts the extraction app at the published prefix', function () {
    // The literal URL that goes on a CV and in a submission email. If this
    // test fails, a link someone else is holding has broken.
    expect(route('login', absolute: false))->toBe('/labelextractionagent/login');
    expect(route('documents.index', absolute: false))->toBe('/labelextractionagent');
    expect(route('documents.store', absolute: false))->toBe('/labelextractionagent/documents');
});

it('shares the app base with the frontend so links match the routes', function () {
    // The React side builds every internal URL from this prop via appUrl().
    // If it drifts from the actual prefix, every link 404s while the server
    // routes stay perfectly correct.
    $this->get('/')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('appBase', '/'.config('site.app_prefix'))
    );
});

it('sends a signed-in user to the app, not to the portfolio', function () {
    $user = User::factory()->create([
        'email' => 'admin',
        'password' => bcrypt('correct-horse-battery'),
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('documents.index'));
});

it('keeps the health endpoint at the root, outside the prefix', function () {
    // Probes are configured with a URL, not with route(). Moving /up under the
    // prefix would break the compose healthcheck silently.
    $this->get('/up')->assertOk();
    $this->get('/labelextractionagent/up')->assertNotFound();
});

it('sends an already-authenticated visitor from login into the app', function () {
    // The portfolio card links to the LOGIN url, so a signed-in visitor
    // clicking it hits a `guest` route. Laravel's default for that is a
    // redirect to '/', which is now the portfolio — so the card would bounce
    // a logged-in user straight back to the page they clicked from.
    $this->actingAs(User::factory()->create());

    $this->get(route('login'))->assertRedirect(route('documents.index'));
});
