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
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }
}
