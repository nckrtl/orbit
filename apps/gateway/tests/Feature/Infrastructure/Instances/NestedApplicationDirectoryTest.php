<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Doctor\NativeInstanceAppStateInspector;
use App\Infrastructure\Instances\NativeDevelopmentInstanceProvisioner;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('detects PHP and configures the Laravel URL in the application directory', function (string $root, string $relative): void {
    $checkout = sys_get_temp_dir().'/orbit-nested-source-'.Str::uuid();
    $application = $checkout.$relative;
    $files = new Filesystem;
    $files->ensureDirectoryExists($application.'/bootstrap/cache');
    $files->ensureDirectoryExists($application.'/public');
    file_put_contents($application.'/composer.json', '{"require":{"php":"~8.4.0","laravel/framework":"^13.0"}}');
    file_put_contents($application.'/artisan', '<?php');
    file_put_contents($application.'/.env.example', "APP_NAME=Nested\nAPP_URL=http://old.test\n");
    file_put_contents($application.'/bootstrap/cache/config.php', "<?php return ['app' => ['url' => 'http://old.test']];");
    chmod($application.'/.env.example', 0644);
    if ($relative !== '') {
        file_put_contents($checkout.'/.env', "APP_URL=https://repository.test\n");
    }

    try {
        [$configurator, $instance, $account] = nested_application_configurator($checkout, $root);
        $profile = $configurator->inspect($instance);

        expect($profile->phpVersion)->toBe('8.4')->and($profile->laravel)->toBeTrue();

        $configurator->configureLaravelUrl($instance, 'https://nested.test');
        expect(file_get_contents($application.'/.env'))->toBe("APP_NAME=Nested\nAPP_URL=https://nested.test\n")
            ->and(file_get_contents($application.'/bootstrap/cache/config.php'))->toBe("<?php return ['app' => ['url' => 'https://nested.test']];")
            ->and(fileperms($application.'/.env') & 0777)->toBe(0640);
        if ($relative !== '') {
            expect(file_get_contents($checkout.'/.env'))->toBe("APP_URL=https://repository.test\n");
        }

        file_put_contents($application.'/.env', "TOKEN=keep\nAPP_URL=https://operator.test\n");
        $configurator->configureLaravelUrl($instance, 'https://next.test');
        expect(file_get_contents($application.'/.env'))->toBe("TOKEN=keep\nAPP_URL=https://next.test\n");

        $site = new DevelopmentSite(1, '10.44.0.10', 'app-instance-1', $checkout, $root, $profile->phpVersion, 'nested.test');
        expect(new DevelopmentPhpFpmConfigRenderer()->render(collect([$site]), $account))->toContain("chdir = {$application}\n");
    } finally {
        $files->deleteDirectory($checkout);
    }
})->with(['nested Laravel' => ['server/web/public', '/server/web'], 'root public regression' => ['public', '']]);

