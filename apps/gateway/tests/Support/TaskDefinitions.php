<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Node;
use App\Models\Project;

function task_definition_gateway(string $name = 'definition-gateway', string $ip = '10.44.0.90'): Node
{
    $gateway = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => $ip,
    ]);
    test()->markAsGateway($gateway);
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();

    return $gateway;
}

function task_definition_project(string $slug = 'definitions'): Project
{
    return Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
}

/** @param array<string, mixed> $overrides */
function task_definition_payload(array $overrides = []): array
{
    return array_replace([
        'name' => 'build-feature',
        'title' => 'Build a feature',
        'brief' => 'Document and build it.',
        'parameters' => [],
        'status' => 'backlog',
        'subtasks' => [
            ['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent'],
        ],
    ], $overrides);
}
