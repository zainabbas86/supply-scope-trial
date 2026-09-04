<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Do not resolve the Vite manifest during tests.
         *
         * Rendering any Inertia page runs `@vite(...)` in the root Blade view,
         * which reads `public/build/manifest.json`. That file is a BUILD
         * ARTIFACT: gitignored, absent from a fresh checkout, and produced by a
         * different CI job than the one running these tests. Four feature tests
         * passed locally — where a stale build happened to be on disk — and
         * returned 500 on GitHub Actions with ViteManifestNotFoundException.
         *
         * `withoutVite()` swaps in a no-op, so a test asserting on an Inertia
         * component and its props does not also depend on the asset pipeline
         * having been built. That coupling is accidental, not meaningful.
         *
         * Nothing is lost: the asset build is verified by its own CI job
         * (`npm run build`) and again inside the Docker `assets` stage, which
         * type-checks before it builds.
         */
        $this->withoutVite();
    }
}
