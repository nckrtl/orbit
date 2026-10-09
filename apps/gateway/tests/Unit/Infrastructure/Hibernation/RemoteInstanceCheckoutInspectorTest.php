<?php

declare(strict_types=1);

use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\LocalRuntimeDependencies;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Hibernation\RemoteInstanceCheckoutInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\TestCase;

uses(TestCase::class);

it('classifies reconstructable vendor and node_modules from the remote checkout facts', function (): void {
    $stdout = implode("\n", [
        'composer_json=1',
        'composer_lock=1',
        'vendor_present=1',
        'vendor_symlink=0',
        'package_json=1',
        'node_modules_present=1',
        'node_modules_symlink=0',
        'javascript_lock=package-lock.json',
        'source_mtime=100',
        '',
    ]);
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, $stdout, '', 1, false)]);
    $inspector = checkout_inspector($ssh);
    $instance = checkout_instance();

    $state = $inspector->inspect($instance);

    expect($state->prunableVendor())
        ->toBeTrue()
        ->and($state->prunableNodeModules())
        ->toBeTrue()
        ->and($state->sourceTreeLastActivityUnix)
        ->toBe(100)
        ->and($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', '/home/orbit/apps/docs', '/home/orbit/apps/docs']);
});

it('prunes only reconstructable dependency directories and leaves lockfiles', function (): void {
    $ssh = new AppDevFakeSshExecutor([new CommandResult(0, '', '', 1, false)]);
    $inspector = checkout_inspector($ssh);
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: true,
        vendorPresent: true,
        vendorSymlink: false,
        packageJsonFile: true,
        javascriptLockFiles: ['package-lock.json'],
        nodeModulesPresent: true,
        nodeModulesSymlink: false,
    );

    $inspector->prune(checkout_instance(), $state);

    expect($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', '/home/orbit/apps/docs', 'vendor', 'node_modules']);
});

it('restores missing vendor with Composer and missing node_modules with frozen vp', function (): void {
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);
    $inspector = checkout_inspector($ssh, 1_800);
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: true,
        vendorPresent: false,
        vendorSymlink: false,
        packageJsonFile: true,
        javascriptLockFiles: ['package-lock.json'],
        nodeModulesPresent: false,
        nodeModulesSymlink: false,
    );

    $inspector->restore(checkout_instance(), $state);

    expect($ssh->commands[0]->arguments)
        ->toBe([
            'sudo',
            '-u',
            'orbit',
            '-H',
            'env',
            'COMPOSER_HOME=/opt/orbit/composer',
            '/usr/bin/composer',
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--working-dir=/home/orbit/apps/docs',
        ])
        ->and($ssh->commands[1]->arguments)
        ->toBe([
            'sudo',
            '-u',
            'orbit',
            '-H',
            'bash',
            '-seu',
            '--',
            '/home/orbit/apps/docs',
        ])
        ->and($ssh->commands[1]->input)
        ->toContain('vp install --frozen-lockfile')
        ->and($ssh->connections[0]->commandTimeout)
        ->toBe(1_800.0);
});

it('inspects and prunes only the application dependencies while tracking repository-wide edits', function (string $root, string $suffix): void {
    $repository = sys_get_temp_dir().'/orbit-hibernation-'.Str::uuid();
    $application = $repository.$suffix;
    mkdir($application, 0o700, true);
    foreach (['composer.json', 'composer.lock', 'package.json', 'pnpm-lock.yaml'] as $file) {
        file_put_contents($application.'/'.$file, '{}');
        touch($application.'/'.$file, 100);
    }
    mkdir($application.'/vendor');
    mkdir($application.'/node_modules');
    mkdir($repository.'/sibling');
    file_put_contents($repository.'/sibling/edited.php', 'edited');
    touch($repository.'/sibling/edited.php', 200);
    mkdir($repository.'/sibling/vendor');
    if ($suffix !== '') {
        mkdir($repository.'/vendor');
        mkdir($repository.'/node_modules');
    }
    $instance = checkout_instance();
    $instance->forceFill(['app_overrides' => fixture_app_overrides($root), 'checkout_path' => $repository]);
    $ssh = new AppDevFakeSshExecutor;
    $inspector = checkout_inspector($ssh);

    try {
        $inspector->inspect($instance);
        $command = $ssh->commands[0];
        $process = new Process(array_slice($command->arguments, 1));
        $process->setInput($command->input);
        $process->mustRun();
        $state = checkout_inspector(new AppDevFakeSshExecutor([
            new CommandResult(0, $process->getOutput(), '', 1, false),
        ]))->inspect($instance);

        expect($state->prunableVendor())->toBeTrue();
        expect($state->prunableNodeModules())->toBeTrue();
        expect($state->sourceTreeLastActivityUnix)->toBe(200);
        $inspector->prune($instance, $state);
        $command = $ssh->commands[1];
        $process = new Process(array_slice($command->arguments, 1));
        $process->setInput($command->input);
        $process->mustRun();

        expect(is_dir($application.'/vendor'))->toBeFalse();
        expect(is_dir($application.'/node_modules'))->toBeFalse();
        expect(is_dir($repository.'/sibling/vendor'))->toBeTrue();
        expect(is_file($application.'/composer.lock'))->toBeTrue();
        if ($suffix !== '') {
            expect(is_dir($repository.'/vendor'))->toBeTrue();
            expect(is_dir($repository.'/node_modules'))->toBeTrue();
        }

        $cold = LocalRuntimeDependencies::inspect(true, true, false, false, true, ['pnpm-lock.yaml'], false, false);
        $inspector->restore($instance, $cold);
        expect($ssh->commands[2]->arguments)->toContain('--working-dir='.$application);
        expect($ssh->commands[3]->arguments)->toContain($application);
    } finally {
        new Filesystem()->deleteDirectory($repository);
    }
})->with([
    'root public' => ['public', ''],
    'nested app' => ['apps/site/public', '/apps/site'],
]);

