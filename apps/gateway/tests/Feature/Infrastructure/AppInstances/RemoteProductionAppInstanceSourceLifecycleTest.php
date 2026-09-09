<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstancePhpVersionCatalog;
use App\Domain\AppInstances\ComposerSourceClassifier;
use App\Infrastructure\AppInstances\RemoteProductionAppInstanceSourceLifecycle;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

it('prepares the recorded user and home and resolves only the App default branch', function (): void {
    [$source, $ssh, $instance] = production_source_lifecycle([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "main\t".str_repeat('a', 40)."\n", '', 1, false),
        new CommandResult(0, "NONE\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    $source->prepareUser($instance);
    $source->prepareSource($instance, false);
    $resolution = $source->resolve($instance);
    $profile = $source->inspectProfile($instance);
    $source->prepareCaddyAccess($instance);

    expect($ssh->commands[0]->arguments)
        ->toBe(['bash', '-seu', '--', 'orbit-app-1', '/home/orbit-app-1'])
        ->and($ssh->commands[0]->input)
        ->toContain('test ! -e "$home"', 'sudo useradd', 'sudo install -d')
        ->and($ssh->commands[1]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'https://example.test/application.git',
            'orbit-app-1',
            '/home/orbit-app-1',
            '0',
        ])
        ->and($ssh->commands[1]->input)
        ->toContain('git clone --no-checkout --origin origin')
        ->not
        ->toContain('rm -rf')
        ->and($ssh->commands[2]->arguments)
        ->toBe(['bash', '-seu', '--', 'orbit-app-1', '/home/orbit-app-1', 'main'])
        ->and($resolution->branch)
        ->toBe('main')
        ->and($resolution->startingCommit)
        ->toBe(str_repeat('a', 40))
        ->and($ssh->commands[3]->input)
        ->toContain(
            'resolved=$(sudo -u "$user" -H realpath',
            'sudo -u "$user" -H find -P "$home"',
            'sudo -u "$user" -H test -e "$composer"',
            'sudo -u "$user" -H test -f "$artisan"',
            'sudo -u "$user" -H base64',
        )
        ->and($ssh->commands[4]->arguments)
        ->toBe(['bash', '-seu', '--', '/home/orbit-app-1', 'orbit-app-1', 'public'])
        ->and($ssh->commands[4]->input)
        ->toContain(
            'sudo -u "$user" -H realpath -m -- "$document_root"',
            'sudo find -P "$document_root_real" -type l',
            'sudo setfacl -m u:caddy:--x /home "$home"',
            'sudo setfacl -P -R -m u:caddy:r-X "$document_root_real"',
        )
        ->and($profile->phpVersion)
        ->toBeNull()
        ->and($profile->laravel)
        ->toBeFalse();
});

it('passes an explicit branch without changing production identity', function (): void {
    [$source, $ssh, $instance] = production_source_lifecycle([
        new CommandResult(0, "release\t".str_repeat('b', 40)."\n", '', 1, false),
    ], 'release');

    $resolution = $source->resolve($instance);

    expect($ssh->commands[0]->arguments)
        ->toBe(['bash', '-seu', '--', 'orbit-app-1', '/home/orbit-app-1', 'release'])
        ->and($resolution->branch)
        ->toBe('release')
        ->and($instance->name)
        ->toBe('matching-remote-name');
});

it('permits an unresolved root and revalidates complete ownership immediately before ACL mutation', function (): void {
    [$source, $ssh, $instance] = production_source_lifecycle([
        new CommandResult(0, '', '', 1, false),
    ]);
    $instance->update(['root' => 'current/public']);

    $source->prepareCaddyAccess($instance);

    $command = $ssh->commands[0];
    $userOwnership = strpos($command->input, 'sudo find -P "$home" -xdev ! -user "$user"');
    $groupOwnership = strpos($command->input, 'sudo find -P "$home" -xdev ! -group "$user"');
    $firstAclMutation = strpos($command->input, 'sudo setfacl -P -R -m u:caddy:--- "$home"');

    expect($command->arguments)
        ->toBe(['bash', '-seu', '--', '/home/orbit-app-1', 'orbit-app-1', 'current/public'])
        ->and($command->input)
        ->toContain(
            'document_root_real=$(sudo -u "$user" -H realpath -m -- "$document_root")',
            'if sudo -u "$user" -H test -e "$document_root" || sudo -u "$user" -H test -L "$document_root"; then',
            'if [ "$document_root_exists" = 1 ]; then',
        )
        ->and($userOwnership)
        ->toBeInt()
        ->toBeLessThan($firstAclMutation)
        ->and($groupOwnership)
        ->toBeInt()
        ->toBeLessThan($firstAclMutation)
        ->and($firstAclMutation)
        ->toBeInt();
});

/**
 * @param list<CommandResult> $results
 * @return array{RemoteProductionAppInstanceSourceLifecycle, AppDevFakeSshExecutor, AppInstance}
 */
function production_source_lifecycle(array $results, ?string $branch = null): array
{
    $ssh = new AppDevFakeSshExecutor($results);
    $executor = new AppProdSshExecutor(
        $ssh,
        new class implements SshKeyProvider {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
    $node = Node::query()->create([
        'name' => 'production-source',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.60',
        'wireguard_ip' => '10.44.0.60',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Application',
        'slug' => 'application',
        'repository_url' => 'https://example.test/application.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'matching-remote-name',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-1',
        'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1',
        'branch_override' => $branch,
    ]);

    return [
        new RemoteProductionAppInstanceSourceLifecycle(
            $executor,
            new ComposerSourceClassifier(new AppInstancePhpVersionCatalog),
        ),
        $ssh,
        $instance,
    ];
}
