<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /**
     * The catalogue is cached now, and the test cache store is `array` — which
     * lives for the whole PHP process, not for one test. Without this, a mega
     * menu built from one test's fixtures would still be sitting there for the
     * next test, which builds a different catalogue and would be handed the
     * previous one.
     *
     * withoutVite: CI runs the PHP suites on a fresh checkout, where
     * public/build does not exist — `npm run build` happens only in the Lint &
     * Build job. Every page render then threw ViteManifestNotFoundException and
     * answered 500, so the suites passed only on a machine that happened to have
     * built assets lying around. What a page renders is under test here, not
     * whether its bundle was compiled.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->withoutVite();
    }
}
