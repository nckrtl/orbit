<?php

declare(strict_types=1);

return [
    // Which physical topology snapshot this checkout owns. The repository's primary
    // checkout owns the unnamespaced topology snapshot; the validation clone that
    // bin/e2e-live drives sets `live` so a promotion there never touches it.
    'topology_snapshot' => [
        'namespace' => env('ORBIT_E2E_TOPOLOGY_SNAPSHOT_NAMESPACE', ''),
    ],
    'incus' => [
        'remote' => env('ORBIT_E2E_INCUS_REMOTE', 'local'),
        'project' => env('ORBIT_E2E_INCUS_PROJECT', 'default'),
        'storage_pool' => env('ORBIT_E2E_INCUS_STORAGE_POOL', 'orbit-e2e'),
        'cpu' => env('ORBIT_E2E_INCUS_CPU', '1'),
        'memory' => env('ORBIT_E2E_INCUS_MEMORY', '2GiB'),
        'root_size' => env('ORBIT_E2E_INCUS_ROOT_SIZE', '16GiB'),
        'max_vms' => (int) env('ORBIT_E2E_INCUS_MAX_VMS', 24),
        'operation_id' => env('ORBIT_E2E_OPERATION_ID'),
        'ownership' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
        ],
    ],
];
