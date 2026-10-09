<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Str;
use Tests\Support\AppDevFakeSshExecutor;

it('owns a finite descending Instance PHP candidate catalog', function (): void {
    expect(new InstancePhpVersionCatalog()->versions())->toBe(['8.5', '8.4']);
});

it('selects the highest compatible candidate', function (?string $constraint, string $version): void {
    expect(new InstancePhpVersionCatalog()->select($constraint))->toBe($version);
})->with([
    'no constraint' => [null, '8.5'],
    'both candidates' => ['^8.4', '8.5'],
    '8.4 only' => ['~8.4.0', '8.4'],
    'bounded above' => ['>=8.4 <8.5', '8.4'],
]);

it('refuses invalid and unsupported source constraints', function (string $constraint): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);

    expect(fn () => $classifier->classify(json_encode(['require' => [
        'php' => $constraint,
    ]], JSON_THROW_ON_ERROR), ProjectType::LaravelApp, 'absent'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->step)
                ->toBe('source-classification')
                ->and($exception->errorCode)
                ->toBe('app-dev.php_version_unsupported');
        });
})->with([
    'invalid' => ['not a constraint'],
    'below catalog' => ['<8.4'],
    'between candidates' => ['>8.4 <8.5'],
    'above catalog' => ['>8.5'],
]);

it('classifies Composer metadata as PHP and metadata absence as non-PHP', function (): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);

    expect($classifier->classify('{"name":"acme/site"}', ProjectType::LaravelApp, 'absent'))
        ->phpVersion->toBe('8.5')
        ->laravel->toBeFalse();
});

it('laravel package without artisan inspects require-dev Laravel while a Laravel app still rejects it', function (): void {
    $composer = json_encode(['require-dev' => ['laravel/framework' => '^13.0']], JSON_THROW_ON_ERROR);
    [$packageConfigurator, $packageInstance] = orb170_source_configurator(
        ProjectType::LaravelPackage,
        $composer,
        '10.44.0.10',
    );
    [$appConfigurator, $instance] = orb170_source_configurator(
        ProjectType::LaravelApp,
        $composer,
        '10.44.0.11',
    );

    expect($packageConfigurator->inspect($packageInstance)->laravel)->toBeFalse()
        ->and(fn () => $appConfigurator->inspect($instance))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.laravel_source_invalid');
        });
});

it('preserves Laravel marker detection for every non-package project type', function (ProjectType $type): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);
    $composer = '{"require":{"laravel/framework":"^13.0"}}';

    expect($classifier->classify($composer, $type, 'regular')->laravel)->toBeTrue()
        ->and(fn () => $classifier->classify($composer, $type, 'absent'))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)
            ->toBe('app-dev.laravel_source_invalid'));
})->with([ProjectType::Monorepo, ProjectType::NodePackage]);

it('classifies a Symfony app by bin/console and the framework bundle without marking it Laravel', function (): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);
    $composer = '{"require":{"php":"~8.4.0","symfony/framework-bundle":"^7.3"}}';

    expect($classifier->classify($composer, ProjectType::SymfonyApp, 'regular'))
        ->phpVersion->toBe('8.4')
        ->laravel->toBeFalse()
        ->and($classifier->classify('{"name":"acme/site"}', ProjectType::SymfonyApp, 'absent'))
        ->phpVersion->toBe('8.5')
        ->laravel->toBeFalse();
});

it('rejects inconsistent or unsafe Symfony markers', function (string $composer, string $entryPointKind): void {
    $classifier = new ComposerSourceClassifier(new InstancePhpVersionCatalog);

    expect(fn () => $classifier->classify($composer, ProjectType::SymfonyApp, $entryPointKind))
        ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)
            ->toBe('app-dev.symfony_source_invalid'));
})->with([
    'console without bundle' => ['{"require":{"php":"^8.4"}}', 'regular'],
    'bundle without console' => ['{"require":{"symfony/framework-bundle":"^7.3"}}', 'absent'],
    'symlinked console' => ['{"require":{"symfony/framework-bundle":"^7.3"}}', 'unsafe'],
    'Laravel source' => ['{"require":{"laravel/framework":"^13.0"}}', 'regular'],
]);

