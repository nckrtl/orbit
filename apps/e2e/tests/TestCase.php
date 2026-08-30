<?php

declare(strict_types=1);

namespace Tests;

use App\E2E\State\StatePaths;
use App\E2E\WorktreeLocator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TemporaryPaths;

abstract class TestCase extends BaseTestCase
{
    /** Feature tests never touch the real primary checkout's `.e2e/` or its worktrees. */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $primary = TemporaryPaths::path('orbit-test-primary-', 6);
        mkdir($primary, 0700, true);
        $this->app->instance(StatePaths::class, StatePaths::forPrimary($primary));
        $this->app->instance(WorktreeLocator::class, new WorktreeLocator($primary));
    }
}
