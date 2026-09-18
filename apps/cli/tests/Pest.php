<?php

declare(strict_types=1);

use Tests\TestCase;

require_once __DIR__.'/Helpers/GatewayFixtures.php';
require_once __DIR__.'/Helpers/InstanceCommandFixtures.php';

uses(TestCase::class)->in('Feature');

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

if (is_string($tiaDirectory) && $tiaDirectory !== '') {
    pest()->tia()->directory($tiaDirectory);
}
