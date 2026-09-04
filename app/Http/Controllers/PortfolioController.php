<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The public site root.
 *
 * Deliberately outside the `auth` middleware and outside the app prefix: this
 * is the page a recruiter lands on, and the extraction app is one link from it.
 *
 * The CV content itself lives in the React page rather than being passed as
 * props. It is static prose with no server-side source of truth, so routing it
 * through PHP would add a serialisation layer that nothing benefits from. Only
 * the contact links come from config, because those are the parts that might
 * plausibly need changing without a frontend rebuild.
 */
final class PortfolioController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Portfolio', [
            'contact' => config('site.contact'),
        ]);
    }
}
