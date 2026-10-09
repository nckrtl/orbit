<?php

declare(strict_types=1);

use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\UpdateProjectData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Dotenv\Dotenv;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\Orb101ProjectUpdateFixture;

it('requires one Composer Laravel declaration and one regular Artisan marker', function (): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);
    $composer = json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^13.0']], JSON_THROW_ON_ERROR);

    expect($classifier->classify($composer, ProjectType::LaravelApp, 'regular'))
        ->phpVersion->toBe('8.5')
        ->laravel->toBeTrue();
});

it('refuses partial conflicting and unsafe Laravel markers', function (array $composer, string $artisan): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);

    expect(fn () => $classifier->classify(json_encode($composer, JSON_THROW_ON_ERROR), ProjectType::LaravelApp, $artisan))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.laravel_source_invalid');
        });
})->with([
    'artisan only' => [['require' => ['php' => '^8.4']], 'regular'],
    'declaration only' => [['require' => ['laravel/framework' => '^13.0']], 'absent'],
    'duplicate declaration' => [
        [
            'require' => ['laravel/framework' => '^13.0'],
            'require-dev' => ['laravel/framework' => '^13.0'],
        ],
        'regular',
    ],
    'symlinked artisan' => [['require' => ['laravel/framework' => '^13.0']], 'unsafe'],
]);

it('leaves a Composer non-Laravel source classified as PHP only', function (): void {
    $profile = new ComposerSourceClassifier(new InstancePhpVersionCatalog)
        ->classify('{"require":{"php":"~8.4.0"}}', ProjectType::LaravelApp, 'absent');

    expect($profile->phpVersion)->toBe('8.4')->and($profile->laravel)->toBeFalse();
});

it('emits a source preflight that rejects foreign-owned Composer metadata', function (): void {
    $directory = sys_get_temp_dir().'/orbit-source-owner-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    file_put_contents($directory.'/composer.json', '{"require":{"php":"^8.4"}}');

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory, 'nobody');

        expect(fn () => $configurator->inspect($instance))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('app-dev.source_metadata_unsafe');
            });

        $result = orb127_run_laravel_command($ssh->commands[0]);
        expect($result->isSuccessful())
            ->toBeTrue($result->getErrorOutput())
            ->and(trim($result->getOutput()))
            ->toBe('UNSAFE');
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('does not classify application metadata for an unrouted monorepo default', function (): void {
    $directory = sys_get_temp_dir().'/orbit-monorepo-default-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    // A monorepo's root metadata need not belong to a single managed application.
    file_put_contents($directory.'/composer.json', '{"scripts":{"check":"composer check --working-dir=apps/gateway"}}');

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory, 'nobody');
        $instance->project->update(['type' => ProjectType::Monorepo, 'apps' => fixture_apps('public', ProjectType::Monorepo)]);
        $instance->update(['name' => 'default']);
        try {
            $profile = $configurator->inspect($instance);
        } catch (RuntimeConvergenceException $exception) {
            expect($exception->errorCode)->toBe('app-dev.source_metadata_unsafe')
                ->and(orb127_run_laravel_command($ssh->commands[0])->getOutput())->toBe("UNSAFE\n");

            throw $exception;
        }

        expect($profile->phpVersion)->toBeNull()
            ->and($profile->laravel)->toBeFalse()
            ->and($ssh->commands)->toBe([]);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('retains metadata safety checks for a monorepo with a Route', function (RouteStatus $status): void {
    $directory = sys_get_temp_dir().'/orbit-monorepo-routed-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    file_put_contents($directory.'/composer.json', '{}');

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory, 'nobody');
        $instance->project->update(['type' => ProjectType::Monorepo]);
        $route = Route::query()->create([
            'project_id' => $instance->project_id,
            'node_id' => $instance->node_id,
            'domain' => 'monorepo.test',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update([
            'status' => $status,
            'failed_step' => $status === RouteStatus::Failed ? 'source-classification' : null,
            'error_code' => $status === RouteStatus::Failed ? 'app-dev.source_metadata_unsafe' : null,
        ]);

        expect(fn () => $configurator->inspect($instance))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('app-dev.source_metadata_unsafe');
            });
        expect($ssh->commands)->toHaveCount(1)
            ->and(orb127_run_laravel_command($ssh->commands[0])->getOutput())->toBe("UNSAFE\n");
    } finally {
        $files->deleteDirectory($directory);
    }
})->with([RouteStatus::Pending, RouteStatus::Active, RouteStatus::Failed]);

