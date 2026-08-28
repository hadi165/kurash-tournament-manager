<?php

namespace Tests;

use App\Support\DatabaseGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application, and refuse to go any further if the suite is
     * pointed at a database that holds real data.
     *
     * refreshApplication() is the hook rather than setUp() or
     * beforeRefreshingDatabase(), and the choice matters. Laravel's
     * setUpTheTestEnvironment() calls refreshApplication() and only then
     * setUpTraits(), which is where RefreshDatabase drops and re-migrates.
     * Guarding here therefore runs after the container exists — so the resolved
     * database name is readable — and before anything touches a connection.
     *
     * beforeRefreshingDatabase() would have been the obvious hook and is the
     * wrong one: it fires only for tests that use RefreshDatabase, and a test
     * that writes to the database without the trait would sail past it. Every
     * test in this suite goes through here.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        DatabaseGuard::assertSafeToDestroy('running the test suite');
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
