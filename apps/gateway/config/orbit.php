<?php

declare(strict_types=1);

$configuredHome = env('ORBIT_HOME');
$userHome = getenv('HOME');
$orbitHome = base_path('.orbit');

if (is_string($userHome) && $userHome !== '') {
    $orbitHome = $userHome.'/.orbit';
}

if (is_string($configuredHome) && $configuredHome !== '') {
    $orbitHome = $configuredHome;
}

return [
    'home' => rtrim(string: $orbitHome, characters: '/'),
    'gateway_checkout' => rtrim(
        string: env(key: 'ORBIT_GATEWAY_CHECKOUT', default: '/home/orbit/orbit-gateway'),
        characters: '/',
    ),
    'app_dev_domain' => trim(
        string: env(key: 'ORBIT_APP_DEV_DOMAIN', default: 'orbit'),
        characters: '.',
    ),
    'command_timeout' => 900.0,
    'hibernation' => [
        'idle_seconds' => max(1, (int) env('ORBIT_HIBERNATION_IDLE_SECONDS', 3600)),
        'sweep_seconds' => max(60, (int) env('ORBIT_HIBERNATION_SWEEP_SECONDS', 600)),
        'wake_timeout_seconds' => max(5, (int) env('ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS', 60)),
    ],
];
