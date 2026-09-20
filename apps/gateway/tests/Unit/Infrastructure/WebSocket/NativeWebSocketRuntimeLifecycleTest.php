<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WebSocket\NativeWebSocketRuntimeLifecycle;
use App\Models\Node;
use App\Models\NodeRole;

it('installs prerequisites, then clones, installs and starts Reverb with the resolved config', function (): void {
    $commands = [];
    $lifecycle = websocket_runtime_lifecycle($commands);
    $node = websocket_runtime_node();
    $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
    $credentials = new WebSocketCredentials('app-id', 'app-key', 'app-secret', 'base64:'.base64_encode('k'));

    $lifecycle->converge($node, $assignment, $credentials);

    expect($commands)->toHaveCount(2);

    $prerequisites = $commands[0];
    expect($prerequisites->arguments)->toContain('caddy')
        ->and($prerequisites->arguments)->toContain('composer')
        ->and($prerequisites->arguments)->toContain('git')
        ->and($prerequisites->arguments)->toContain('php-curl')
        ->and($prerequisites->arguments)->toContain('php-xml');

    $runtime = $commands[1];

    expect($runtime->arguments)
        ->toContain('orbit-websocket')
        ->toContain('/opt/orbit/websocket')
        ->toContain('https://example.test/orbit-reverb.git')
        ->toContain('release-42')
        ->and($runtime->protectedInput)
        ->not
        ->toBeNull();

    $script = stream_get_contents($runtime->protectedInput->stream());

    expect($script)
        ->toContain('git clone')
        ->toContain('fetch --quiet origin "$ref"')
        ->toContain('composer install')
        ->toContain('systemd-analyze verify "$verify_directory/orbit-websocket.service"')
        ->toContain('systemctl enable --now orbit-websocket')
        ->not
        ->toContain('app-secret')
        ->not
        ->toContain('[PROTECTED]');
});

it('throws a NodeRoleOperationException when convergence fails', function (): void {
    $commands = [];
    $lifecycle = websocket_runtime_lifecycle($commands, failRuntime: true);
    $node = websocket_runtime_node();
    $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
    $credentials = new WebSocketCredentials('app-id', 'app-key', 'app-secret', 'base64:'.base64_encode('k'));

    expect(fn () => $lifecycle->converge($node, $assignment, $credentials))
        ->toThrow(NodeRoleOperationException::class);
});

it('stops and disables the unit, purging the install path only when asked', function (): void {
    $commands = [];
    $lifecycle = websocket_runtime_lifecycle($commands);
    $node = websocket_runtime_node();
    $assignment = new NodeRole(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);

    $lifecycle->remove($node, $assignment, true);

    expect($commands)->toHaveCount(1);
    expect($commands[0]->input)
        ->toContain('systemctl disable --now')
        ->toContain('rm -rf -- "$install_path"');
});

function websocket_runtime_node(): Node
{
    $node = new Node([
        'name' => 'websocket',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.9',
    ]);
    $node->exists = true;
    $node->id = 1;

    return $node;
}

function websocket_runtime_lifecycle(array &$commands, bool $failRuntime = false): NativeWebSocketRuntimeLifecycle
{
    return new NativeWebSocketRuntimeLifecycle(
        commands: new NodeRolePrerequisiteCommandFactory,
        ssh: new class($commands, $failRuntime) implements SshExecutor
        {
            public function __construct(private array &$commands, private bool $failRuntime) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->commands[] = $command;
                $isRuntime = $command->protectedInput !== null;

                return new CommandResult($isRuntime && $this->failRuntime ? 1 : 0, '', '', 1, false);
            }
        },
        keys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA test';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        accounts: new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit-websocket', 'orbit-websocket', '/home/orbit-websocket');
            }
        },
        repository: 'https://example.test/orbit-reverb.git',
        ref: 'release-42',
        installPath: '/opt/orbit/websocket',
        port: 8790,
    );
}
