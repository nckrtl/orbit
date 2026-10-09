<?php

declare(strict_types=1);

/*
 * Task VMs: one disposable `app-dev` Node per task group. Off by default.
 * App\Domain\TaskVms\TaskVmSettings validates these values once, whenever they are set; read
 * them through it. Unset values stay absent. Malformed JSON becomes null and fails validation.
 */
return [
    'enabled' => (bool) env('ORBIT_TASK_VMS_ENABLED', false),
    'dev_cluster_id' => env('ORBIT_TASK_VMS_DEV_CLUSTER_ID'),
    'wireguard_range' => env('ORBIT_TASK_VMS_WIREGUARD_RANGE') ?: '10.44.0.128/25',
    'model_proxy_origin' => env('ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN'),
    'pi' => [
        'artifact_path' => env('ORBIT_TASK_VMS_PI_ARTIFACT_PATH'),
        'artifact_sha256' => env('ORBIT_TASK_VMS_PI_ARTIFACT_SHA256'),
        'models' => json_decode((string) (env('ORBIT_TASK_VMS_PI_MODELS') ?: '[]'), true),
    ],
    'incus' => [
        /*
         * A JSON list of hosts: node_id, cidr (the bridge network) and max_vms are required;
         * project (orbit-tasks), network (orbittask0), image (ubuntu-26.04-vm), cpus (2),
         * memory (4GiB), disk (20GiB) and pool (default) are optional.
         */
        'hosts' => json_decode((string) (env('ORBIT_TASK_VMS_INCUS_HOSTS') ?: '[]'), true),
    ],
];