it('refuses malformed Composer metadata and conflicting Laravel declarations', function (): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);

    expect(fn () => $classifier->classify('{', ProjectType::LaravelApp, 'regular'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_version_unsupported');
        })
        ->and(fn () => $classifier->classify(json_encode([
            'require' => ['laravel/framework' => '^13.0'],
            'require-dev' => ['laravel/framework' => '^12.0'],
        ], JSON_THROW_ON_ERROR), ProjectType::LaravelApp, 'regular'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.laravel_source_invalid');
        });
});

it('atomically reconciles only Laravel URL values and preserves later operator changes', function (): void {
    $directory = sys_get_temp_dir().'/orbit-laravel-url-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory.'/bootstrap/cache');
    $initialEnvironment = "APP_NAME=Acme\nAPP_URL=http://old.test\nTOKEN=unchanged\n";
    $initialCache = <<<'PHP'
        <?php

        return [
            'app' => [
                'name' => 'Acme',
                'url' => 'http://old.test',
            ],
            'filesystems' => [
                'disks' => ['public' => ['url' => 'https://files.example.test/storage']],
            ],
            'token' => 'unchanged',
        ];
        PHP;
    file_put_contents($directory.'/.env', $initialEnvironment);
    file_put_contents($directory.'/bootstrap/cache/config.php', $initialCache);
    chmod($directory.'/.env', 0o640);
    chmod($directory.'/bootstrap/cache/config.php', 0o644);

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory);

        $configurator->configureLaravelUrl($instance, 'https://feature.acme.test');
        $first = orb127_run_laravel_command($ssh->commands[0]);

        expect($first->isSuccessful())
            ->toBeTrue($first->getErrorOutput())
            ->and(file_get_contents($directory.'/.env'))
            ->toBe("APP_NAME=Acme\nAPP_URL=https://feature.acme.test\nTOKEN=unchanged\n")
            ->and(file_get_contents($directory.'/bootstrap/cache/config.php'))
            ->toBe(str_replace("'url' => 'http://old.test'", "'url' => 'https://feature.acme.test'", $initialCache))
            ->and(fileperms($directory.'/.env') & 0o777)
            ->toBe(0o640)
            ->and(fileperms($directory.'/bootstrap/cache/config.php') & 0o777)
            ->toBe(0o644);

        file_put_contents(
            $directory.'/.env',
            "APP_NAME=Operator\nAPP_URL=https://operator.test\nTOKEN=later-change\n",
        );
        $operatorCache = str_replace(
            ["'name' => 'Acme'", "'url' => 'https://feature.acme.test'", "'token' => 'unchanged'"],
            ["'name' => 'Operator'", "'url' => 'https://operator.test'", "'token' => 'later-change'"],
            (string) file_get_contents($directory.'/bootstrap/cache/config.php'),
        );
        file_put_contents($directory.'/bootstrap/cache/config.php', $operatorCache);

        $configurator->configureLaravelUrl($instance, 'https://next.acme.test');
        $second = orb127_run_laravel_command($ssh->commands[1]);

        expect($second->isSuccessful())
            ->toBeTrue($second->getErrorOutput())
            ->and(file_get_contents($directory.'/.env'))
            ->toBe("APP_NAME=Operator\nAPP_URL=https://next.acme.test\nTOKEN=later-change\n")
            ->and(file_get_contents($directory.'/bootstrap/cache/config.php'))
            ->toBe(str_replace(
                "'url' => 'https://operator.test'",
                "'url' => 'https://next.acme.test'",
                $operatorCache,
            ));
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('creates missing Laravel environment configuration from its safe template', function (): void {
    $directory = sys_get_temp_dir().'/orbit-laravel-template-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    file_put_contents($directory.'/.env.example', "APP_NAME=Acme\nTOKEN=installation-input\n");
    chmod($directory.'/.env.example', 0o640);

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory);

        $configurator->configureLaravelUrl($instance, 'https://feature.acme.test');
        $result = orb127_run_laravel_command($ssh->commands[0]);

        expect($result->isSuccessful())
            ->toBeTrue($result->getErrorOutput())
            ->and(file_get_contents($directory.'/.env.example'))
            ->toBe("APP_NAME=Acme\nTOKEN=installation-input\n")
            ->and(file_get_contents($directory.'/.env'))
            ->toMatch('#^APP_NAME=Acme\nTOKEN=installation-input\nAPP_URL=https://feature\.acme\.test\nAPP_KEY="base64:[A-Za-z0-9+/]{43}="\n$#')
            ->and(fileperms($directory.'/.env') & 0o777)
            ->toBe(0o640);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('writes a usable development .env APP_KEY and the Project name from an empty template', function (): void {
    $directory = sys_get_temp_dir().'/orbit-laravel-key-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    file_put_contents($directory.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_URL=http://localhost\n");

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory);
        $instance->project->update(['name' => 'Acme "Shop" $HOME']);

        $configurator->configureLaravelUrl($instance->refresh(), 'https://feature.acme.test');
        $result = orb127_run_laravel_command($ssh->commands[0]);
        $environment = Dotenv::parse((string) file_get_contents($directory.'/.env'));

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and(array_keys($environment))->toBe(['APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_URL'])
            ->and($environment['APP_NAME'])->toBe('Acme "Shop" $HOME')
            ->and($environment['APP_URL'])->toBe('https://feature.acme.test')
            ->and($environment['APP_KEY'])->toStartWith('base64:')
            ->and(strlen((string) base64_decode(substr((string) $environment['APP_KEY'], 7), true)))->toBe(32)
            ->and(implode(' ', $ssh->commands[0]->arguments))->not->toContain((string) $environment['APP_KEY']);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('projects stored values into a new development .env APP_KEY and APP_NAME', function (): void {
    $directory = sys_get_temp_dir().'/orbit-laravel-stored-key-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    file_put_contents($directory.'/.env.example', "APP_NAME=Template\nAPP_KEY=base64:template\n");
    $key = 'base64:'.base64_encode(str_repeat('k', 32));

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory);
        $instance->environmentValues()->createMany([
            ['env_key' => 'APP_KEY', 'env_value' => $key],
            ['env_key' => 'APP_NAME', 'env_value' => 'Stored Name'],
        ]);

        $configurator->configureLaravelUrl($instance, 'https://feature.acme.test');
        $result = orb127_run_laravel_command($ssh->commands[0]);

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and(Dotenv::parse((string) file_get_contents($directory.'/.env')))
            ->toBe(['APP_NAME' => 'Stored Name', 'APP_KEY' => $key, 'APP_URL' => 'https://feature.acme.test']);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('fills only an empty development .env APP_KEY in an existing file', function (): void {
    $directory = sys_get_temp_dir().'/orbit-laravel-existing-key-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    file_put_contents($directory.'/.env', "APP_NAME=Laravel\nAPP_KEY=\nAPP_URL=http://old.test\n");

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory);

        $configurator->configureLaravelUrl($instance, 'https://feature.acme.test');
        $first = orb127_run_laravel_command($ssh->commands[0]);
        $filled = (string) file_get_contents($directory.'/.env');

        $configurator->configureLaravelUrl($instance, 'https://next.acme.test');
        $second = orb127_run_laravel_command($ssh->commands[1]);

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($filled)->toMatch('#^APP_NAME=Laravel\nAPP_KEY="base64:[A-Za-z0-9+/]{43}="\nAPP_URL=https://feature\.acme\.test\n$#')
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(file_get_contents($directory.'/.env'))
            ->toBe(str_replace('https://feature.acme.test', 'https://next.acme.test', $filled));
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('keeps an Instance environment unreadable to other local users', function (bool $exists): void {
    $directory = sys_get_temp_dir().'/orbit-laravel-env-mode-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    $file = $directory.($exists ? '/.env' : '/.env.example');
    file_put_contents($file, $exists ? "APP_URL=https://feature.acme.test\n" : "APP_NAME=Acme\n");
    chmod($file, 0o664);

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory);

        $configurator->configureLaravelUrl($instance, 'https://feature.acme.test');
        $result = orb127_run_laravel_command($ssh->commands[0]);

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput())
            ->and(fileperms($directory.'/.env') & 0o777)->toBe(0o660);
    } finally {
        $files->deleteDirectory($directory);
    }
})->with(['created from a template' => false, 'already present and unchanged' => true]);

it('keeps the Laravel URL out of argv input state and debug output', function (): void {
    [$configurator, $ssh, $instance] = orb127_laravel_configurator('/srv/acme/feature');
    $url = 'https://secret-value.acme.test';

    $configurator->configureLaravelUrl($instance, $url);

    $command = $ssh->commands[0];
    $protectedInput = $command->protectedInput;
    expect($command->arguments)
        ->not->toContain($url)->and($command->input)->toBeNull()->and($protectedInput)
        ->not->toBeNull()->and((array) $protectedInput)
        ->not->toContain($url)
        ->and(json_decode((string) stream_get_contents($protectedInput?->stream()), true, flags: JSON_THROW_ON_ERROR))
        ->url->toBe($url)
        ->app_key->toStartWith('APP_KEY="base64:');

    $metadata = stream_get_meta_data($protectedInput?->stream());
    expect(fileperms($metadata['uri']) & 0o777)->toBe(0o600);
});

it('refuses unsafe Laravel URL files before replacing any bytes', function (string $unsafe): void {
    $directory = sys_get_temp_dir().'/orbit-laravel-unsafe-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory.'/bootstrap/cache');
    $external = $directory.'-external';
    file_put_contents($external, "APP_URL=https://outside.test\n");
    file_put_contents($directory.'/.env', "APP_URL=https://inside.test\n");
    file_put_contents($directory.'/bootstrap/cache/config.php', "<?php return ['url' => 'https://inside.test'];\n");

    if ($unsafe === 'symlink') {
        unlink($directory.'/.env');
        symlink($external, $directory.'/.env');
    }

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator(
            $directory,
            $unsafe === 'owner' ? 'not-the-file-owner' : null,
        );

        $configurator->configureLaravelUrl($instance, 'https://next.test');
        $result = orb127_run_laravel_command($ssh->commands[0]);

        expect($result->getExitCode())
            ->toBe(42)
            ->and(file_get_contents($external))
            ->toBe("APP_URL=https://outside.test\n")
            ->and(file_get_contents($directory.'/bootstrap/cache/config.php'))
            ->toBe("<?php return ['url' => 'https://inside.test'];\n");
    } finally {
        $files->deleteDirectory($directory);
        $files->delete($external);
    }
})->with(['symlink', 'owner']);

it('refuses duplicate Laravel URL values without changing the environment', function (): void {
    $directory = sys_get_temp_dir().'/orbit-laravel-duplicate-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);
    $environment = "APP_URL=https://first.test\nAPP_NAME=Acme\nAPP_URL=https://second.test\n";
    file_put_contents($directory.'/.env', $environment);

    try {
        [$configurator, $ssh, $instance] = orb127_laravel_configurator($directory);

        $configurator->configureLaravelUrl($instance, 'https://next.test');
        $result = orb127_run_laravel_command($ssh->commands[0]);

        expect($result->getExitCode())
            ->toBe(42)
            ->and(file_get_contents($directory.'/.env'))
            ->toBe($environment);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('reconciles Laravel canonical URLs when a Project slug changes', function (): void {
    $fixture = Orb101ProjectUpdateFixture::bind($this);
    $fixture->defaultInstance->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://acme.test',
    ]);

    app(UpdateProjectAction::class)->execute(
        $fixture->project,
        new UpdateProjectData(
            typeProvided: false,
            type: null,
            slugProvided: true,
            slug: 'shop',
            repositoryUrlProvided: false,
            repositoryUrl: null,
            defaultBranchProvided: false,
            defaultBranch: null,
        ),
    );

    expect($fixture->projections->laravelUrls)
        ->toBe([['instance_id' => $fixture->defaultInstance->id, 'url' => 'https://shop.test']])
        ->and(InstanceEnvironmentValue::query()->where('env_key', 'APP_URL')->value('env_value'))
        ->toBe('https://shop.test');
});

it('restores Laravel URL environment on a failed slug update and ignores application errors after publication', function (): void {
    $fixture = Orb101ProjectUpdateFixture::bind($this);
    $fixture->defaultInstance->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://acme.test',
    ]);
    $fixture->projections->failSlugPrepare = true;

    expect(fn () => app(UpdateProjectAction::class)->execute(
        $fixture->project,
        new UpdateProjectData(
            typeProvided: false,
            type: null,
            slugProvided: true,
            slug: 'shop',
            repositoryUrlProvided: false,
            repositoryUrl: null,
            defaultBranchProvided: false,
            defaultBranch: null,
        ),
    ))->toThrow(ResourceOperationException::class);

    expect(InstanceEnvironmentValue::query()->where('env_key', 'APP_URL')->value('env_value'))
        ->toBe('https://acme.test');

    $fixture->projections->failSlugPrepare = false;
    $fixture->projections->applicationErrorOnUrl = true;

    expect(fn () => app(UpdateProjectAction::class)->execute(
        $fixture->project->refresh(),
        new UpdateProjectData(
            typeProvided: false,
            type: null,
            slugProvided: true,
            slug: 'shop',
            repositoryUrlProvided: false,
            repositoryUrl: null,
            defaultBranchProvided: false,
            defaultBranch: null,
        ),
    ))->toThrow(ResourceOperationException::class);

    expect($fixture->project->refresh()->slug)->toBe('acme');
});

