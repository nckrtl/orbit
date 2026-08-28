<?php

declare(strict_types=1);

return [
    'profile' => env('ORBIT_E2E_PROFILE', 'gateway_app-dev_app-prod'),
    'incus' => [
        'remote' => env('ORBIT_E2E_INCUS_REMOTE', 'local'),
        'project' => env('ORBIT_E2E_INCUS_PROJECT', 'default'),
        'storage_pool' => env('ORBIT_E2E_INCUS_STORAGE_POOL', 'orbit-e2e'),
        'operation_id' => env('ORBIT_E2E_OPERATION_ID'),
        'ownership' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
        ],
    ],
];
