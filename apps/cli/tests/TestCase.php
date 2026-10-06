<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Extensions\GatewayExtensionState;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GatewayExtensionState::reset();
    }
}
