<?php

declare(strict_types=1);

use Design\Flows\InstanceShowFlowCommand;
use Illuminate\Filesystem\Filesystem;

it('reads the recorded instance summary', function (): void {
    $summary = show_flow_call('instanceSummary', 'instances/instance-show/charlie-shop-dev');

    expect($summary)->toBe([
        'id' => 1,
        'name' => 'dev',
        'slug' => 'charlie-shop',
        'node' => 'app-dev',
        'status' => 'active',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/charlie-shop/dev',
        'vite_port' => 5173,
        'apps' => ['web: web · web root public · laravel-app'],
        'selected_branch' => 'dev',
        'branch_override' => null,
        'domain' => 'web.dev.charlie-shop.test',
        'url' => 'https://web.dev.charlie-shop.test',
    ]);
});

it('reads the recorded process rows', function (): void {
    $rows = show_flow_call('processRows', 'processes/process-list/instance');

    expect($rows)->toBe([
        1 => ['1', 'queue', 'systemd', 'running', 'running', 'active'],
        2 => ['2', 'scheduler', 'systemd', 'running', 'running', 'active'],
    ]);
});

it('refuses a fixture whose shape cannot be shown', function (string $method, string $contents): void {
    $root = sys_get_temp_dir().'/orbit-show-fixtures-'.bin2hex(random_bytes(4));
    mkdir($root, 0700);
    file_put_contents($root.'/bad.json', $contents);

    try {
        expect(fn () => show_flow_call($method, 'bad', $root))
            ->toThrow(RuntimeException::class, 'Gateway fixture bad is not recorded.');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'instance data is a list' => ['instanceSummary', show_flow_fixture([])],
    'instance project is missing' => ['instanceSummary', show_flow_fixture(show_flow_instance(project: null))],
    'instance id is text' => ['instanceSummary', show_flow_fixture(show_flow_instance(id: '1'))],
    'process data is an object' => ['processRows', show_flow_fixture(['id' => 1])],
    'process row is a list' => ['processRows', show_flow_fixture([['queue']])],
    'process name is a number' => ['processRows', show_flow_fixture([show_flow_process(name: 1)])],
    'fixture is not an object' => ['instanceSummary', '[]'],
    'fixture body is text' => ['instanceSummary', '{"request":"Example","status":200,"body":"nope"}'],
]);

function show_flow_call(string $method, string $name, ?string $root = null): mixed
{
    $command = new InstanceShowFlowCommand;

    if ($root !== null) {
        $command->useFixtureRoot($root);
    }

    return (new ReflectionMethod(InstanceShowFlowCommand::class, $method))->invoke($command, $name);
}

function show_flow_fixture(mixed $data): string
{
    return json_encode([
        'request' => 'Example',
        'status' => 200,
        'body' => ['data' => $data],
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function show_flow_instance(mixed $id = 1, mixed $project = ['slug' => 'charlie-shop']): array
{
    return [
        'id' => $id,
        'name' => 'dev',
        'project' => $project,
        'node' => ['name' => 'app-dev'],
        'status' => 'active',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/apps/charlie-shop/dev',
        'vite_port' => 5173,
        'apps' => [['name' => 'web', 'path' => 'web', 'web_root' => 'public', 'type' => 'laravel-app']],
        'selected_branch' => 'dev',
        'branch_override' => null,
        'domain' => 'web.dev.charlie-shop.test',
        'url' => 'https://web.dev.charlie-shop.test',
    ];
}

/**
 * @return array<string, mixed>
 */
function show_flow_process(mixed $name = 'queue'): array
{
    return [
        'id' => 1,
        'name' => $name,
        'runtime' => 'systemd',
        'desired_state' => 'running',
        'runtime_status' => 'running',
        'status' => 'active',
    ];
}
