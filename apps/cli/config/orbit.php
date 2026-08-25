<?php

declare(strict_types=1);

$configuredHome = env('ORBIT_HOME');
$userHome = getenv('HOME');
$orbitHome = is_string($configuredHome) && $configuredHome !== ''
    ? $configuredHome
    : $userHome;

if (! is_string($orbitHome) || $orbitHome === '') {
    $orbitHome = '.';
}

return [
    'home' => $configuredHome === $orbitHome ? $orbitHome : $orbitHome.'/.orbit',
];
