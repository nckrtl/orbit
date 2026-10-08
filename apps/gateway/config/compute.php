<?php

declare(strict_types=1);

return [
    'project_claims_enabled' => (bool) env('ORBIT_SANDBOX_PROJECT_CLAIMS_ENABLED', false),
    'orbit_claims_enabled' => (bool) env('ORBIT_SANDBOX_ORBIT_CLAIMS_ENABLED', false),
    'model_proxy' => [
        'enabled' => (bool) env('ORBIT_SANDBOX_MODEL_PROXY_ENABLED', false),
    ],
    'pi' => [
        'artifact_path' => env('ORBIT_SANDBOX_PI_ARTIFACT_PATH'),
        'artifact_sha256' => env('ORBIT_SANDBOX_PI_ARTIFACT_SHA256'),
        'models' => json_decode((string) env('ORBIT_SANDBOX_PI_MODELS', '[]'), true),
    ],
    'incus' => [
        'enrollment_enabled' => (bool) env('ORBIT_INCUS_ENROLLMENT_ENABLED', false),
        'dev_cluster_id' => (int) env('ORBIT_INCUS_DEV_CLUSTER_ID', 0),
        'model_address' => env('ORBIT_INCUS_MODEL_ADDRESS'),
        'model_port' => (int) env('ORBIT_INCUS_MODEL_PORT', 8317),
        'enabled' => (bool) env('ORBIT_INCUS_ENABLED', false),
        'hosts' => json_decode((string) env('ORBIT_INCUS_HOSTS', '[]'), true),
    ],
    'upcloud' => [
        'enrollment_enabled' => (bool) env('ORBIT_UPCLOUD_ENROLLMENT_ENABLED', false),
        'dev_cluster_id' => (int) env('ORBIT_UPCLOUD_DEV_CLUSTER_ID', 0),
        'model_address' => env('ORBIT_UPCLOUD_MODEL_ADDRESS'),
        'model_port' => (int) env('ORBIT_UPCLOUD_MODEL_PORT', 8317),
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