it('inspects bin/console for a Symfony app and artisan for a Laravel app', function (ProjectType $type, string $entryPoint, string $wireguardIp): void {
    [$configurator, $instance, $ssh] = orb170_source_configurator(
        $type,
        '{"require":{"symfony/framework-bundle":"^7.3"}}',
        $wireguardIp,
        'regular',
    );

    if ($type === ProjectType::SymfonyApp) {
        expect($configurator->inspect($instance))->laravel->toBeFalse();
    } else {
        expect(fn () => $configurator->inspect($instance))->toThrow(RuntimeConvergenceException::class);
    }

    expect($ssh->commands[0]->arguments)->toBe(['bash', '-seu', '--', '/home/orbit/checkout', 'orbit', $entryPoint]);
})->with([
    'Symfony' => [ProjectType::SymfonyApp, 'bin/console', '10.44.0.12'],
    'Laravel' => [ProjectType::LaravelApp, 'artisan', '10.44.0.13'],
]);

it('renders only the selected production PHP site with its recorded user home pool and socket', function (): void {
    $php = new DevelopmentSite(
        nodeId: 1,
        nodeAddress: '10.44.0.10',
        scope: 'app-instance-7',
        checkoutPath: '/home/orbit-app-3',
        documentRoot: 'current/public',
        phpVersion: '8.5',
        domain: 'app.example.test',
        environment: 'production',
        productionUser: 'orbit-app-3',
        productionHome: '/home/orbit-app-3',
    );
    $nonPhp = new DevelopmentSite(
        nodeId: 1,
        nodeAddress: '10.44.0.10',
        scope: 'app-instance-8',
        checkoutPath: '/home/orbit-app-4',
        documentRoot: 'public',
        phpVersion: null,
        domain: 'static.example.test',
        environment: 'production',
        productionUser: 'orbit-app-4',
        productionHome: '/home/orbit-app-4',
    );
    $selected = collect([$php, $nonPhp])
        ->filter(static fn (DevelopmentSite $site): bool => $site->phpVersion !== null)
        ->values();
    $configuration = new DevelopmentPhpFpmConfigRenderer()->render(
        $selected,
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );
    $caddy = new DevelopmentCaddyConfigRenderer()->render(collect([$php, $nonPhp]));

    expect($selected)
        ->toHaveCount(1)
        ->and($configuration)
        ->toContain(
            '[orbit-app-instance-7]',
            'user = orbit-app-3',
            'group = orbit-app-3',
            'listen = /run/php/orbit-app-instance-7.sock',
            'env[HOME] = /home/orbit-app-3',
        )
        ->not
        ->toContain('app-instance-8', 'composer install', 'artisan')
        ->and($caddy)
        ->toContain(
            'root * /home/orbit-app-3/current/public',
            'php_fastcgi unix//run/php/orbit-app-instance-7.sock',
            'resolve_root_symlink',
            'root * /home/orbit-app-4/public',
        );
});

/** @return array{RemoteDevelopmentInstanceConfigurator, Instance, AppDevFakeSshExecutor} */
function orb170_source_configurator(ProjectType $type, string $composer, string $wireguardIp, string $entryPointKind = 'absent'): array
{
    $node = Node::query()->create([
        'name' => 'source-classifier-'.Str::lower(Str::random(8)),
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
    $suffix = Str::lower(Str::random(8));
    $project = Project::query()->create([
        'name' => 'Acme '.$suffix,
        'slug' => 'acme-'.$suffix,
        'type' => $type,
        'repository_url' => 'https://example.test/acme-'.$suffix.'.git',
        'apps' => fixture_apps(null, $type),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'checkout_path' => '/home/orbit/checkout',
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'status' => 'source_resolved',
    ]);
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $accounts = new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(private readonly ManagedUserAccount $account) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "COMPOSER\t{$entryPointKind}\t".base64_encode($composer)."\n", '', 0, false),
    ]);
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

    return [
        new RemoteDevelopmentInstanceConfigurator(
            new DevelopmentSshExecutor($ssh, $keys, $knownHosts),
            $accounts,
            new ComposerSourceClassifier(new InstancePhpVersionCatalog),
        ),
        $instance,
        $ssh,
    ];
}
