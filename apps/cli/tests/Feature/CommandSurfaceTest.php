<?php

declare(strict_types=1);

use App\Commands\GatewayCommand;
use App\Commands\Schedules\ListSchedulesCommand;
use App\Commands\Schedules\RunScheduleCommand;
use App\Services\Extensions\LocalExtensionState;
use App\Support\CommandVocabulary;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @return array{extensions: string|null, config: string|null}
 */
function commandSurfaceHomeSnapshot(string $home): array
{
    $extensions = $home.'/extensions.json';
    $config = $home.'/config.json';

    return [
        'extensions' => is_file($extensions) ? file_get_contents($extensions) : null,
        'config' => is_file($config) ? file_get_contents($config) : null,
    ];
}

function replaceCommandSurfaceHome(string $home): void
{
    config()->set('orbit.home', $home);
    app()->forgetInstance(LocalExtensionState::class);
}

beforeEach(function (): void {
    $this->callerOrbitHome = rtrim((string) config('orbit.home'), '/');
    $this->callerSnapshot = commandSurfaceHomeSnapshot($this->callerOrbitHome);
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-command-surface-'.Str::uuid();
    replaceCommandSurfaceHome($this->orbitHome);
});

afterEach(function (): void {
    if ($this->orbitHome !== $this->callerOrbitHome) {
        new Filesystem()->deleteDirectory($this->orbitHome);
    }

    expect(commandSurfaceHomeSnapshot($this->callerOrbitHome))->toBe($this->callerSnapshot);
});

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
        'app:show',
        'app:update',
        'cluster:create',
        'cluster:destroy',
        'cluster:list',
        'cluster:node:add',
        'cluster:node:remove',
        'cluster:router:set',
        'cluster:router:unset',
        'cluster:show',
        'cluster:update',
        'database:create',
        'database:describe',
        'database:destroy',
        'database:list',
        'database:query',
        'database:schema',
        'database:show',
        'database:tables',
        'database:update',
        'database:user:create',
        'database:user:list',
        'dns:resolve',
        'doctor',
        'env:import',
        'env:sync',
        'env:update',
        'extension:disable',
        'extension:enable',
        'extension:list',
        'firewall:allow',
        'firewall:deny',
        'firewall:list',
        'firewall:remove',
        'gateway:add',
        'gateway:remove',
        'gateway:status',
        'gateway:trust',
        'gateway:use',
        'github:app:destroy',
        'github:app:install',
        'github:app:show',
        'instance:clone',
        'instance:create',
        'instance:database:add',
        'instance:database:remove',
        'instance:dependencies:scan',
        'instance:dependencies:update',
        'instance:deploy',
        'instance:deploy-step:create',
        'instance:deploy-step:destroy',
        'instance:deploy-step:list',
        'instance:deploy-step:update',
        'instance:deployment:list',
        'instance:deployment:show',
        'instance:destroy',
        'instance:list',
        'instance:register',
        'instance:release:list',
        'instance:rollback',
        'instance:show',
        'instance:transfer',
        'instance:update',
        'metrics:credentials',
        'metrics:disable',
        'metrics:enable',
        'metrics:exporter:disable',
        'metrics:exporter:enable',
        'metrics:status',
        'node:access:add',
        'node:access:remove',
        'node:add',
        'node:list',
        'node:metrics',
        'node:remove',
        'node:rename',
        'node:role:add',
        'node:role:list',
        'node:role:relocate',
        'node:role:remove',
        'node:settings',
        'node:show',
        'process:create',
        'process:destroy',
        'process:list',
        'process:logs',
        'process:restart',
        'process:show',
        'process:start',
        'process:stop',
        'process:update',
        'profile',
        'realtime:show',
        'realtime:tail',
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
        'schedule:update',
        'tool:install',
        'tool:list',
        'tool:manager:list',
        'tool:remove',
        'tool:show',
        'tool:update',
        'top',
    ]);
});

