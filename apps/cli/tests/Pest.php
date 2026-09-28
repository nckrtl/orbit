<?php

declare(strict_types=1);

use App\Services\Extensions\GatewayExtensionState;
use Tests\TestCase;

require_once __DIR__.'/Helpers/GatewayFixtures.php';
require_once __DIR__.'/Helpers/InstanceCommandFixtures.php';
require_once __DIR__.'/Helpers/RealtimeFixtures.php';

uses(TestCase::class)->in('Feature');

beforeEach(function (): void {
    GatewayExtensionState::reset();
});

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

pest()->tia()->directory(is_string($tiaDirectory) && $tiaDirectory !== '' ? $tiaDirectory : dirname(__DIR__).'/.orbit-tia');
