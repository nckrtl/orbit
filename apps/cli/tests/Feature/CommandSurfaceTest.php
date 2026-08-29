<?php

declare(strict_types=1);

use App\Commands\GatewayCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Command\Command;

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
        'app:list',
        'app:new',
        'app:remove',
        'app:show',
        'dns:resolve',
        'doctor',
        'firewall:allow',
        'firewall:deny',
        'firewall:list',
        'firewall:remove',
        'gateway:add',
        'gateway:status',
        'gateway:trust',
        'gateway:use',
        'instance:list',
        'instance:new',
        'instance:php',
        'instance:remove',
        'instance:show',
        'node:access:add',
        'node:access:remove',
        'node:list',
        'node:provision',
        'node:remove',
        'node:role:add',
        'node:role:list',
        'node:role:remove',
        'node:show',
        'process:add',
        'process:list',
        'process:logs',
        'process:remove',
        'process:restart',
        'process:start',
        'process:stop',
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

it('does not register hidden Orbit product commands', function (): void {
    $orbitCommands = collect(app(Kernel::class)->all())
        ->filter(static fn (Command $command): bool => str_starts_with($command::class, 'App\\Commands\\'));

    expect($orbitCommands)->toHaveCount(48);
    expect($orbitCommands->every(
        static fn (Command $command): bool => ! $command->isHidden(),
    ))->toBeTrue();
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

/** @mago-expect lint:halstead The explicit matrix protects the approved minimal command vocabulary. */
it('keeps the exact approved arguments options and defaults', function (): void {
    $expected = [
        'activity:list' => [[], ['limit' => '25', 'request-id' => null, 'json' => false]],
        'activity:show' => [['activity'], ['json' => false]],
        'app:list' => [[], ['json' => false]],
        'app:new' => [['slug', 'repository'], ['name' => null, 'json' => false]],
        'app:remove' => [['app'], ['json' => false]],
        'app:show' => [['app'], ['json' => false]],
        'dns:resolve' => [['tld', 'target'], ['reset' => false, 'json' => false]],
        'doctor' => [[], ['node' => null, 'family' => [], 'json' => false]],
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
        'gateway:status' => [[], ['json' => false]],
        'gateway:trust' => [[], ['accept-ca-change' => false, 'json' => false]],
        'gateway:use' => [['name'], ['json' => false]],
        'instance:list' => [[], ['json' => false]],
        'instance:new' => [
            ['app', 'node', 'name'],
            [
                'environment' => null,
                'hostname' => null,
                'document-root' => 'public',
                'php' => '8.5',
                'json' => false,
            ],
        ],
        'instance:php' => [['instance', 'version'], ['json' => false]],
        'instance:remove' => [['instance'], ['json' => false]],
        'instance:show' => [['instance'], ['json' => false]],
        'node:access:add' => [['consumer', 'serving'], ['json' => false]],
        'node:access:remove' => [['consumer', 'serving'], ['force' => false, 'json' => false]],
        'node:list' => [[], ['json' => false]],
        'node:provision' => [
            ['name', 'host'],
            [
                'ssh-port' => '22',
                'user' => 'root',
                'orbit-user' => null,
                'platform' => 'linux',
                'architecture' => null,
                'tld' => null,
                'role' => [],
                'host-key-fingerprint' => null,
                'wireguard-address' => null,
                'wireguard-endpoint' => null,
                'dns-server' => null,
                'json' => false,
            ],
        ],
        'node:remove' => [['node'], ['force' => false, 'json' => false]],
        'node:role:add' => [['node', 'role'], ['converge' => false, 'json' => false]],
        'node:role:list' => [['node'], ['json' => false]],
        'node:role:remove' => [['node', 'role'], ['force' => false, 'purge-data' => false, 'json' => false]],
        'node:show' => [['node'], ['json' => false]],
        'process:add' => [
            ['name'],
            [
                'instance' => null,
                'workspace' => null,
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
        'process:list' => [[], ['instance' => null, 'workspace' => null, 'json' => false]],
        'process:logs' => [['process'], ['lines' => '100', 'json' => false]],
        'process:remove' => [['process'], ['json' => false]],
        'process:restart' => [['process'], ['json' => false]],
        'process:start' => [['process'], ['json' => false]],
        'process:stop' => [['process'], ['json' => false]],
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

/** @mago-expect lint:halstead The exhaustive matrix locks every public command to one JSON failure contract. */
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
        'app:new' => [['slug' => 'app', 'repository' => 'https://example.test/app.git'], ...$profileMissing],
        'app:remove' => [['app' => '1'], ...$profileMissing],
        'app:show' => [['app' => '1'], ...$profileMissing],
        'dns:resolve' => [
            ['tld' => '.validation-secret', 'target' => '127.0.0.1'],
            'code' => 'dns.tld_invalid',
            'message' => 'Development TLD must be one lowercase DNS label without a leading dot.',
        ],
        'doctor' => [[], ...$profileMissing],
        'firewall:allow' => [['name' => 'web', '--node' => '1', '--port' => '443'], ...$profileMissing],
        'firewall:deny' => [['name' => 'web', '--node' => '1', '--port' => '443'], ...$profileMissing],
        'firewall:list' => [['--node' => '1'], ...$profileMissing],
        'firewall:remove' => [['name' => 'web', '--node' => '1'], ...$profileMissing],
        'gateway:add' => [
            ['gateway' => 'http://validation-secret'],
            'code' => 'gateway.profile_invalid',
            'message' => 'Gateway URL must use HTTPS.',
        ],
        'gateway:status' => [[], ...$profileMissing],
        'gateway:trust' => [[], ...$profileMissing],
        'gateway:use' => [
            ['name' => 'validation-secret'],
            'code' => 'gateway.profile_not_found',
            'message' => 'Gateway profile does not exist.',
        ],
        'instance:list' => [[], ...$profileMissing],
        'instance:new' => [['app' => '1', 'node' => '1', 'name' => 'web'], ...$profileMissing],
        'instance:php' => [['instance' => '1', 'version' => '8.5'], ...$profileMissing],
        'instance:remove' => [['instance' => '1'], ...$profileMissing],
        'instance:show' => [['instance' => '1'], ...$profileMissing],
        'node:access:add' => [['consumer' => '2', 'serving' => '3'], ...$profileMissing],
        'node:access:remove' => [['consumer' => '2', 'serving' => '3', '--force' => true], ...$profileMissing],
        'node:list' => [[], ...$profileMissing],
        'node:provision' => [['name' => 'node', 'host' => 'node.test'], ...$profileMissing],
        'node:remove' => [['node' => '1', '--force' => true], ...$profileMissing],
        'node:role:add' => [['node' => '7', 'role' => 'app-dev'], ...$profileMissing],
        'node:role:list' => [['node' => '7'], ...$profileMissing],
        'node:role:remove' => [['node' => '7', 'role' => 'app-dev', '--force' => true], ...$profileMissing],
        'node:show' => [['node' => '1'], ...$profileMissing],
        'process:add' => [
            ['name' => 'worker', '--instance' => '1', '--command' => ['/usr/bin/php']],
            ...$profileMissing,
        ],
        'process:list' => [['--instance' => '1'], ...$profileMissing],
        'process:logs' => [['process' => '1'], ...$profileMissing],
        'process:remove' => [['process' => '1'], ...$profileMissing],
        'process:restart' => [['process' => '1'], ...$profileMissing],
        'process:start' => [['process' => '1'], ...$profileMissing],
        'process:stop' => [['process' => '1'], ...$profileMissing],
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
