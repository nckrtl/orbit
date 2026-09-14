<?php

declare(strict_types=1);

use App\Commands\GatewayCommand;
use App\Commands\Schedules\ListSchedulesCommand;
use App\Commands\Schedules\RunScheduleCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('exposes only the implemented Orbit product commands', function (): void {
    $visibleCommands = collect(app(Kernel::class)->all())
        ->reject(static fn (Command $command): bool => $command->isHidden())
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($visibleCommands)->toBe([
        'activity:list',
        'activity:show',
        'app:create',
        'app:destroy',
        'app:list',
        'app:process-definition',
        'app:process-definitions',
        'app:schedule-definition',
        'app:schedule-definitions',
        'app:show',
        'cluster:create',
        'cluster:destroy',
        'cluster:list',
        'cluster:node:add',
        'cluster:node:remove',
        'cluster:router:set',
        'cluster:router:unset',
        'cluster:show',
        'cluster:update',
        'database:add',
        'database:attach',
        'database:detach',
        'database:list',
        'database:remove',
        'database:show',
        'database:update',
        'dns:resolve',
        'doctor',
        'env:import',
        'env:sync',
        'env:update',
        'firewall:allow',
        'firewall:deny',
        'firewall:list',
        'firewall:remove',
        'gateway:add',
        'gateway:remove',
        'gateway:status',
        'gateway:trust',
        'gateway:use',
        'herdr:observe',
        'herdr:session:create',
        'herdr:session:destroy',
        'herdr:session:list',
        'herdr:session:restart',
        'herdr:session:show',
        'instance:clone',
        'instance:create',
        'instance:deploy',
        'instance:deployment-config',
        'instance:destroy',
        'instance:list',
        'instance:prepare-deployment',
        'instance:register',
        'instance:release:list',
        'instance:rollback',
        'instance:show',
        'metrics:credentials',
        'metrics:disable',
        'metrics:enable',
        'metrics:exporter:disable',
        'metrics:exporter:enable',
        'metrics:status',
        'node:access:add',
        'node:access:remove',
        'node:list',
        'node:provision',
        'node:remove',
        'node:role:add',
        'node:role:list',
        'node:role:remove',
        'node:settings',
        'node:show',
        'process:create',
        'process:destroy',
        'process:list',
        'process:logs',
        'process:restart',
        'process:start',
        'process:stop',
        'route:create',
        'route:destroy',
        'route:list',
        'route:show',
        'route:target:set',
        'route:target:unset',
        'route:update',
        'schedule:create',
        'schedule:destroy',
        'schedule:enable',
        'schedule:list',
        'schedule:logs',
        'schedule:run',
        'schedule:show',
        'tool:install',
        'tool:list',
        'tool:manager:list',
        'tool:remove',
        'tool:show',
        'tool:update',
        'workspace:list',
        'workspace:new',
        'workspace:php',
        'workspace:remove',
        'workspace:show',
    ]);
});

it('rejects each replaced App Cluster and Route lifecycle name as an unknown command', function (string $command): void {
    $output = new BufferedOutput;
    $status = app(Kernel::class)->handle(new StringInput($command), $output);

    expect(collect(app(Kernel::class)->all())->keys()->all())
        ->not->toContain($command)
        ->and($status)
        ->toBe(Command::FAILURE)
        ->and(trim($output->fetch()))
        ->toContain(sprintf('Command "%s" is not defined.', $command));
})->with([
    'app:new',
    'app:remove',
    'cluster:new',
    'cluster:remove',
    'cluster:node:attach',
    'cluster:node:detach',
    'cluster:router:clear',
    'route:new',
    'route:remove',
    'route:target:clear',
]);

it('does not register hidden Orbit product commands', function (): void {
    $orbitCommands = collect(app(Kernel::class)->all())
        ->filter(static fn (Command $command): bool => str_starts_with($command::class, 'App\\Commands\\'));

    expect($orbitCommands)->toHaveCount(105);
    expect($orbitCommands->every(
        static fn (Command $command): bool => ! $command->isHidden(),
    ))->toBeTrue();
});

