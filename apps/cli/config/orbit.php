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

    'github' => [
        // How github:app:install waits for a new installation, which GitHub never announces.
        'install_poll_seconds' => 3,
        'install_wait_seconds' => 600,
    ],
];
