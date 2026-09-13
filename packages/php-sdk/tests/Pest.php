<?php

declare(strict_types=1);

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

if (is_string($tiaDirectory) && $tiaDirectory !== '') {
    pest()->tia()->directory($tiaDirectory);
}
