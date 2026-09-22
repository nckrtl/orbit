<?php

declare(strict_types=1);

pest()
    ->tia()
    ->locally()
    ->filtered();

$tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');

pest()->tia()->directory(is_string($tiaDirectory) && $tiaDirectory !== '' ? $tiaDirectory : dirname(__DIR__).'/.orbit-tia');