function checkout_inspector(AppDevFakeSshExecutor $ssh, int $timeout = 1_800): RemoteInstanceCheckoutInspector
{
    return new RemoteInstanceCheckoutInspector(
        ssh: $ssh,
        keys: new CheckoutInspectorKeyProvider,
        knownHosts: new CheckoutInspectorKnownHostsStore,
        accounts: new CheckoutInspectorAccounts,
        restoreTimeoutSeconds: $timeout,
    );
}

it('per-app hibernation prunes and restores both app dependency directories and protects whole-repository edits', function (): void {
    $repository = sys_get_temp_dir().'/orbit-per-app-hibernation-'.Str::uuid();
    $instance = checkout_instance();
    $instance->project->forceFill(['apps' => [
        ['name' => 'web', 'type' => 'laravel-app', 'path' => 'apps/web', 'web_root' => 'public'],
        ['name' => 'docs', 'type' => 'node-package', 'path' => 'apps/docs', 'web_root' => null],
    ]]);
    $instance->forceFill(['app_overrides' => [], 'checkout_path' => $repository]);
    foreach (['web', 'docs'] as $app) {
        mkdir($repository.'/apps/'.$app.'/vendor', 0700, true);
        mkdir($repository.'/apps/'.$app.'/node_modules');
        foreach (['composer.json', 'composer.lock', 'package.json', 'pnpm-lock.yaml'] as $file) {
            file_put_contents($repository.'/apps/'.$app.'/'.$file, '{}');
            touch($repository.'/apps/'.$app.'/'.$file, 100);
        }
    }
    mkdir($repository.'/vendor');
    file_put_contents($repository.'/vendor/unowned.txt', 'outside configured dependency directories');
    touch($repository.'/vendor/unowned.txt', 600);
    file_put_contents($repository.'/repository-edit.txt', 'outside all apps');
    touch($repository.'/repository-edit.txt', 500);
    $ssh = new AppDevFakeSshExecutor;
    $inspector = checkout_inspector($ssh);
    try {
        $inspector->inspect($instance);
        $inspectCommands = $ssh->commands;
        $inspect = static function (array $commands): array {
            return array_map(static function ($command): CommandResult {
                $process = new Process(array_slice($command->arguments, 1), input: $command->input);
                $process->mustRun();

                return new CommandResult(0, $process->getOutput(), '', 1, false);
            }, $commands);
        };
        $ssh = new AppDevFakeSshExecutor($inspect($inspectCommands));
        $inspector = checkout_inspector($ssh);
        $state = $inspector->inspect($instance);
        expect(array_keys($state->apps))->toBe(['docs', 'web'])
            ->and($state->sourceTreeLastActivityUnix)->toBe(600)->and($state->hasPrunable())->toBeTrue();
        $inspector->prune($instance, $state);
        foreach (array_slice($ssh->commands, 2) as $command) {
            new Process(array_slice($command->arguments, 1), input: $command->input)->mustRun();
        }
        foreach (['web', 'docs'] as $app) {
            expect(is_dir($repository.'/apps/'.$app.'/vendor'))->toBeFalse()
                ->and(is_dir($repository.'/apps/'.$app.'/node_modules'))->toBeFalse()
                ->and(is_file($repository.'/apps/'.$app.'/composer.lock'))->toBeTrue();
        }
        expect(is_dir($repository.'/vendor'))->toBeTrue();
        $ssh = new AppDevFakeSshExecutor($inspect($inspectCommands));
        $inspector = checkout_inspector($ssh);
        $state = $inspector->inspect($instance);
        expect($state->hasRestorable())->toBeTrue();
        $inspector->restore($instance, $state);
        foreach (['docs', 'web'] as $index => $app) {
            expect($ssh->commands[2 + $index * 2]->arguments)->toContain('--working-dir='.$repository.'/apps/'.$app)
                ->and($ssh->commands[3 + $index * 2]->arguments)->toContain($repository.'/apps/'.$app)
                ->and($ssh->commands[3 + $index * 2]->input)->toContain('vp install --frozen-lockfile');
        }
        expect(fn () => $inspector->prune($instance, LocalRuntimeDependencies::inspect(true, true, true, false, true, ['pnpm-lock.yaml'], true, false)))->toThrow(HibernationException::class);
    } finally {
        new Filesystem()->deleteDirectory($repository);
    }
});

function checkout_instance(): Instance
{
    $node = new Node([
        'name' => 'app-dev',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $node->setRelation('roles', collect([new NodeRole(['role' => RoleName::AppDev])]));
    $instance = new Instance([
        'app_overrides' => fixture_app_overrides('public'),
        'source_is_laravel' => true,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
    ]);
    $instance->setRelation('node', $node);
    $instance->setRelation('project', new Project(['name' => 'Docs', 'slug' => 'docs', 'type' => 'laravel-app', 'apps' => fixture_apps('public', 'laravel-app')]));

    return $instance;
}

final class CheckoutInspectorKeyProvider implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/orbit/ssh/id_ed25519';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class CheckoutInspectorKnownHostsStore implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

final class CheckoutInspectorAccounts implements ManagedUserAccountResolver
{
    public function resolve(Node $node): ManagedUserAccount
    {
        return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    }
}
