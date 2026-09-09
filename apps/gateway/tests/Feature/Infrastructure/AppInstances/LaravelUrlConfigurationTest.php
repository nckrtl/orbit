<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstancePhpVersionCatalog;
use App\Domain\AppInstances\ComposerSourceClassifier;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppInstances\RemoteDevelopmentAppInstanceConfigurator;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

it('requires one Composer Laravel declaration and one regular Artisan marker', function (): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);
    $composer = json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^13.0']], JSON_THROW_ON_ERROR);

    expect($classifier->classify($composer, 'regular'))
        ->phpVersion->toBe('8.5')
        ->laravel->toBeTrue();
});

it('refuses partial conflicting and unsafe Laravel markers', function (array $composer, string $artisan): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);

    expect(fn () => $classifier->classify(json_encode($composer, JSON_THROW_ON_ERROR), $artisan))
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
    $profile = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog)
        ->classify('{"require":{"php":"~8.4.0"}}', 'absent');

    expect($profile->phpVersion)->toBe('8.4')->and($profile->laravel)->toBeFalse();
});

it('refuses malformed Composer metadata and conflicting Laravel declarations', function (): void {
    $classifier = new ComposerSourceClassifier(new AppInstancePhpVersionCatalog);

    expect(fn () => $classifier->classify('{', 'regular'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.php_version_unsupported');
        })
        ->and(fn () => $classifier->classify(json_encode([
            'require' => ['laravel/framework' => '^13.0'],
            'require-dev' => ['laravel/framework' => '^12.0'],
        ], JSON_THROW_ON_ERROR), 'regular'))
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
            'token' => 'unchanged',
        ];
        PHP;
    file_put_contents($directory.'/.env', $initialEnvironment);
    file_put_contents($directory.'/bootstrap/cache/config.php', $initialCache);
    chmod($directory.'/.env', 0o640);
    chmod($directory.'/bootstrap/cache/config.php', 0o644);

    try {
        [$configurator, $ssh, $appInstance] = orb127_laravel_configurator($directory);

        $configurator->configureLaravelUrl($appInstance, 'https://feature.acme.test');
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

        $configurator->configureLaravelUrl($appInstance, 'https://next.acme.test');
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
        [$configurator, $ssh, $appInstance] = orb127_laravel_configurator($directory);

        $configurator->configureLaravelUrl($appInstance, 'https://feature.acme.test');
        $result = orb127_run_laravel_command($ssh->commands[0]);

        expect($result->isSuccessful())
            ->toBeTrue($result->getErrorOutput())
            ->and(file_get_contents($directory.'/.env.example'))
            ->toBe("APP_NAME=Acme\nTOKEN=installation-input\n")
            ->and(file_get_contents($directory.'/.env'))
            ->toBe("APP_NAME=Acme\nTOKEN=installation-input\nAPP_URL=https://feature.acme.test\n")
            ->and(fileperms($directory.'/.env') & 0o777)
            ->toBe(0o640);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('keeps the Laravel URL out of argv input state and debug output', function (): void {
    [$configurator, $ssh, $appInstance] = orb127_laravel_configurator('/srv/acme/feature');
    $url = 'https://secret-value.acme.test';

    $configurator->configureLaravelUrl($appInstance, $url);

    $command = $ssh->commands[0];
    $protectedInput = $command->protectedInput;
    expect($command->arguments)
        ->not->toContain($url)->and($command->input)->toBeNull()->and($protectedInput)
        ->not->toBeNull()->and((array) $protectedInput)
        ->not->toContain($url)->and(stream_get_contents($protectedInput?->stream()))->toBe($url);

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
        [$configurator, $ssh, $appInstance] = orb127_laravel_configurator(
            $directory,
            $unsafe === 'owner' ? 'not-the-file-owner' : null,
        );

        $configurator->configureLaravelUrl($appInstance, 'https://next.test');
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
        [$configurator, $ssh, $appInstance] = orb127_laravel_configurator($directory);

        $configurator->configureLaravelUrl($appInstance, 'https://next.test');
        $result = orb127_run_laravel_command($ssh->commands[0]);

        expect($result->getExitCode())
            ->toBe(42)
            ->and(file_get_contents($directory.'/.env'))
            ->toBe($environment);
    } finally {
        $files->deleteDirectory($directory);
    }
});

/** @return array{RemoteDevelopmentAppInstanceConfigurator, AppDevFakeSshExecutor, AppInstance} */
function orb127_laravel_configurator(string $checkoutPath, ?string $managedUser = null): array
{
    $owner = posix_getpwuid(posix_geteuid());
    $user = $managedUser ?? (is_array($owner) && is_string($owner['name'] ?? null) ? $owner['name'] : 'orbit');
    $account = new ManagedUserAccount($user, $user, '/home/'.$user);
    $accounts = new class($account) implements ManagedUserAccountResolver {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $ssh = new AppDevFakeSshExecutor;
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore {
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
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $appInstance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'checkout_path' => $checkoutPath,
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'status' => 'source_resolved',
    ]);
    $configurator = new RemoteDevelopmentAppInstanceConfigurator(
        new AppDevSshExecutor($ssh, $keys, $knownHosts),
        $accounts,
        new ComposerSourceClassifier(new AppInstancePhpVersionCatalog),
    );

    return [$configurator, $ssh, $appInstance];
}

function orb127_run_laravel_command(RemoteCommand $command): Process
{
    $process = new Process($command->arguments);
    $process->setInput($command->protectedInput?->stream() ?? $command->input);
    $process->run();

    return $process;
}