it('registers only the Orbit Schedule adapters', function (): void {
    $commands = app(Kernel::class)->all();

    expect($commands)
        ->toHaveKeys(['schedule:list', 'schedule:run'])
        ->not->toHaveKeys([
            'schedule:finish',
            'schedule:complete',
        ]);
    expect($commands['schedule:list'])->toBeInstanceOf(ListSchedulesCommand::class);
    expect($commands['schedule:run'])->toBeInstanceOf(RunScheduleCommand::class);
});

it('does not expose replaced instance lifecycle names', function (): void {
    expect(app(Kernel::class)->all())->not->toHaveKeys([
        'instance:new',
        'instance:remove',
        'instance:releases',
    ]);
});

it('keeps the hidden Boost MCP entrypoint available to coding agents', function (): void {
    $commands = app(Kernel::class)->all();
    $codexConfig = file_get_contents(base_path('.codex/config.toml'));

    expect($commands)
        ->toHaveKeys(['boost:mcp', 'mcp:start'])
        ->and($commands['boost:mcp']->isHidden())
        ->toBeTrue()
        ->and($commands['mcp:start']->isHidden())
        ->toBeTrue()
        ->and($codexConfig)
        ->toBeString()
        ->toContain('args = ["orbit", "boost:mcp"]');
});

it('offers JSON output for every Orbit product command', function (): void {
    $commands = collect(app(Kernel::class)->all())
        ->reject(static fn (Command $command): bool => $command->isHidden());

    expect($commands->every(
        static fn (Command $command): bool => $command->getDefinition()->hasOption('json'),
    ))->toBeTrue();
});

it('describes the environment lifecycle in command help', function (): void {
    $commands = app(Kernel::class)->all();

    expect($commands['env:import']->getDescription())
        ->toBe('Import the workload environment file into stored AppInstance configuration.');
    expect($commands['env:import']->getDefinition()->getOption('replace')->getDescription())
        ->toBe('Replace stored-key conflicts while retaining other stored keys');
    expect($commands['env:update']->getDescription())
        ->toBe('Update one stored AppInstance environment value without changing the workload file.');
    expect($commands['env:update']->getDefinition()->getOption('value')->getDescription())
        ->toContain('quote empty, multiline, or placeholder values for the shell');
    expect($commands['env:sync']->getDescription())
        ->toBe('Synchronize stored AppInstance configuration to the workload environment file.');
});