describe('command vocabulary', function (): void {
    it('rejects a command whose last segment is outside the vocabulary and family-specific actions', function (): void {
        expect(CommandVocabulary::allowsCommand('app:create'))->toBeTrue();
        expect(CommandVocabulary::allowsCommand('instance:clone'))->toBeTrue();
        expect(CommandVocabulary::allowsCommand('instance:transfer'))->toBeTrue();
        expect(CommandVocabulary::allowsCommand('node:settings'))->toBeTrue();
        expect(CommandVocabulary::allowsCommand('doctor'))->toBeTrue();
        expect(CommandVocabulary::allowsCommand('profile'))->toBeTrue();
        expect(CommandVocabulary::allowsCommand('workspace:new'))->toBeFalse();
        expect(CommandVocabulary::allowsCommand('workspace:php'))->toBeFalse();
        expect(CommandVocabulary::allowsCommand('instance:deployment-config'))->toBeFalse();
        expect(CommandVocabulary::allowsCommand('instance:prepare-deployment'))->toBeFalse();
        expect(CommandVocabulary::allowsCommand('app:new'))->toBeFalse();
        expect(CommandVocabulary::allowsCommand('app:frobnicate'))->toBeFalse();
        expect(CommandVocabulary::allowsCommand('cluster:attach'))->toBeFalse();
        expect(CommandVocabulary::allowsCommand('internal:database-local'))->toBeTrue();
        expect(CommandVocabulary::allowsCommand('internal:database-query-local'))->toBeFalse();
        expect(CommandVocabulary::allowsCommand('internal:frobnicate'))->toBeFalse();
    });

    it('accepts every registered Orbit command last segment', function (): void {
        foreach (orbitProductCommandNames() as $name) {
            expect(CommandVocabulary::allowsCommand($name))->toBeTrue();
        }
    });

    it('rejects a named Gateway API route that matches a CLI family without a command', function (): void {
        $commands = ['app:create', 'metrics:enable', 'metrics:status'];

        expect(CommandVocabulary::routeRequiresMatchingCommand('metrics:list', $commands))->toBeTrue();
        expect($commands)->not->toContain('metrics:list');
        expect(CommandVocabulary::routeRequiresMatchingCommand('schedule:complete', $commands))->toBeFalse();
        expect(CommandVocabulary::routeRequiresMatchingCommand('instance:dependencies:show', ['instance:dependencies:scan']))->toBeFalse();
        expect(CommandVocabulary::routeRequiresMatchingCommand('instance:dependencies:update', ['instance:dependencies:update']))->toBeTrue();
        expect(CommandVocabulary::routeRequiresMatchingCommand('instance:dependencies:update', ['instance:dependencies:scan']))->toBeTrue();
        expect(CommandVocabulary::routeRequiresMatchingCommand('instance:dependencies:scan', ['instance:dependencies:scan']))->toBeTrue();
        expect(CommandVocabulary::routeRequiresMatchingCommand('instance:deployment-config:show', $commands))->toBeFalse();
    });

    it('requires a matching command for every named Gateway API route that matches a CLI family', function (): void {
        $commands = orbitProductCommandNames();
        $contents = file_get_contents(base_path('../gateway/routes/api.php'));

        expect($contents)->toBeString();

        $routes = CommandVocabulary::namedRoutesFromApiFile($contents);

        expect($routes)->not->toBeEmpty();

        foreach ($routes as $route) {
            if (CommandVocabulary::routeRequiresMatchingCommand($route, $commands)) {
                expect($commands)->toContain($route);
            }
        }
    });

    it('documents every vocabulary verb family-specific action and noun-ending command', function (): void {
        $page = file_get_contents(base_path('../../docs/reference/cli-command-vocabulary.md'));

        expect($page)->toBeString();

        foreach (CommandVocabulary::VERBS as $verb) {
            expect($page)->toContain('`'.$verb.'`');
        }

        foreach (CommandVocabulary::FAMILY_ACTIONS as $actions) {
            foreach ($actions as $action) {
                expect($page)->toContain('`'.$action.'`');
            }
        }

        foreach (CommandVocabulary::NOUN_ENDING_COMMANDS as $command) {
            expect($page)->toContain('`'.$command.'`');
        }
    });
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

it('rejects the retired internal database query local command name', function (): void {
    $output = new BufferedOutput;
    $status = app(Kernel::class)->handle(new StringInput('internal:database-query-local'), $output);

    expect(collect(app(Kernel::class)->all())->keys()->all())
        ->not->toContain('internal:database-query-local')
        ->and($status)
        ->toBe(Command::FAILURE)
        ->and(trim($output->fetch()))
        ->toContain('Command "internal:database-query-local" is not defined.');
});

it('only hides Orbit commands that belong to disabled extensions', function (): void {
    $orbitCommands = collect(app(Kernel::class)->all())
        ->filter(static fn (Command $command): bool => str_starts_with($command::class, 'App\\Commands\\'));

    expect($orbitCommands)->toHaveCount(129);
    expect($orbitCommands
        ->filter(static fn (Command $command): bool => $command->isHidden())
        ->keys()
        ->sort()
        ->values()
        ->all())->toBe([
            'herdr:observe',
            'herdr:session:adopt',
            'herdr:session:create',
            'herdr:session:destroy',
            'herdr:session:list',
            'herdr:session:restart',
            'herdr:session:show',
            'internal:database-local',
        ]);
});

it('keeps command-surface visibility independent of caller Herdr settings', function (bool $herdrEnabled): void {
    $filesystem = new Filesystem;
    $callerHome = sys_get_temp_dir().'/orbit-cli-command-surface-caller-'.Str::uuid();
    mkdir($callerHome, 0700, true);
    $sentinel = '{"sentinel":"orb-347-caller-config"}'.PHP_EOL;
    file_put_contents($callerHome.'/config.json', $sentinel);
    chmod($callerHome.'/config.json', 0600);

    replaceCommandSurfaceHome($callerHome);

    if ($herdrEnabled) {
        app(LocalExtensionState::class)->enable('herdr');
    }

    $callerSnapshot = commandSurfaceHomeSnapshot($callerHome);

    replaceCommandSurfaceHome($this->orbitHome);

    expect(app(LocalExtensionState::class)->enabled('herdr'))->toBeFalse();
    expect(collect(app(Kernel::class)->all())
        ->filter(static fn (Command $command): bool => str_starts_with($command::class, 'App\\Commands\\Herdr\\'))
        ->every(static fn (Command $command): bool => $command->isHidden()))
        ->toBeTrue();
    expect(collect(app(Kernel::class)->all())
        ->reject(static fn (Command $command): bool => $command->isHidden())
        ->keys()
        ->all())
        ->not->toContain('herdr:session:create')
        ->not->toContain('herdr:observe');
    expect(commandSurfaceHomeSnapshot($callerHome))->toBe($callerSnapshot)
        ->and(commandSurfaceHomeSnapshot($this->callerOrbitHome))->toBe($this->callerSnapshot);

    $filesystem->deleteDirectory($this->orbitHome);

    expect(is_dir($this->orbitHome))->toBeFalse()
        ->and(is_dir($callerHome))->toBeTrue()
        ->and(commandSurfaceHomeSnapshot($callerHome))->toBe($callerSnapshot);

    $filesystem->deleteDirectory($callerHome);
})->with([
    'Herdr disabled' => [false],
    'Herdr enabled' => [true],
]);

it('removes only owned command-surface fixtures and leaves caller configuration intact', function (): void {
    $filesystem = new Filesystem;
    $callerHome = sys_get_temp_dir().'/orbit-cli-command-surface-caller-'.Str::uuid();
    mkdir($callerHome, 0700, true);
    mkdir($this->orbitHome, 0700, true);
    $sentinel = '{"sentinel":"orb-347-caller-config"}'.PHP_EOL;
    file_put_contents($callerHome.'/config.json', $sentinel);
    chmod($callerHome.'/config.json', 0600);
    file_put_contents($this->orbitHome.'/owned.json', '{"owned":true}'.PHP_EOL);

    $filesystem->deleteDirectory($this->orbitHome);

    expect(is_dir($this->orbitHome))->toBeFalse()
        ->and(is_dir($callerHome))->toBeTrue()
        ->and(file_get_contents($callerHome.'/config.json'))->toBe($sentinel)
        ->and(commandSurfaceHomeSnapshot($this->callerOrbitHome))->toBe($this->callerSnapshot);

    $filesystem->deleteDirectory($callerHome);
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
        'instance:php',
        'instance:deployment-config',
        'instance:prepare-deployment',
    ]);
});

