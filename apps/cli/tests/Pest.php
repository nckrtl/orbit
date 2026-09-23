<?php

declare(strict_types=1);

use Tests\TestCase;

require_once __DIR__.'/Helpers/GatewayFixtures.php';
require_once __DIR__.'/Helpers/InstanceCommandFixtures.php';
require_once __DIR__.'/Helpers/RealtimeFixtures.php';
require_once __DIR__.'/Helpers/TuiFixtures.php';

uses(TestCase::class)->in('Feature');

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

pest()->tia()->directory(is_string($tiaDirectory) && $tiaDirectory !== '' ? $tiaDirectory : dirname(__DIR__).'/.orbit-tia');