it('keeps the exact approved arguments options and defaults', function (): void {
    $expected = [
        'activity:list' => [[], ['limit' => '25', 'request-id' => null, 'json' => false]],
        'activity:show' => [['activity'], ['json' => false]],
        'app:list' => [[], ['json' => false]],
        'app:create' => [
            ['slug', 'repository'],
            ['name' => null, 'default-branch' => null, 'root' => 'public', 'json' => false],
        ],
        'app:process-definition' => [
            ['app'],
            ['id' => null, 'file' => null, 'remove' => false, 'json' => false],
        ],
        'app:process-definitions' => [['app'], ['json' => false]],
        'app:destroy' => [['app'], ['json' => false]],
        'app:schedule-definition' => [
            ['app'],
            ['id' => null, 'file' => null, 'remove' => false, 'json' => false],
        ],
        'app:schedule-definitions' => [['app'], ['json' => false]],
        'app:show' => [['app'], ['json' => false]],
        'cluster:list' => [[], ['json' => false]],
        'cluster:create' => [['name'], ['tld' => null, 'json' => false]],
        'cluster:node:add' => [['cluster', 'node'], ['json' => false]],
        'cluster:node:remove' => [['cluster', 'node'], ['force' => false, 'json' => false]],
        'cluster:destroy' => [['cluster'], ['force' => false, 'json' => false]],
        'cluster:router:unset' => [['cluster'], ['force' => false, 'json' => false]],
        'cluster:router:set' => [['cluster', 'node'], ['json' => false]],
        'cluster:show' => [['cluster'], ['json' => false]],
        'cluster:update' => [
            ['cluster'],
            ['name' => null, 'tld' => null, 'state' => null, 'json' => false],
        ],
        'database:add' => [
            ['slug'],
            [
                'driver' => null,
                'node' => null,
                'host' => null,
                'port' => null,
                'database' => null,
                'path' => null,
                'username' => null,
                'password' => null,
                'json' => false,
            ],
        ],
        'database:attach' => [
            ['slug'],
            ['instance' => null, 'prefix' => null, 'json' => false],
        ],
        'database:detach' => [
            ['slug'],
            ['instance' => null, 'prefix' => null, 'force' => false, 'json' => false],
        ],
        'database:list' => [[], ['json' => false]],
        'database:remove' => [['slug'], ['force' => false, 'json' => false]],
        'database:show' => [['slug'], ['json' => false]],
        'database:update' => [
            ['slug'],
            [
                'driver' => null,
                'node' => null,
                'host' => null,
                'port' => null,
                'database' => null,
                'path' => null,
                'username' => null,
                'password' => null,
                'json' => false,
            ],
        ],
        'dns:resolve' => [['tld', 'target'], ['reset' => false, 'json' => false]],
        'doctor' => [[], ['node' => null, 'family' => [], 'json' => false]],
        'env:import' => [[], ['instance' => null, 'replace' => false, 'json' => false]],
        'env:sync' => [[], ['instance' => null, 'json' => false]],
        'env:update' => [[], ['instance' => null, 'key' => null, 'value' => null, 'json' => false]],
        'firewall:allow' => [
            ['name'],
            ['node' => null, 'from' => null, 'protocol' => null, 'port' => null, 'json' => false],
        ],
        'firewall:deny' => [
            ['name'],
            ['node' => null, 'from' => null, 'protocol' => null, 'port' => null, 'json' => false],
        ],
        'firewall:list' => [[], ['node' => null, 'json' => false]],
        'firewall:remove' => [['name'], ['node' => null, 'json' => false]],
        'gateway:add' => [['gateway'], ['name' => 'default', 'ca' => null, 'use' => false, 'json' => false]],
        'gateway:remove' => [['name'], ['force' => false, 'json' => false]],
        'gateway:status' => [[], ['json' => false]],
        'gateway:trust' => [[], ['accept-ca-change' => false, 'json' => false]],
        'gateway:use' => [['name'], ['json' => false]],
        'herdr:observe' => [
            ['session'],
            [
                'node' => null,
                'pane' => null,
                'terminal' => null,
                'cols' => null,
                'rows' => null,
                'json' => false,
            ],
        ],
        'herdr:session:create' => [
            ['session'],
            ['node' => null, 'user' => null, 'publish-observer' => false, 'json' => false],
        ],
        'herdr:session:list' => [[], ['node' => null, 'json' => false]],
        'herdr:session:destroy' => [
            ['session'],
            ['node' => null, 'accept-termination' => false, 'json' => false],
        ],
        'herdr:session:restart' => [
            ['session'],
            ['node' => null, 'handoff' => false, 'json' => false],
        ],
        'herdr:session:show' => [['session'], ['node' => null, 'json' => false]],
        'instance:clone' => [
            ['candidate', 'node', 'name'],
            ['preview-name' => null, 'branch' => null, 'sqlite-source-path' => null, 'json' => false],
        ],
        'instance:create' => [
            ['app', 'node', 'name'],
            [
                'root' => null,
                'hostname' => null,
                'branch' => null,
                'recover-source-profile' => false,
                'json' => false,
            ],
        ],
        'instance:deploy' => [['instance'], ['json' => false]],
        'instance:deployment-config' => [['instance'], ['file' => null, 'json' => false]],
        'instance:destroy' => [['instance'], ['force' => false, 'json' => false]],
        'instance:list' => [[], ['json' => false]],
        'instance:prepare-deployment' => [
            ['instance'],
            ['sqlite-source-path' => null, 'json' => false],
        ],
        'instance:register' => [
            [],
            [
                'path' => null,
                'include-worktrees' => false,
                'app' => null,
                'app-name' => null,
                'app-slug' => null,
                'default-branch' => null,
                'name' => null,
                'root' => null,
                'hostname' => null,
                'json' => false,
            ],
        ],
        'instance:release:list' => [['instance'], ['json' => false]],
        'instance:rollback' => [['instance'], ['release' => null, 'json' => false]],
        'instance:show' => [['instance'], ['json' => false]],
        'metrics:credentials' => [[], ['reset' => false, 'json' => false]],
        'metrics:disable' => [[], ['force' => false, 'purge-data' => false, 'json' => false]],
        'metrics:enable' => [['node'], ['json' => false]],
        'metrics:exporter:disable' => [['node'], ['json' => false]],
        'metrics:exporter:enable' => [['node'], ['json' => false]],
        'metrics:status' => [[], ['json' => false]],
        'node:access:add' => [['consumer', 'serving'], ['json' => false]],
        'node:access:remove' => [['consumer', 'serving'], ['force' => false, 'json' => false]],
        'node:list' => [[], ['json' => false]],
        'node:provision' => [
            ['name', 'host'],
            [
                'ssh-port' => '22',
                'user' => null,
                'orbit-user' => null,
                'platform' => 'linux',
                'architecture' => null,
                'tld' => null,
                'role' => [],
                'host-key-fingerprint' => null,
                'cluster' => null,
                'wireguard-ip' => null,
                'wireguard-address' => null,
                'lan-ip' => null,
                'wireguard-endpoint' => null,
                'dns-server' => null,
                'setting' => [],
                'json' => false,
            ],
        ],
        'node:remove' => [['node'], ['force' => false, 'offline' => false, 'json' => false]],
        'node:settings' => [['node'], ['setting' => [], 'json' => false]],
        'node:role:add' => [['node', 'role'], ['converge' => false, 'json' => false]],
        'node:role:list' => [['node'], ['json' => false]],
        'node:role:remove' => [
            ['node', 'role'],
            ['force' => false, 'purge-data' => false, 'offline' => false, 'json' => false],
        ],
        'node:show' => [['node'], ['json' => false]],
        'process:create' => [
            ['name'],
            [
                'instance' => null,
                'node' => null,
                'runtime' => 'systemd',
                'command' => [],
                'image' => null,
                'working-directory' => null,
                'environment' => [],
                'port' => [],
                'volume' => [],
                'restart' => 'never',
                'start' => false,
                'json' => false,
            ],
        ],
        'process:list' => [[], ['instance' => null, 'node' => null, 'json' => false]],
        'process:logs' => [['process'], ['lines' => '100', 'json' => false]],
        'process:destroy' => [['process'], ['json' => false]],
        'process:restart' => [['process'], ['json' => false]],
        'process:start' => [['process'], ['json' => false]],
        'process:stop' => [['process'], ['json' => false]],
        'route:list' => [[], ['json' => false]],
        'route:create' => [
            ['app', 'hostname'],
            [
                'publication' => 'private',
                'target' => null,
                'node' => null,
                'cluster' => null,
                'json' => false,
            ],
        ],
        'route:destroy' => [['route'], ['json' => false]],
        'route:show' => [['route'], ['json' => false]],
        'route:target:unset' => [['route'], ['json' => false]],
        'route:target:set' => [['route', 'target'], ['json' => false]],
        'route:update' => [['route'], ['hostname' => null, 'publication' => null, 'json' => false]],
        'schedule:enable' => [['schedule'], ['json' => false]],
        'schedule:create' => [[
            'name',
        ], [
            'node' => null,
            'instance' => null,
            'calendar' => null,
            'command' => null,
            'timeout' => '3600',
            'no-start' => false,
            'json' => false,
        ]],
        'schedule:list' => [[], ['json' => false]],
        'schedule:logs' => [['schedule'], ['lines' => '100', 'json' => false]],
        'schedule:destroy' => [['schedule'], ['json' => false]],
        'schedule:run' => [['schedule'], ['json' => false]],
        'schedule:show' => [['schedule'], ['json' => false]],
        'tool:install' => [
            ['package'],
            ['node' => null, 'manager' => null, 'constraint' => null, 'json' => false],
        ],
        'tool:list' => [[], ['node' => null, 'json' => false]],
        'tool:manager:list' => [[], ['node' => null, 'json' => false]],
        'tool:remove' => [['tool'], ['json' => false]],
        'tool:show' => [['tool'], ['json' => false]],
        'tool:update' => [['tool'], ['json' => false]],
        'workspace:list' => [[], ['json' => false]],
        'workspace:new' => [
            ['instance', 'name'],
            ['branch' => null, 'path' => null, 'php' => null, 'json' => false],
        ],
        'workspace:php' => [['workspace', 'version'], ['json' => false]],
        'workspace:remove' => [['workspace'], ['json' => false]],
        'workspace:show' => [['workspace'], ['json' => false]],
    ];
    $globalOptions = [
        'help',
        'silent',
        'quiet',
        'verbose',
        'version',
        'ansi',
        'no-ansi',
        'no-interaction',
        'env',
    ];
    $commands = app(Kernel::class)->all();

    foreach ($expected as $name => [$arguments, $options]) {
        $definition = $commands[$name]->getDefinition();
        $actualOptions = collect($definition->getOptions())
            ->except($globalOptions)
            ->map(static fn ($option): mixed => $option->getDefault())
            ->all();

        expect(array_keys($definition->getArguments()))->toBe($arguments);
        $optionalArguments = match ($name) {
            'dns:resolve' => ['target'],
            'node:provision' => ['host'],
            'tool:install' => ['package'],
            'metrics:enable' => ['node'],
            default => [],
        };
        expect(collect($definition->getArguments())
            ->reject(static fn ($argument, string $argumentName): bool => in_array(
                $argumentName,
                $optionalArguments,
                strict: true,
            ))
            ->every(static fn ($argument): bool => $argument->isRequired()))
            ->toBeTrue();
        expect(
            collect($definition->getArguments())
                ->only($optionalArguments)
                ->every(static fn ($argument): bool => ! $argument->isRequired()),
        )
            ->toBeTrue();
        expect($actualOptions)->toBe($options);
    }
});

