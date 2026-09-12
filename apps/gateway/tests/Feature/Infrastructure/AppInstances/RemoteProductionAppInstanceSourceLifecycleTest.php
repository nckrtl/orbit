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
            '1',
            '0',
        ])
        ->and($ssh->commands[1]->input)
        ->toContain(
            'set -o pipefail',
            'unexpected_user=$(sudo find -P "$home" -xdev ! -user "$user" -print -quit)',
            'unexpected_group=$(sudo find -P "$home" -xdev ! -group "$user" -print -quit)',
            'if sudo -u "$user" -H test -e "$release/.git"',
            'config --null --get remote.origin.url | base64 --wrap=0',
            'expected=$(printf \'%s\\0\' "$repository" | base64 --wrap=0)',
            'state_root=/var/lib/orbit/app-instance-sources',
            'layout_marker="$state_directory/release-layout"',
            'expected_marker=$(printf \'%s\\0%s\\0%s\\0\'',
            'expected_layout=$(printf \'%s\\0%s\\0%s\\0%s\\0\'',
            'test "$(sudo stat -c %U:%G -- "$marker")" = root:root',
            'unexpected_entry=$(sudo find -P "$home" -mindepth 1 -maxdepth 1 -print -quit)',
            'install -d -m 0700 -- "$releases"',
            'install -m 0600 /dev/null "$environment"',
            'git clone --no-checkout --origin origin',
            'sudo mv -- "$temporary" "$marker"',
            'sudo mv -- "$layout_temporary" "$layout_marker"',
        )
        ->not
        ->toContain(
            'test -z "$(find',
            'rm -rf',
        )
        ->and($ssh->commands[2]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'https://example.test/application.git',
            'orbit-app-1',
            '/home/orbit-app-1',
            'main',
            '1',
            '0',
        ])
        ->and($resolution->branch)
        ->toBe('main')
        ->and($resolution->startingCommit)
        ->toBe(str_repeat('a', 40))
        ->and($ssh->commands[3]->input)
        ->toContain(
            'resolved=$(sudo -u "$user" -H realpath',
            'unexpected_user=$(sudo -u "$user" -H find -P "$release"',
            'unexpected_group=$(sudo -u "$user" -H find -P "$release"',
            'sudo -u "$user" -H test -e "$composer"',
            'sudo -u "$user" -H test -f "$artisan"',
            'sudo -u "$user" -H base64',
        )
        ->and($ssh->commands[4]->arguments)
        ->toBe(['bash', '-seu', '--', '/home/orbit-app-1', 'orbit-app-1', 'public'])
        ->and($ssh->commands[4]->input)
        ->toContain(
            'sudo -u "$user" -H realpath -m -- "$document_root"',
            'unexpected_symlink=$(sudo find -P "$document_root_real" -type l',
            'unexpected_user=$(sudo find -P "$home" -xdev ! -user "$user"',
            'unexpected_group=$(sudo find -P "$home" -xdev ! -group "$user"',
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
    $instance->update(['clone_candidate_id' => $instance->id]);

    $resolution = $source->resolve($instance);

    expect($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'https://example.test/application.git',
            'orbit-app-1',
            '/home/orbit-app-1',
            'release',
            '1',
            '1',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain(
            'release="$home/releases/initial"',
            'git -C "$release" checkout --quiet',
            'git -C "$release" branch --quiet',
            'ln -s ../../.env "$release_environment"',
            'test "$clone_target" = 1',
            'git -C "$release" diff --quiet -- .env',
            'realpath -e -- "$release_environment"',
            'test ! -e "$home/current"',
            'test ! -L "$home/current"',
        )
        ->and($resolution->branch)
        ->toBe('release')
        ->and($instance->name)
        ->toBe('matching-remote-name');
});

