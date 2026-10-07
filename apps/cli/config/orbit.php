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

    // The files the Gateway's agent converge writes on a managed Linux Node (ADR 0202). `orbit self-update` reads
    // the unit marker to know it runs on such a Node and replaces the binary when it differs from the pin.
    'self_update' => [
        'agent_unit' => '/etc/systemd/system/orbit-agent.service',
        'agent_binary' => '/usr/local/bin/orbit-agent',
    ],

    'github' => [
        // How github:app:install waits for a new installation, which GitHub never announces.
        'install_poll_seconds' => 3,
        'install_wait_seconds' => 600,
    ],
];
