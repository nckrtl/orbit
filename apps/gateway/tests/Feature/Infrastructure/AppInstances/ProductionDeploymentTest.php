<?php

declare(strict_types=1);

use App\Domain\AppInstances\Deployment\DeploymentEvent;
use App\Domain\AppInstances\Deployment\DeploymentOutputStream;
use App\Domain\AppInstances\Deployment\DeploymentPhase;
use App\Domain\AppInstances\Deployment\DeploymentRelease;
use App\Domain\AppInstances\Deployment\DeploymentRequest;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\RemoteProductionDeployment;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

it('prepares a fresh branch-pinned release without changing current', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "20260911-a1\t".str_repeat('a', 40)."\n", '', 1, false),
    ]);

    $release = $deployment->prepare($instance, 'release');

    expect($release->name)
        ->toBe('20260911-a1')
        ->and($release->path)
        ->toBe('/home/orbit-app-1/releases/20260911-a1')
        ->and($release->commit)
        ->toBe(str_repeat('a', 40))
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'bash',
            '-seu',
            '--',
            'https://example.test/deployment.git',
            'orbit-app-1',
            '/home/orbit-app-1',
            (string) $instance->id,
            'release',
            '20260911-a1',
            'public',
        ])
        ->and($ssh->commands[0]->input)
        ->toContain(
            'git clone --no-checkout --origin origin',
            'git -C "$release" fetch --prune -- origin',
            'show-ref --verify --quiet "$source_ref"',
            'checkout --detach "$source_ref"',
            'ln -s ../../.env "$release_environment"',
            'test ! -e "$release"',
            'unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)',
        )
        ->not->toContain('mv -Tf -- "$temporary" "$current"');
});

it('runs protected application input from the release with streaming controls', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, 'final-out', 'final-error', 12, false),
    ]);
    $events = [];
    $request = new DeploymentRequest(static function (DeploymentEvent $event) use (&$events): void {
        $events[] = $event;
    });
    $step = new DeploymentStep(
        'build',
        DeploymentPhase::BeforeActivation,
        'printf "private-command"',
        37,
    );

    $result = $deployment->executeStep(
        $instance,
        new DeploymentRelease('retained', '/home/orbit-app-1/releases/retained', str_repeat('b', 40)),
        $step,
        $request,
    );
    $command = $ssh->commands[0];
    ($command->output)(new ProcessOutput(ProcessOutputStream::Stdout, 'one'));
    ($command->output)(new ProcessOutput(ProcessOutputStream::Stderr, 'two'));

    expect($result->stdout)
        ->toBe('final-out')
        ->and($command->arguments)
        ->toBe(['bash', '-seu', '--', 'orbit-app-1', '/home/orbit-app-1', '/home/orbit-app-1/releases/retained'])
        ->and($command->timeout)
        ->toBe(37.0)
        ->and($command->protectedInput)
        ->toBeInstanceOf(ProtectedInput::class)
        ->and($command->input)
        ->toBeNull()
        ->and(implode("\0", $command->arguments))
        ->not->toContain('private-command')
        ->and(print_r($command, return: true))
        ->not->toContain('private-command')
        ->and(stream_get_contents($command->protectedInput->stream()))
        ->toContain(
            'setsid bash -eu -c \'umask 077; printf "%s\n" "$$" > "$3"; chmod 0644 -- "$3"; cd -- "$1"; exec bash -eu "$2"\'',
            'process_group=$(cat -- "$group_file")',
            'while [ ! -e "$completion_file" ] && kill -0 "$owner"',
            'touch -- "$completion_file"',
            'kill -TERM -- "-$process_group"',
            base64_encode('printf "private-command"'),
        )
        ->and(array_map(
            static fn (DeploymentEvent $event): array => [$event->step, $event->stream, $event->value],
            $events,
        ))
        ->toBe([
            ['build', DeploymentOutputStream::Stdout, 'one'],
            ['build', DeploymentOutputStream::Stderr, 'two'],
        ]);
});

it('publishes one validated release with an atomic current replacement', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "retained\t".str_repeat('b', 40)."\n", '', 1, false),
    ]);
    $release = new DeploymentRelease(
        'retained',
        '/home/orbit-app-1/releases/retained',
        str_repeat('b', 40),
    );

    $selected = $deployment->activate($instance, $release);

    expect($selected)
        ->toBe($release)
        ->and($ssh->commands[0]->input)
        ->toContain(
            'config --null --get remote.origin.url',
            'realpath -m -- "$release/$relative_root"',
            'unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)',
            'sudo setfacl -m u:caddy:--x "$release"',
            'sudo setfacl -P -R -m u:caddy:r-X "$selected_root"',
            'sudo find -P "$selected_root" -type d -exec setfacl -m d:u:caddy:r-x -- {} +',
            'ln -s "releases/$name" "$temporary"',
            'mv -Tf -- "$temporary" "$current"',
        );
});

it('inspects current and retained releases through owned source and root boundaries', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "initial\t".str_repeat('a', 40)."\n", '', 1, false),
        new CommandResult(0, "retained\t".str_repeat('b', 40)."\n", '', 1, false),
    ]);

    $selected = $deployment->selected($instance);
    $retained = $deployment->retained($instance, 'retained');

    expect($selected?->path)
        ->toBe('/home/orbit-app-1/releases/initial')
        ->and($retained->path)
        ->toBe('/home/orbit-app-1/releases/retained');

    foreach ($ssh->commands as $command) {
        expect($command->input)->toContain(
            'config --null --get remote.origin.url',
            'realpath -m -- "$release/$relative_root"',
            'unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)',
            'find -P "$release" -xdev ! -user "$user"',
            'realpath -e -- "$release_environment"',
        );
    }

    expect($ssh->commands[0]->input)
        ->toContain('case "$release" in "$releases"/*)')
        ->and($ssh->commands[1]->input)
        ->toContain('release="$releases/$name"');
});

it('reports no selection when current does not exist', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([
        new CommandResult(0, "NONE\n", '', 1, false),
    ]);

    expect($deployment->selected($instance))
        ->toBeNull()
        ->and($ssh->commands)
        ->toHaveCount(1);
});

it('rejects traversal before asking the remote host to inspect a release', function (): void {
    [$deployment, $ssh, $instance] = orb219_remote_deployment([]);

    try {
        $deployment->retained($instance, '../foreign');
        $this->fail('The unsafe retained release was accepted.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('rollback.release_invalid');
    }

    expect($ssh->commands)->toBe([]);
});

/**
 * @param  list<CommandResult>  $results
 * @return array{RemoteProductionDeployment, AppDevFakeSshExecutor, AppInstance}
 */
function orb219_remote_deployment(array $results): array
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
        'name' => 'deployment-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.219',
        'wireguard_ip' => '10.44.0.219',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Deployment',
        'slug' => 'deployment',
        'repository_url' => 'https://example.test/deployment.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-1/releases/initial',
        'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1',
        'root' => 'public',
        'branch' => 'main',
        'deployment_steps' => [],
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);

    return [
        new RemoteProductionDeployment($executor, static fn (): string => '20260911-a1'),
        $ssh,
        $instance,
    ];
}