it('permits an unresolved root and revalidates complete ownership immediately before ACL mutation', function (): void {
    [$source, $ssh, $instance] = production_source_lifecycle([
        new CommandResult(0, '', '', 1, false),
    ]);
    $instance->update(['root' => 'public']);

    $source->prepareCaddyAccess($instance);

    $command = $ssh->commands[0];
    $userOwnership = strpos($command->input, 'sudo find -P "$home" -xdev ! -user "$user"');
    $groupOwnership = strpos($command->input, 'sudo find -P "$home" -xdev ! -group "$user"');
    $firstAclMutation = strpos($command->input, 'sudo setfacl -n -P -R -m u:caddy:--- "$home"');

    expect($command->arguments)
        ->toBe(['bash', '-seu', '--', '/home/orbit-app-1', 'orbit-app-1', 'public'])
        ->and($command->input)
        ->toContain(
            'document_root_real=$(sudo -u "$user" -H realpath -m -- "$document_root")',
            'if sudo -u "$user" -H test -e "$current" || sudo -u "$user" -H test -L "$current"; then',
            'case "$selected" in "$releases"/*)',
            'selected=$(sudo -u "$user" -H realpath -e -- "$initial")',
            'test "$selected" = "$initial"',
            'ancestor_paths+=("$ancestor")',
            'sudo setfacl -n -P -R -m u:caddy:--- "$home"',
            'sudo setfacl -m u:caddy:--x "$ancestor"',
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

it('requires a root-owned clone marker before adopting source on retry', function (): void {
    [$source, $ssh, $instance] = production_source_lifecycle([
        new CommandResult(0, '', '', 1, false),
    ]);

    $source->prepareSource($instance, true);

    $command = $ssh->commands[0];
    $markerGate = strpos($command->input, 'actual_marker=$(sudo base64 --wrap=0 -- "$marker")');
    $sourceInspection = strpos($command->input, 'git -C "$release" rev-parse --is-inside-work-tree');
    $clone = strpos($command->input, 'git clone --no-checkout --origin origin');
    $markerPublication = strpos($command->input, 'sudo mv -- "$temporary" "$marker"');

    expect($command->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'https://example.test/application.git',
            'orbit-app-1',
            '/home/orbit-app-1',
            '1',
            '1',
        ])
        ->and($markerGate)
        ->toBeInt()
        ->toBeLessThan($sourceInspection)
        ->and($sourceInspection)
        ->toBeInt()
        ->and($clone)
        ->toBeInt()
        ->toBeLessThan($markerPublication)
        ->and($markerPublication)
        ->toBeInt();
});

it('propagates every source safety enumeration failure before treating its output as clean', function (): void {
    [$source, $ssh, $instance] = production_source_lifecycle([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(0, "NONE\n", '', 1, false),
        new CommandResult(0, '', '', 1, false),
    ]);

    $source->prepareSource($instance, true);
    $source->inspectProfile($instance);
    $source->prepareCaddyAccess($instance);

    foreach ($ssh->commands as $command) {
        expect($command->input)->not->toContain('test -z "$(sudo find', 'test -z "$(sudo -u');
    }

    expect($ssh->commands[0]->input)
        ->toContain(
            'unexpected_user=$(sudo find',
            'unexpected_group=$(sudo find',
            'unexpected_entry=$(sudo find',
        )
        ->and($ssh->commands[1]->input)
        ->toContain(
            'unexpected_user=$(sudo -u "$user" -H find',
            'unexpected_group=$(sudo -u "$user" -H find',
        )
        ->and($ssh->commands[2]->input)
        ->toContain(
            'unexpected_symlink=$(sudo find',
            'unexpected_user=$(sudo find',
            'unexpected_group=$(sudo find',
        );
});

/**
 * @param  list<CommandResult>  $results
 * @return array{RemoteProductionAppInstanceSourceLifecycle, AppDevFakeSshExecutor, AppInstance}
 */
function production_source_lifecycle(array $results, ?string $branch = null): array
{
    $ssh = new AppDevFakeSshExecutor($results);
    $executor = new AppProdSshExecutor(
        $ssh,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 test';
            }
        },
        new class implements KnownHostsStore
        {
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
