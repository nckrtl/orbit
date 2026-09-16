<?php

declare(strict_types=1);

use App\Support\Console\InterruptIntent;
use Tests\TestCase;

require_once __DIR__.'/Helpers/InstanceCommandFixtures.php';

uses(TestCase::class)->in('Feature');

uses()->beforeEach(function (): void {
    InterruptIntent::clear();
})->in('Feature', 'Unit');

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

if (is_string($tiaDirectory) && $tiaDirectory !== '') {
    pest()->tia()->directory($tiaDirectory);
}