it('does not expose retired Workspace command names', function (): void {
    expect(app(Kernel::class)->all())->not->toHaveKeys([
        'workspace:list',
        'workspace:new',
        'workspace:php',
        'workspace:remove',
        'workspace:show',
    ]);
});

it('rejects retired deployment document and layout commands as unknown', function (string $command): void {
    $output = new BufferedOutput;
    $status = app(Kernel::class)->handle(new StringInput($command), $output);

    expect(collect(app(Kernel::class)->all())->keys()->all())
        ->not->toContain($command)
        ->and($status)
        ->toBe(Command::FAILURE)
        ->and(trim($output->fetch()))
        ->toContain(sprintf('Command "%s" is not defined.', $command));
})->with([
    'instance:deployment-config',
    'instance:prepare-deployment',
]);

it('does not expose replaced definition command names', function (): void {
    expect(app(Kernel::class)->all())->not->toHaveKeys([
        'app:process-definition',
        'app:process-definitions',
        'app:schedule-definition',
        'app:schedule-definitions',
    ]);
});

it('does not expose replaced database connection names', function (): void {
    expect(app(Kernel::class)->all())->not->toHaveKeys([
        'database:add',
        'database:add-user',
        'database:remove',
        'database:attach',
        'database:detach',
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
        'app:destroy' => [['app'], ['yes' => false, 'json' => false]],
        'app:show' => [['app'], ['json' => false]],
        'app:update' => [
            ['app'],
            ['slug' => null, 'repository' => null, 'default-branch' => null, 'root' => null, 'json' => false],
        ],
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
        'database:create' => [
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
        'database:describe' => [['slug', 'table'], ['json' => false]],
        'database:destroy' => [['slug'], ['force' => false, 'json' => false]],
        'database:list' => [[], ['json' => false]],
        'database:query' => [['slug', 'sql'], ['write' => false, 'json' => false]],
        'database:schema' => [['slug'], ['json' => false]],
        'database:show' => [['slug'], ['json' => false]],
        'database:tables' => [['slug'], ['json' => false]],
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
        'database:user:create' => [
            ['slug'],
            [
                'process' => null,
                'database' => null,
                'username' => null,
                'password' => null,
                'json' => false,
            ],
        ],
        'database:user:list' => [['slug'], ['json' => false]],
        'dns:resolve' => [['tld', 'target'], ['reset' => false, 'json' => false]],
        'doctor' => [[], ['node' => null, 'family' => [], 'json' => false]],
        'env:import' => [[], ['instance' => null, 'replace' => false, 'json' => false]],
        'env:sync' => [[], ['instance' => null, 'json' => false]],
        'env:update' => [[], ['instance' => null, 'key' => null, 'value' => null, 'json' => false]],
        'extension:disable' => [['extension'], ['json' => false]],
        'extension:enable' => [['extension'], ['json' => false]],
        'extension:list' => [[], ['json' => false]],
        'firewall:allow' => [
            ['name'],
            ['node' => null, 'from' => null, 'protocol' => null, 'port' => null, 'json' => false],
        ],
        'firewall:deny' => [
            ['name'],
            ['node' => null, 'from' => null, 'protocol' => null, 'port' => null, 'json' => false],
        ],
        'firewall:list' => [[], ['node' => null, 'json' => false]],
        'firewall:remove' => [['name'], ['node' => null, 'yes' => false, 'json' => false]],
        'gateway:add' => [['gateway'], ['name' => 'default', 'ca' => null, 'use' => false, 'json' => false]],
        'gateway:remove' => [['name'], ['force' => false, 'yes' => false, 'json' => false]],
        'gateway:status' => [[], ['json' => false]],
        'gateway:trust' => [[], ['accept-ca-change' => false, 'json' => false]],
        'gateway:use' => [['name'], ['json' => false]],
        'github:app:destroy' => [[], ['yes' => false, 'json' => false]],
        'github:app:install' => [[], ['name' => null, 'owner' => null, 'json' => false]],
        'github:app:show' => [[], ['json' => false]],
        'herdr:observe' => [
            ['session'],
            [
                'node' => null,
                'pane' => null,
                'terminal' => null,
                'cols' => null,
                'rows' => null,
                'origin' => null,
                'json' => false,
            ],
        ],
        'herdr:session:create' => [
            ['session'],
            ['node' => null, 'user' => null, 'publish-observer' => false, 'json' => false],
        ],
        'herdr:session:adopt' => [
            ['session'],
            ['node' => null, 'user' => null, 'publish-observer' => false, 'json' => false],
        ],
        'herdr:session:list' => [[], ['node' => null, 'json' => false]],
        'herdr:session:destroy' => [
            ['session'],
            ['node' => null, 'accept-termination' => false, 'yes' => false, 'json' => false],
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
                'domain' => null,
                'branch' => null,
                'recover-source-profile' => false,
                'json' => false,
            ],
        ],
        'instance:database:add' => [
            ['slug'],
            ['instance' => null, 'prefix' => null, 'json' => false],
        ],
        'instance:database:remove' => [
            ['slug'],
            ['instance' => null, 'prefix' => null, 'force' => false, 'json' => false],
        ],
        'instance:deploy' => [['instance'], ['json' => false]],
        'instance:deploy-step:create' => [
            ['instance', 'name'],
            [
                'command' => null,
                'phase' => 'before_activation',
                'timeout' => null,
                'before' => null,
                'after' => null,
                'json' => false,
            ],
        ],
        'instance:deploy-step:destroy' => [['instance', 'name'], ['yes' => false, 'json' => false]],
        'instance:deploy-step:list' => [['instance'], ['json' => false]],
        'instance:deploy-step:update' => [
            ['instance', 'name'],
            [
                'command' => null,
                'phase' => null,
                'timeout' => null,
                'before' => null,
                'after' => null,
                'json' => false,
            ],
        ],
        'instance:deployment:list' => [['instance'], ['json' => false]],
        'instance:deployment:show' => [['deployment'], ['json' => false]],
        'instance:destroy' => [['instance'], ['yes' => false, 'force' => false, 'json' => false]],
        'instance:list' => [[], ['json' => false]],
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
                'domain' => null,
                'yes' => false,
                'json' => false,
            ],
        ],
        'instance:release:list' => [['instance'], ['json' => false]],
        'instance:rollback' => [['instance'], ['release' => null, 'json' => false]],
        'instance:dependencies:scan' => [[], ['app' => null, 'all' => false, 'json' => false]],
        'instance:dependencies:update' => [[], ['app' => null, 'all' => false, 'latest' => false, 'json' => false]],
        'instance:show' => [['instance'], ['json' => false]],
        'instance:transfer' => [
            ['instance', 'node'],
            ['name' => null, 'sqlite-source-path' => null, 'force' => false, 'json' => false],
        ],
        'instance:update' => [['instance'], ['branch' => null, 'json' => false]],
        'metrics:credentials' => [[], ['reset' => false, 'json' => false]],
        'metrics:disable' => [[], ['force' => false, 'purge-data' => false, 'json' => false]],
        'metrics:enable' => [['node'], ['json' => false]],
        'metrics:exporter:disable' => [['node'], ['json' => false]],
        'metrics:exporter:enable' => [['node'], ['json' => false]],
        'metrics:status' => [[], ['json' => false]],
        'node:access:add' => [['consumer', 'serving'], ['json' => false]],
        'node:access:remove' => [['consumer', 'serving'], ['force' => false, 'json' => false]],
        'node:add' => [
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
        'node:list' => [[], ['json' => false]],
        'node:metrics' => [['node'], ['json' => false]],
        'node:remove' => [['node'], ['force' => false, 'offline' => false, 'json' => false]],
        'node:rename' => [['node', 'name'], ['json' => false]],
        'node:settings' => [['node'], ['setting' => [], 'json' => false]],
        'node:role:add' => [['node', 'role'], ['converge' => false, 'json' => false]],
        'node:role:list' => [['node'], ['json' => false]],
        'node:role:relocate' => [['node', 'role'], ['force' => false, 'from' => null, 'json' => false]],
        'node:role:remove' => [
            ['node', 'role'],
            ['force' => false, 'purge-data' => false, 'offline' => false, 'json' => false],
        ],
        'node:show' => [['node'], ['json' => false]],
        'process:create' => [
            ['name'],
            [
                'instance' => null,
                'preset' => null,
                'node' => null,
                'app' => null,
                'for' => null,
                'runtime' => 'systemd',
                'command' => [],
                'image' => null,
                'working-directory' => null,
                'environment' => [],
                'port' => [],
                'volume' => [],
                'restart' => 'never',
                'keep-alive' => false,
                'start' => false,
                'json' => false,
            ],
        ],
        'process:list' => [[], ['instance' => null, 'node' => null, 'app' => null, 'json' => false]],
        'process:logs' => [['process'], ['lines' => '100', 'json' => false]],
        'process:destroy' => [['process'], ['app' => null, 'yes' => false, 'json' => false]],
        'process:restart' => [['process'], ['json' => false]],
        'process:show' => [['name'], ['app' => null, 'json' => false]],
        'process:start' => [['process'], ['json' => false]],
        'process:stop' => [['process'], ['json' => false]],
        'process:update' => [
            ['name'],
            [
                'app' => null,
                'for' => null,
                'runtime' => 'systemd',
                'command' => [],
                'image' => null,
                'working-directory' => null,
                'environment' => [],
                'port' => [],
                'volume' => [],
                'restart' => 'never',
                'keep-alive' => false,
                'json' => false,
            ],
        ],
        'profile' => [['url'], ['instance' => null, 'path' => null, 'as-first-user' => false, 'user' => null, 'json' => false]],
        'realtime:show' => [[], ['json' => false]],
        'realtime:tail' => [[], ['types' => null, 'json' => false]],
        'route:list' => [[], ['json' => false]],
        'route:create' => [
            ['app', 'domain'],
            [
                'publication' => 'private',
                'target' => null,
                'node' => null,
                'cluster' => null,
                'upstream' => null,
                'process' => null,
                'json' => false,
            ],
        ],
        'route:destroy' => [['route'], ['yes' => false, 'json' => false]],
        'route:show' => [['route'], ['json' => false]],
        'route:target:unset' => [['route'], ['yes' => false, 'json' => false]],
        'route:target:set' => [['route', 'target'], ['targets' => [], 'reassign' => [], 'remove' => [], 'json' => false]],
        'route:update' => [['route'], ['domain' => null, 'publication' => null, 'json' => false]],
        'schedule:enable' => [['schedule'], ['json' => false]],
        'schedule:create' => [[
            'name',
        ], [
            'node' => null,
            'instance' => null,
            'app' => null,
            'for' => null,
            'calendar' => null,
            'command' => null,
            'timeout' => '3600',
            'no-start' => false,
            'json' => false,
        ]],
        'schedule:list' => [[], ['app' => null, 'json' => false]],
        'schedule:logs' => [['schedule'], ['lines' => '100', 'json' => false]],
        'schedule:destroy' => [['schedule'], ['app' => null, 'yes' => false, 'json' => false]],
        'schedule:run' => [['schedule'], ['json' => false]],
        'schedule:show' => [['schedule'], ['app' => null, 'json' => false]],
        'schedule:update' => [[
            'name',
        ], [
            'app' => null,
            'for' => null,
            'calendar' => null,
            'command' => null,
            'timeout' => '3600',
            'json' => false,
        ]],
        'tool:install' => [
            ['package'],
            ['node' => null, 'manager' => null, 'constraint' => null, 'json' => false],
        ],
        'tool:list' => [[], ['node' => null, 'json' => false]],
        'tool:manager:list' => [[], ['node' => null, 'json' => false]],
        'tool:remove' => [['tool'], ['yes' => false, 'json' => false]],
        'tool:show' => [['tool'], ['json' => false]],
        'tool:update' => [['tool'], ['json' => false]],
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
            'node:add' => ['host'],
            'profile' => ['url'],
            'tool:install' => ['package'],
            'metrics:enable' => ['node'],
            'route:create' => ['app', 'domain'],
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
        'app:destroy' => [['app' => '1'], ...$profileMissing],
        'app:show' => [['app' => '1'], ...$profileMissing],
        'app:update' => [['app' => '1', '--slug' => 'shop'], ...$profileMissing],
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
        'database:create' => [[
            'slug' => 'app',
            '--driver' => 'mysql',
            '--host' => 'db.example.test',
            '--database' => 'app',
            '--username' => 'app',
            '--password' => 'secret',
        ], ...$profileMissing],
        'database:describe' => [['slug' => 'app', 'table' => 'users'], ...$profileMissing],
        'database:destroy' => [['slug' => 'app', '--force' => true], ...$profileMissing],
        'database:list' => [[], ...$profileMissing],
        'database:query' => [['slug' => 'app', 'sql' => 'SELECT 1'], ...$profileMissing],
        'database:schema' => [['slug' => 'app'], ...$profileMissing],
        'database:show' => [['slug' => 'app'], ...$profileMissing],
        'database:tables' => [['slug' => 'app'], ...$profileMissing],
        'database:update' => [['slug' => 'app', '--host' => 'db.example.test'], ...$profileMissing],
        'database:user:create' => [[
            'slug' => 'app',
            '--process' => '12',
            '--database' => 'app',
            '--username' => 'app',
            '--password' => 'secret',
        ], ...$profileMissing],
        'database:user:list' => [['slug' => 'app'], ...$profileMissing],
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
        'extension:disable' => [
            ['extension' => 'validation-secret'],
            'code' => 'extension.unknown',
            'message' => 'Unknown Orbit extension.',
        ],
        'extension:enable' => [
            ['extension' => 'validation-secret'],
            'code' => 'extension.unknown',
            'message' => 'Unknown Orbit extension.',
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
        'github:app:destroy' => [['--yes' => true], ...$profileMissing],
        'github:app:install' => [[], ...$profileMissing],
        'github:app:show' => [[], ...$profileMissing],
        'instance:clone' => [
            ['candidate' => '1', 'node' => '2', 'name' => 'web', '--preview-name' => 'web'],
            ...$profileMissing,
        ],
        'instance:create' => [['app' => '1', 'node' => '1', 'name' => 'web'], ...$profileMissing],
        'instance:database:add' => [[
            'slug' => 'app',
            '--instance' => '12',
        ], ...$profileMissing],
        'instance:database:remove' => [[
            'slug' => 'app',
            '--instance' => '12',
            '--force' => true,
        ], ...$profileMissing],
        'instance:deploy' => [['instance' => '1'], ...$profileMissing],
        'instance:deploy-step:create' => [['instance' => '1', 'name' => 'migrate', '--command' => 'true'], ...$profileMissing],
        'instance:deploy-step:destroy' => [['instance' => '1', 'name' => 'migrate'], ...$profileMissing],
        'instance:deploy-step:list' => [['instance' => '1'], ...$profileMissing],
        'instance:deploy-step:update' => [['instance' => '1', 'name' => 'migrate', '--command' => 'true'], ...$profileMissing],
        'instance:deployment:list' => [['instance' => '1'], ...$profileMissing],
        'instance:deployment:show' => [['deployment' => '1'], ...$profileMissing],
        'instance:destroy' => [['instance' => '1'], ...$profileMissing],
        'instance:list' => [[], ...$profileMissing],
        'instance:register' => [
            ['--app' => '1', '--no-interaction' => true, '--path' => '/tmp/orbit-command-surface-not-git'],
            'code' => 'instance.source_invalid',
            'message' => 'The current path is not a supported Git checkout or worktree.',
        ],
        'instance:release:list' => [['instance' => '1'], ...$profileMissing],
        'instance:rollback' => [['instance' => '1', '--release' => 'release-a'], ...$profileMissing],
        'instance:dependencies:scan' => [[], ...$profileMissing],
        'instance:dependencies:update' => [[], ...$profileMissing],
        'instance:show' => [['instance' => '1'], ...$profileMissing],
        'instance:transfer' => [
            ['instance' => '1', 'node' => '2', '--force' => true],
            ...$profileMissing,
        ],
        'instance:update' => [['instance' => '1', '--branch' => 'main'], ...$profileMissing],
        'metrics:credentials' => [[], ...$profileMissing],
        'metrics:disable' => [['--force' => true], ...$profileMissing],
        'metrics:enable' => [['node' => '1'], ...$profileMissing],
        'metrics:exporter:disable' => [['node' => '1'], ...$profileMissing],
        'metrics:exporter:enable' => [['node' => '1'], ...$profileMissing],
        'metrics:status' => [[], ...$profileMissing],
        'node:access:add' => [['consumer' => '2', 'serving' => '3'], ...$profileMissing],
        'node:access:remove' => [['consumer' => '2', 'serving' => '3', '--force' => true], ...$profileMissing],
        'node:add' => [['name' => 'node', 'host' => 'node.test'], ...$profileMissing],
        'node:list' => [[], ...$profileMissing],
        'node:metrics' => [['node' => '1'], ...$profileMissing],
        'node:remove' => [['node' => '1', '--force' => true], ...$profileMissing],
        'node:rename' => [['node' => '1', 'name' => 'vpn'], ...$profileMissing],
        'node:role:add' => [['node' => '7', 'role' => 'app-dev'], ...$profileMissing],
        'node:role:list' => [['node' => '7'], ...$profileMissing],
        'node:role:relocate' => [['node' => '7', 'role' => 'gateway', '--force' => true], ...$profileMissing],
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
        'process:show' => [['name' => 'worker', '--app' => '1'], ...$profileMissing],
        'process:start' => [['process' => '1'], ...$profileMissing],
        'process:stop' => [['process' => '1'], ...$profileMissing],
        'process:update' => [[
            'name' => 'worker',
            '--app' => '1',
            '--for' => 'development',
            '--command' => ['/usr/bin/php'],
        ], ...$profileMissing],
        'profile' => [
            ['url' => 'ftp://docs.test/archive'],
            'code' => 'profile.validation_failed',
            'message' => 'URL to profile must be an absolute HTTP or HTTPS URL.',
        ],
        'realtime:show' => [[], ...$profileMissing],
        'realtime:tail' => [[], ...$profileMissing],
        'route:list' => [[], ...$profileMissing],
        'route:create' => [['app' => '1', 'domain' => 'app.test', '--node' => '1'], ...$profileMissing],
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
        'schedule:update' => [[
            'name' => 'daily-report',
            '--app' => '1',
            '--for' => 'production',
            '--calendar' => 'daily',
            '--command' => 'php artisan report:send',
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
        'top' => [
            [],
            'code' => 'input.invalid',
            'message' => 'orbit top does not support --json; it is an interactive screen with no final result.',
        ],
    ];
    $visibleCommandNames = collect(app(Kernel::class)->all())
        ->reject(static fn (Command $command): bool => $command->isHidden())
        ->except(['extension:list'])
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
});

/**
 * @return list<string>
 */
function orbitProductCommandNames(): array
{
    return collect(app(Kernel::class)->all())
        ->filter(static fn (Command $command): bool => str_starts_with($command::class, 'App\\Commands\\'))
        ->keys()
        ->sort()
        ->values()
        ->all();
}