it('refuses application metadata reached through a linked parent directory', function (): void {
    $directory = sys_get_temp_dir().'/orbit-nested-link-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory.'/checkout');
    $files->ensureDirectoryExists($directory.'/outside/web');
    file_put_contents($directory.'/outside/web/composer.json', '{"require":{"laravel/framework":"^13.0"}}');
    file_put_contents($directory.'/outside/web/artisan', '<?php');
    symlink($directory.'/outside', $directory.'/checkout/server');

    try {
        [$configurator, $instance] = nested_application_configurator($directory.'/checkout', 'server/web/public');

        expect(fn () => $configurator->inspect($instance))->toThrow(
            fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-dev.source_metadata_unsafe'),
        );
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('converges per-app route APP_URL files and cached URLs without rewriting a sibling', function (): void {
    $checkout = sys_get_temp_dir().'/orbit-per-app-source-'.Str::uuid();
    $files = new Filesystem;
    foreach (['web', 'docs'] as $app) {
        $directory = $checkout.'/apps/'.$app;
        $files->ensureDirectoryExists($directory.'/bootstrap/cache');
        $files->ensureDirectoryExists($directory.'/public');
        file_put_contents($directory.'/composer.json', '{"require":{"php":"~8.4.0","laravel/framework":"^13.0"}}');
        file_put_contents($directory.'/artisan', '<?php');
        file_put_contents($directory.'/.env.example', "APP_NAME={$app}\nAPP_URL=http://old.test\n");
        file_put_contents($directory.'/.env.testing', "APP_ENV=testing\nAPP_URL=http://old.test\n");
        file_put_contents($directory.'/bootstrap/cache/config.php', "<?php return ['app' => ['url' => 'http://old.test']];");
    }
    try {
        [$configuration, $instance, , $ssh] = nested_application_configurator($checkout, 'public');
        $instance->project->update(['slug' => 'two-apps', 'apps' => [
            ['name' => 'web', 'path' => 'apps/web', 'web_root' => 'public', 'type' => 'laravel-app'],
            ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => 'laravel-app'],
        ]]);
        $instance->node->update(['tld' => 'test']);
        $instance->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
        $ports = Mockery::mock(VitePortRuntime::class);
        $ports->shouldReceive('selectPort')->andReturnUsing(static function (Node $node, int $preferred, array $excluded): int {
            while (in_array($preferred, $excluded, true)) {
                $preferred++;
            }

            return $preferred;
        });
        app()->instance(VitePortRuntime::class, $ports);
        $projection = new class implements DevelopmentRouteProjector
        {
            public function converge(Instance $instance, Route $route): void
            {
                $route->publishSites();
            }
        };
        $lock = new class implements DevelopmentProjectionOperationLock
        {
            public function run(Closure $operation): mixed
            {
                return $operation();
            }
        };
        $provisioner = new NativeDevelopmentInstanceProvisioner(app(CreateRouteAction::class), $configuration, $projection, $lock);
        $active = $provisioner->complete($instance, null);
        foreach (['web', 'docs'] as $app) {
            $url = 'https://'.$active->authoritativeRoute($app)->domain;
            $directory = $checkout.'/apps/'.$app;
            expect(file_get_contents($directory.'/.env'))->toBe("APP_NAME={$app}\nAPP_URL={$url}\n")
                ->and(file_get_contents($directory.'/.env.testing'))->toBe("APP_ENV=testing\nAPP_URL={$url}\n")
                ->and(file_get_contents($directory.'/bootstrap/cache/config.php'))->toBe("<?php return ['app' => ['url' => '{$url}']];")
                ->and($instance->environmentValues()->where('app', $app)->where('env_key', 'APP_URL')->first()->env_value)->toBe($url);
        }
        $inspector = new NativeInstanceAppStateInspector($ssh, $configuration);
        expect($inspector->inspectApp($active, 'web')->sourceProfileMatches)->toBeTrue()
            ->and($inspector->inspectApp($active, 'docs')->sourceProfileMatches)->toBeTrue();
        file_put_contents($checkout.'/apps/docs/composer.json', '{"require":{"php":"~5.0.0","laravel/framework":"^13.0"}}');
        expect($inspector->inspectApp($active, 'docs')->pathMatches)->toBeTrue()
            ->and($inspector->inspectApp($active, 'docs')->sourceProfileMatches)->toBeFalse()
            ->and($inspector->inspectApp($active, 'web')->sourceProfileMatches)->toBeTrue();
        $files->deleteDirectory($checkout.'/apps/docs/public');
        expect($inspector->inspectApp($active, 'docs')->pathMatches)->toBeFalse();
        $files->moveDirectory($checkout.'/apps/web', $checkout.'/apps/web-missing');
        expect($inspector->inspectApp($active, 'web')->pathMatches)->toBeFalse();
        $files->moveDirectory($checkout.'/apps/web-missing', $checkout.'/apps/web');
        $docs = file_get_contents($checkout.'/apps/docs/.env');
        $docsCache = file_get_contents($checkout.'/apps/docs/bootstrap/cache/config.php');
        $configuration->configureLaravelUrl($active, 'https://custom.web.test', 'web');
        expect(file_get_contents($checkout.'/apps/docs/.env'))->toBe($docs)
            ->and(file_get_contents($checkout.'/apps/docs/bootstrap/cache/config.php'))->toBe($docsCache)
            ->and(file_get_contents($checkout.'/apps/web/.env'))->toContain('APP_URL=https://custom.web.test')
            ->and($instance->environmentValues()->where('app', 'docs')->where('env_key', 'APP_URL')->first()->env_value)->toBe('https://docs.feature.two-apps.test');
    } finally {
        $files->deleteDirectory($checkout);
    }
});

/** @return array{RemoteDevelopmentInstanceConfigurator, Instance, ManagedUserAccount, DevelopmentSshExecutor} */
function nested_application_configurator(string $checkout, string $root): array
{
    $owner = posix_getpwuid(posix_geteuid());
    assert(is_array($owner));
    $account = new ManagedUserAccount($owner['name'], $owner['name'], '/home/'.$owner['name']);
    $accounts = new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(private readonly ManagedUserAccount $account) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $ssh = new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $process = new Process($command->arguments);
            $process->setInput($command->protectedInput?->stream() ?? $command->input);
            $process->mustRun();

            return new CommandResult(0, $process->getOutput(), $process->getErrorOutput(), 0, false);
        }
    };
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
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $node = Node::query()->create(['name' => 'nested-source', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'user' => $owner['name']]);
    $project = Project::query()->create(['name' => 'Nested', 'slug' => 'nested-'.Str::lower(Str::random(8)), 'repository_url' => 'https://example.test/nested.git', 'root' => $root]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => $checkout, 'branch' => 'feature', 'starting_commit' => str_repeat('a', 40), 'status' => 'source_resolved']);

    $development = new DevelopmentSshExecutor($ssh, $keys, $hosts);

    return [new RemoteDevelopmentInstanceConfigurator($development, $accounts, new ComposerSourceClassifier(new InstancePhpVersionCatalog)), $instance, $account, $development];
}