it('routes every Orbit product command through the shared output boundary', function (): void {
    $commands = collect(app(Kernel::class)->all())
        ->reject(static fn (Command $command): bool => $command->isHidden());

    expect($commands->every(
        static fn (Command $command): bool => $command instanceof GatewayCommand,
    ))->toBeTrue();
});

it('does not let command classes bypass the shared failure renderer', function (): void {
    $commandFiles = app(Filesystem::class)->allFiles(app_path('Commands'));

    foreach ($commandFiles as $commandFile) {
        $contents = file_get_contents($commandFile->getPathname());

        expect($contents)
            ->toBeString()
            ->not->toMatch('/->\s*error\s*\(/');
    }
});

it('does not execute local or remote shell processes from command classes', function (): void {
    $commandFiles = app(Filesystem::class)->allFiles(app_path('Commands'));

    foreach ($commandFiles as $commandFile) {
        $contents = file_get_contents($commandFile->getPathname());

        expect($contents)
            ->toBeString()
            ->not->toMatch(
                '/Symfony\\\\Component\\\\Process|Facades\\\\Process|shell_exec|proc_open|passthru|\\bsystem\s*\(/',
            );
    }
});

it('renders one exact json failure envelope for every Orbit product command', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-cli-command-surface-'.Str::uuid();
    config()->set('orbit.home', $orbitHome);
    $mock = MockClient::global();
    $profileMissing = [
        'code' => 'gateway.profile_missing',
        'message' => 'No active gateway profile.',
    ];
    $cases = [
        'activity:list' => [[], ...$profileMissing],
        'activity:show' => [['activity' => '1'], ...$profileMissing],
        'app:list' => [[], ...$profileMissing],
        'app:create' => [['slug' => 'app', 'repository' => 'https://example.test/app.git'], ...$profileMissing],
        'app:process-definition' => [[
            'app' => '1',
            '--id' => '0199cc62-68f3-75b8-9f11-36fe92ac1f36',
        ], ...$profileMissing],
        'app:process-definitions' => [['app' => '1'], ...$profileMissing],
        'app:destroy' => [['app' => '1'], ...$profileMissing],
        'app:schedule-definition' => [[
            'app' => '1',
            '--id' => '0199cc62-68f3-75b8-9f11-36fe92ac1f36',
        ], ...$profileMissing],
        'app:schedule-definitions' => [['app' => '1'], ...$profileMissing],
        'app:show' => [['app' => '1'], ...$profileMissing],
        'cluster:list' => [[], ...$profileMissing],
        'cluster:create' => [['name' => 'development'], ...$profileMissing],
        'cluster:node:add' => [['cluster' => '1', 'node' => '2'], ...$profileMissing],
        'cluster:node:remove' => [
            ['cluster' => '1', 'node' => '2', '--force' => true],
            ...$profileMissing,
        ],
        'cluster:destroy' => [['cluster' => '1', '--force' => true], ...$profileMissing],
        'cluster:router:unset' => [['cluster' => '1', '--force' => true], ...$profileMissing],
        'cluster:router:set' => [['cluster' => '1', 'node' => '2'], ...$profileMissing],
        'cluster:show' => [['cluster' => '1'], ...$profileMissing],
        'cluster:update' => [['cluster' => '1', '--state' => 'inactive'], ...$profileMissing],
        'database:add' => [[
            'slug' => 'app',
            '--driver' => 'mysql',
            '--host' => 'db.example.test',
            '--database' => 'app',
            '--username' => 'app',
            '--password' => 'secret',
        ], ...$profileMissing],
        'database:attach' => [[
            'slug' => 'app',
            '--instance' => '12',
        ], ...$profileMissing],
        'database:detach' => [[
            'slug' => 'app',
            '--instance' => '12',
            '--force' => true,
        ], ...$profileMissing],
        'database:list' => [[], ...$profileMissing],
        'database:remove' => [['slug' => 'app', '--force' => true], ...$profileMissing],
        'database:show' => [['slug' => 'app'], ...$profileMissing],
        'database:update' => [['slug' => 'app', '--host' => 'db.example.test'], ...$profileMissing],
        'dns:resolve' => [
            ['tld' => '.validation-secret', 'target' => '127.0.0.1'],
            'code' => 'dns.tld_invalid',
            'message' => 'Development TLD must be one lowercase DNS label without a leading dot.',
        ],
        'doctor' => [[], ...$profileMissing],
        'env:import' => [['--instance' => 'app.com'], ...$profileMissing],
        'env:sync' => [['--instance' => 'app.com'], ...$profileMissing],
        'env:update' => [
            ['--instance' => 'app.com', '--key' => 'PRIVATE_VALUE', '--value' => 'validation-secret'],
            ...$profileMissing,
        ],
        'firewall:allow' => [['name' => 'web', '--node' => '1', '--port' => '443'], ...$profileMissing],
        'firewall:deny' => [['name' => 'web', '--node' => '1', '--port' => '443'], ...$profileMissing],
        'firewall:list' => [['--node' => '1'], ...$profileMissing],
        'firewall:remove' => [['name' => 'web', '--node' => '1'], ...$profileMissing],
        'gateway:add' => [
            ['gateway' => 'http://validation-secret'],
            'code' => 'gateway.profile_invalid',
            'message' => 'Gateway URL must use HTTPS.',
        ],
        'gateway:remove' => [
            ['name' => 'validation-secret'],
            'code' => 'gateway.profile_not_found',
            'message' => 'Gateway profile does not exist.',
        ],
        'gateway:status' => [[], ...$profileMissing],
        'gateway:trust' => [[], ...$profileMissing],
        'gateway:use' => [
            ['name' => 'validation-secret'],
            'code' => 'gateway.profile_not_found',
            'message' => 'Gateway profile does not exist.',
        ],
        'herdr:observe' => [
            [
                'session' => 'commander-tasks',
                '--node' => '1',
                '--pane' => 'w1:p1',
                '--terminal' => 'term-abc',
                '--cols' => '120',
                '--rows' => '40',
            ],
            ...$profileMissing,
        ],
        'herdr:session:create' => [
            ['session' => 'commander-tasks', '--node' => '1', '--user' => 'nckrtl'],
            ...$profileMissing,
        ],
        'herdr:session:list' => [['--node' => '1'], ...$profileMissing],
        'herdr:session:destroy' => [['session' => 'commander-tasks', '--node' => '1'], ...$profileMissing],
        'herdr:session:restart' => [['session' => 'commander-tasks', '--node' => '1'], ...$profileMissing],
        'herdr:session:show' => [['session' => 'commander-tasks', '--node' => '1'], ...$profileMissing],
        'instance:clone' => [
            ['candidate' => '1', 'node' => '2', 'name' => 'web', '--preview-name' => 'web'],
            ...$profileMissing,
        ],
        'instance:create' => [['app' => '1', 'node' => '1', 'name' => 'web'], ...$profileMissing],
        'instance:deploy' => [['instance' => '1'], ...$profileMissing],
        'instance:deployment-config' => [['instance' => '1'], ...$profileMissing],
        'instance:destroy' => [['instance' => '1'], ...$profileMissing],
        'instance:list' => [[], ...$profileMissing],
        'instance:prepare-deployment' => [['instance' => '1'], ...$profileMissing],
        'instance:register' => [
            ['--app' => '1', '--no-interaction' => true, '--path' => '/tmp/orbit-command-surface-not-git'],
            'code' => 'instance.source_invalid',
            'message' => 'The current path is not a supported Git checkout or worktree.',
        ],
        'instance:release:list' => [['instance' => '1'], ...$profileMissing],
        'instance:rollback' => [['instance' => '1', '--release' => 'release-a'], ...$profileMissing],
        'instance:show' => [['instance' => '1'], ...$profileMissing],
        'metrics:credentials' => [[], ...$profileMissing],
        'metrics:disable' => [['--force' => true], ...$profileMissing],
        'metrics:enable' => [['node' => '1'], ...$profileMissing],
        'metrics:exporter:disable' => [['node' => '1'], ...$profileMissing],
        'metrics:exporter:enable' => [['node' => '1'], ...$profileMissing],
        'metrics:status' => [[], ...$profileMissing],
        'node:access:add' => [['consumer' => '2', 'serving' => '3'], ...$profileMissing],
        'node:access:remove' => [['consumer' => '2', 'serving' => '3', '--force' => true], ...$profileMissing],
        'node:list' => [[], ...$profileMissing],
        'node:provision' => [['name' => 'node', 'host' => 'node.test'], ...$profileMissing],
        'node:remove' => [['node' => '1', '--force' => true], ...$profileMissing],
        'node:role:add' => [['node' => '7', 'role' => 'app-dev'], ...$profileMissing],
        'node:role:list' => [['node' => '7'], ...$profileMissing],
        'node:role:remove' => [['node' => '7', 'role' => 'app-dev', '--force' => true], ...$profileMissing],
        'node:settings' => [['node' => '1', '--setting' => ['apps.path:/srv/orbit/apps']], ...$profileMissing],
        'node:show' => [['node' => '1'], ...$profileMissing],
        'process:create' => [
            ['name' => 'worker', '--instance' => '1', '--command' => ['/usr/bin/php']],
            ...$profileMissing,
        ],
        'process:list' => [['--instance' => '1'], ...$profileMissing],
        'process:logs' => [['process' => '1'], ...$profileMissing],
        'process:destroy' => [['process' => '1'], ...$profileMissing],
        'process:restart' => [['process' => '1'], ...$profileMissing],
        'process:start' => [['process' => '1'], ...$profileMissing],
        'process:stop' => [['process' => '1'], ...$profileMissing],
        'route:list' => [[], ...$profileMissing],
        'route:create' => [['app' => '1', 'hostname' => 'app.test', '--node' => '1'], ...$profileMissing],
        'route:destroy' => [['route' => '1'], ...$profileMissing],
        'route:show' => [['route' => '1'], ...$profileMissing],
        'route:target:unset' => [['route' => '1'], ...$profileMissing],
        'route:target:set' => [['route' => '1', 'target' => '2'], ...$profileMissing],
        'route:update' => [['route' => '1', '--publication' => 'private'], ...$profileMissing],
        'schedule:enable' => [[
            'schedule' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], ...$profileMissing],
        'schedule:create' => [[
            'name' => 'daily-report',
            '--node' => '1',
            '--calendar' => 'daily',
            '--command' => 'php artisan report:send',
        ], ...$profileMissing],
        'schedule:list' => [[], ...$profileMissing],
        'schedule:logs' => [[
            'schedule' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], ...$profileMissing],
        'schedule:destroy' => [[
            'schedule' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], ...$profileMissing],
        'schedule:run' => [[
            'schedule' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], ...$profileMissing],
        'schedule:show' => [[
            'schedule' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], ...$profileMissing],
        'tool:install' => [
            ['package' => 'curl', '--node' => '1', '--manager' => 'apt'],
            ...$profileMissing,
        ],
        'tool:list' => [['--node' => '1'], ...$profileMissing],
        'tool:manager:list' => [['--node' => '1'], ...$profileMissing],
        'tool:remove' => [['tool' => '1'], ...$profileMissing],
        'tool:show' => [['tool' => '1'], ...$profileMissing],
        'tool:update' => [['tool' => '1'], ...$profileMissing],
        'workspace:list' => [[], ...$profileMissing],
        'workspace:new' => [['instance' => '1', 'name' => 'work'], ...$profileMissing],
        'workspace:php' => [['workspace' => '1', 'version' => '8.5'], ...$profileMissing],
        'workspace:remove' => [['workspace' => '1'], ...$profileMissing],
        'workspace:show' => [['workspace' => '1'], ...$profileMissing],
    ];
    $visibleCommandNames = collect(app(Kernel::class)->all())
        ->reject(static fn (Command $command): bool => $command->isHidden())
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect(array_keys($cases))->toEqualCanonicalizing($visibleCommandNames);

    foreach ($cases as $command => $case) {
        $arguments = $case[0];
        $code = $case['code'];
        $message = $case['message'];
        $expectedPayload = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => null,
            ],
        ];

        $exitCode = Artisan::call($command, [...$arguments, '--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(Command::FAILURE);
        expect($output)
            ->toBe(json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->not->toContain('validation-secret');
        expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe($expectedPayload);
        expect($mock->getLastPendingRequest())->toBeNull();
    }

    MockClient::destroyGlobal();
    app(Filesystem::class)->deleteDirectory($orbitHome);
});