/** @return array{RemoteDevelopmentInstanceConfigurator, AppDevFakeSshExecutor, Instance} */
function orb127_laravel_configurator(string $checkoutPath, ?string $managedUser = null): array
{
    $owner = posix_getpwuid(posix_geteuid());
    $user = $managedUser ?? (is_array($owner) && is_string($owner['name'] ?? null) ? $owner['name'] : 'orbit');
    $account = new ManagedUserAccount($user, $user, '/home/'.$user);
    $accounts = new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $ssh = new AppDevFakeSshExecutor;
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $node = Node::query()->create([
        'name' => 'laravel-configurator',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'user' => $user,
    ]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/acme.git',
        'apps' => fixture_apps(null),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'checkout_path' => $checkoutPath,
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'status' => 'source_resolved',
    ]);
    $configurator = new RemoteDevelopmentInstanceConfigurator(
        new DevelopmentSshExecutor($ssh, $keys, $knownHosts),
        $accounts,
        new ComposerSourceClassifier(new InstancePhpVersionCatalog),
    );

    return [$configurator, $ssh, $instance];
}

function orb127_run_laravel_command(RemoteCommand $command): Process
{
    $process = new Process($command->arguments);
    $process->setInput($command->protectedInput?->stream() ?? $command->input);
    $process->run();

    return $process;
}
