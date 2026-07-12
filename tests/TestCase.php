<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate-limiter hits live in the array cache, which persists across tests in
        // the same process — flush so every test starts with fresh throttle buckets.
        $this->app['cache']->flush();
    }
}
