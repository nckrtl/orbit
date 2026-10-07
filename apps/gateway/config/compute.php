<?php

declare(strict_types=1);

return [
    'model_proxy' => [
        'enabled' => (bool) env('ORBIT_SANDBOX_MODEL_PROXY_ENABLED', false),
    ],
    'pi' => [
        'models' => json_decode((string) env('ORBIT_SANDBOX_PI_MODELS', '[]'), true),
    ],
    'incus' => [
        'enabled' => (bool) env('ORBIT_INCUS_ENABLED', false),
        'hosts' => json_decode((string) env('ORBIT_INCUS_HOSTS', '[]'), true),
    ],
    'upcloud' => [
        'enabled' => (bool) env('ORBIT_UPCLOUD_ENABLED', false),
        'token_file' => env('ORBIT_UPCLOUD_TOKEN_FILE'),
        'max_vms' => max(0, (int) env('ORBIT_UPCLOUD_MAX_VMS', 0)),
        'zone' => env('ORBIT_UPCLOUD_ZONE', 'nl-ams1'),
        'image' => '01000000-0000-4000-8000-000030260200',
        'gateway_address' => env('ORBIT_UPCLOUD_GATEWAY_ADDRESS'),
        'wireguard_address' => env('ORBIT_UPCLOUD_WIREGUARD_ADDRESS'),
        'wireguard_port' => (int) env('ORBIT_UPCLOUD_WIREGUARD_PORT', 51820),
    ],
];
