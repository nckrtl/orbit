<?php

declare(strict_types=1);

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;

/**
 * A storage Process with the environment and published ports Plausible connects through.
 *
 * @param  array<string, string>  $environment
 * @param  list<string>  $ports
 */
function analytics_connection_process(Node $node, string $name, string $image, array $environment, array $ports): Process
{
    return Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/',
        'runtime_config' => [
            'image' => $image,
            'command' => [],
            'environment' => $environment,
            'ports' => $ports,
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}
