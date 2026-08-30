<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\Roles\NodeRoleOperatingSystemGuard;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('runs one fixed remote operating-system preflight through WireGuard as orbit', function (): void {
    $calls = [];
    $guard = new NodeRoleOperatingSystemGuard(
        ssh: new class($calls) implements SshExecutor {
            /** @param list<array{host: string, user: string, arguments: list<string>, input: string}> $calls */
            public function __construct(
                private array &$calls,
            ) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->calls[] = [
                    'host' => $connection->host,
                    'user' => $connection->user,
                    'arguments' => $command->arguments,
                    'input' => $command->input ?? '',
                ];

                return new CommandResult(0, '', '', 12, false);
            }
        },
        keys: node_role_guard_keys(),
        knownHosts: node_role_guard_known_hosts(),
    );

    $guard->assert(node_role_guard_node(), RoleName::Gateway);

    expect($calls)
        ->toHaveCount(1)
        ->and($calls[0]['host'])
        ->toBe('10.44.0.2')
        ->and($calls[0]['user'])
        ->toBe('nckrtl')
        ->and($calls[0]['arguments'])
        ->toBe([
            'bash',
            '-seu',
            '--',
            'gateway',
            '1',
            UbuntuRelease::unsupportedText(),
            'resolute',
        ])
        ->and($calls[0]['input'])
        ->toContain(
            'if ! [ -r /etc/os-release ]; then',
            'release_count=$1',
            'unsupported_text=$1',
            'supported_release=false',
        );
});

it('rejects nodes without a WireGuard address before SSH', function (): void {
    $calls = 0;
    $guard = new NodeRoleOperatingSystemGuard(
        ssh: new class($calls) implements SshExecutor {
            public function __construct(
                private int &$calls,
            ) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->calls++;

                return new CommandResult(0, '', '', 1, false);
            }
        },
        keys: node_role_guard_keys(),
        knownHosts: node_role_guard_known_hosts(),
    );
    $node = node_role_guard_node();
    $node->update(['wireguard_address' => null]);

    try {
        $guard->assert($node, RoleName::Vpn);

        throw new LogicException('Expected a missing WireGuard address to fail.');
    } catch (NodeRoleOperationException $exception) {
        expect($exception->step)
            ->toBe('operating-system')
            ->and($exception->errorCode)
            ->toBe('node_role.convergence_failed')
            ->and($exception->underlyingErrorCode)
            ->toBe('node_role.wireguard_missing')
            ->and($exception->getMessage())
            ->toBe('Node [guard-node] role [vpn] requires a WireGuard address.')
            ->and($exception->result)
            ->toBeNull();
    }
    expect($calls)->toBe(0);
});

it('accepts every supported remote Ubuntu release for the role matrix', function (
    RoleName $role,
    string $contents,
): void {
    $harness = new NodeRoleGuardHarness($contents);
    $guard = new NodeRoleOperatingSystemGuard(
        ssh: node_role_guard_fixture_ssh($harness),
        keys: node_role_guard_keys(),
        knownHosts: node_role_guard_known_hosts(),
    );

    try {
        $guard->assert(node_role_guard_node(), $role);
        expect($harness->calls)
            ->toHaveCount(1)
            ->and($harness->calls[0]['user'])
            ->toBe('nckrtl')
            ->and($harness->calls[0]['host'])
            ->toBe('10.44.0.2')
            ->and($harness->calls[0]['result']->succeeded())
            ->toBeTrue()
            ->and($harness->calls[0]['mutationMarkerExists'])
            ->toBeTrue();
    } finally {
        $harness->cleanup();
    }
})->with([
    'app-dev Resolute bare' => [RoleName::AppDev, "ID=ubuntu\nVERSION_CODENAME=resolute\n"],
    'gateway Resolute bare' => [RoleName::Gateway, "ID=ubuntu\nVERSION_CODENAME=resolute\n"],
    'gateway Resolute single quoted' => [RoleName::Gateway, "ID='ubuntu'\nVERSION_CODENAME='resolute'\n"],
    'gateway Resolute double quoted' => [RoleName::Gateway, "ID=\"ubuntu\"\nVERSION_CODENAME=\"resolute\"\n"],
    'gateway Resolute final line unterminated' => [RoleName::Gateway, "ID=ubuntu\nVERSION_CODENAME=resolute"],
    'vpn Resolute bare' => [RoleName::Vpn, "ID=ubuntu\nVERSION_CODENAME=resolute\n"],
    'app-prod Resolute bare' => [RoleName::AppProd, "ID=ubuntu\nVERSION_CODENAME=resolute\n"],
]);

it('rejects adversarial os-release input without executing payloads', function (string $contents): void {
    $payloadMarker = 'orbit-guard-payload-'.(string) Str::uuid();
    $harness = new NodeRoleGuardHarness(str_replace('__PAYLOAD_MARKER__', $payloadMarker, $contents));
    $guard = new NodeRoleOperatingSystemGuard(
        node_role_guard_fixture_ssh($harness),
        node_role_guard_keys(),
        node_role_guard_known_hosts(),
    );
    try {
        expect(fn () => $guard->assert(node_role_guard_node(), RoleName::Gateway))
            ->toThrow(NodeRoleOperationException::class);
        expect($harness->calls)
            ->toHaveCount(1)
            ->and($harness->calls[0]['result']->stdout)
            ->toBeEmpty()
            ->and($harness->calls[0]['result']->stderr)
            ->toBe(UbuntuRelease::unsupportedText()."\n")
            ->and($harness->calls[0]['mutationMarkerExists'])
            ->toBeFalse()
            ->and($harness->payloadMarkerExists())
            ->toBeFalse();
    } finally {
        $harness->cleanup();
    }
})->with([
    'duplicate ID supported then supported' => "ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute\n",
    'duplicate ID supported then unsupported' => "ID=ubuntu\nID=debian\nVERSION_CODENAME=resolute\n",
    'duplicate codename supported then supported' => "ID=ubuntu\nVERSION_CODENAME=resolute\nVERSION_CODENAME=resolute\n",
    'duplicate codename supported then unsupported' => "ID=ubuntu\nVERSION_CODENAME=resolute\nVERSION_CODENAME=unsupported\n",
    'missing codename value' => "ID=ubuntu\nVERSION_CODENAME=\n",
    'empty ID value' => "ID=\nVERSION_CODENAME=resolute\n",
    'mismatched ID quotes' => "ID=\"ubuntu'\nVERSION_CODENAME=resolute\n",
    'unclosed codename quote' => "ID=ubuntu\nVERSION_CODENAME='resolute\n",
    'command substitution' => "ID=ubuntu\nVERSION_CODENAME=\$(__PAYLOAD_MARKER__)\n",
    'backticks' => "ID=ubuntu\nVERSION_CODENAME=`touch __PAYLOAD_MARKER__`\n",
    'semicolon' => "ID=ubuntu\nVERSION_CODENAME=resolute;touch __PAYLOAD_MARKER__\n",
]);

it('rejects unsupported remote operating systems before a mutation marker with redacted results', function (
    RoleName $role,
    ?string $contents,
    string $message,
): void {
    $harness = new NodeRoleGuardHarness($contents);
    $guard = new NodeRoleOperatingSystemGuard(
        ssh: node_role_guard_fixture_ssh($harness),
        keys: node_role_guard_keys(),
        knownHosts: node_role_guard_known_hosts(),
    );

    try {
        try {
            $guard->assert(node_role_guard_node(), $role);

            throw new LogicException('Expected the remote operating system guard to reject the fixture.');
        } catch (NodeRoleOperationException $exception) {
            expect($exception->step)
                ->toBe('operating-system')
                ->and($exception->errorCode)
                ->toBe('node_role.convergence_failed')
                ->and($exception->underlyingErrorCode)
                ->toBe('node_role.operating_system_unsupported')
                ->and($exception->getMessage())
                ->toBe($message)
                ->and($exception->result)
                ->not
                ->toBeNull()
                ->and($exception->result?->stdout)
                ->toBeEmpty()
                ->and($exception->result?->stderr)
                ->toBeEmpty();
        }
        expect($harness->calls)
            ->toHaveCount(1)
            ->and($harness->calls[0]['result']->succeeded())
            ->toBeFalse()
            ->and($harness->calls[0]['mutationMarkerExists'])
            ->toBeFalse();
    } finally {
        $harness->cleanup();
    }
})->with([
    'missing remote file' => [
        RoleName::Gateway,
        null,
        'Node [guard-node] role [gateway]. Node operating system [unknown/unknown] is not supported.',
    ],
    'unsupported Ubuntu release rejected for VPN' => [
        RoleName::Vpn,
        "ID=ubuntu\nVERSION_CODENAME=unsupported\n",
        'Node [guard-node] role [vpn]. Node operating system [ubuntu/unsupported] is not supported.',
    ],
    'unsupported Ubuntu release rejected for app-dev' => [
        RoleName::AppDev,
        "ID=ubuntu\nVERSION_CODENAME=unsupported\n",
        'Node [guard-node] role [app-dev]. Node operating system [ubuntu/unsupported] is not supported.',
    ],
    'unsupported Ubuntu release rejected for gateway' => [
        RoleName::Gateway,
        "ID=ubuntu\nVERSION_CODENAME=unsupported\n",
        'Node [guard-node] role [gateway]. Node operating system [ubuntu/unsupported] is not supported.',
    ],
    'unsupported Ubuntu release rejected for app-prod' => [
        RoleName::AppProd,
        "ID=ubuntu\nVERSION_CODENAME=unsupported\n",
        'Node [guard-node] role [app-prod]. Node operating system [ubuntu/unsupported] is not supported.',
    ],
    'Debian rejected for app-dev' => [
        RoleName::AppDev,
        "ID=debian\nVERSION_CODENAME=resolute\n",
        'Node [guard-node] role [app-dev]. Node operating system [debian/resolute] is not supported.',
    ],
    'unknown release rejected for app-prod' => [
        RoleName::AppProd,
        "ID=ubuntu\nVERSION_CODENAME=unsupported\n",
        'Node [guard-node] role [app-prod]. Node operating system [ubuntu/unsupported] is not supported.',
    ],
    'malformed release rejected for app-dev' => [
        RoleName::AppDev,
        "ID=ubuntu\nVERSION_CODENAME='resolute extra'\n",
        'Node [guard-node] role [app-dev]. Node operating system [unknown/unknown] is not supported.',
    ],
    'mismatched ID quotes rejected for gateway' => [
        RoleName::Gateway,
        "ID=\"ubuntu'\nVERSION_CODENAME=resolute\n",
        'Node [guard-node] role [gateway]. Node operating system [unknown/unknown] is not supported.',
    ],
    'mismatched codename quotes rejected for gateway' => [
        RoleName::Gateway,
        "ID=ubuntu\nVERSION_CODENAME=\"resolute'\n",
        'Node [guard-node] role [gateway]. Node operating system [unknown/unknown] is not supported.',
    ],
]);

function node_role_guard_node(): Node
{
    return Node::query()->create([
        'name' => 'guard-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_address' => '10.44.0.2',
    ]);
}

function node_role_guard_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 TEST';
        }
    };
}

function node_role_guard_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

function node_role_guard_fixture_ssh(NodeRoleGuardHarness $harness): SshExecutor
{
    return new class($harness) implements SshExecutor {
        public function __construct(
            private NodeRoleGuardHarness $harness,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $process = new Process($command->arguments);
            $path = getenv('PATH');
            $script = str_replace('/etc/os-release', $this->harness->osReleasePath, $command->input ?? '');
            $process->setEnv([
                'PATH' => $path === false ? '' : $path,
                'ORBIT_MUTATION_MARKER' => $this->harness->mutationMarkerPath,
            ]);
            $process->setInput($script."\nprintf 'mutation-reached\n' > \"\$ORBIT_MUTATION_MARKER\"\n");
            $process->run();

            $result = new CommandResult(
                $process->getExitCode() ?? 1,
                $process->getOutput(),
                $process->getErrorOutput(),
                1,
                false,
            );
            $this->harness->calls[] = [
                'host' => $connection->host,
                'user' => $connection->user,
                'arguments' => $command->arguments,
                'result' => $result,
                'mutationMarkerExists' => is_file($this->harness->mutationMarkerPath),
            ];

            return $result;
        }
    };
}

/** @mago-expect lint:file-name Test-local harness keeps the real Bash fixture flow in one file. */
final class NodeRoleGuardHarness
{
    /** @var list<array{host: string, user: string, arguments: list<string>, result: CommandResult, mutationMarkerExists: bool}> */
    public array $calls = [];

    public readonly string $osReleasePath;

    public readonly string $mutationMarkerPath;

    public readonly string $payloadMarkerPath;

    public function __construct(?string $contents)
    {
        $this->osReleasePath = sys_get_temp_dir().'/orbit-node-role-os-release-'.(string) Str::uuid();
        $this->mutationMarkerPath = sys_get_temp_dir().'/orbit-node-role-mutation-marker-'.(string) Str::uuid();
        $this->payloadMarkerPath = sys_get_temp_dir().'/orbit-node-role-payload-marker-'.(string) Str::uuid();

        if ($contents !== null) {
            file_put_contents($this->osReleasePath, $contents);
        }
    }

    public function payloadMarkerExists(): bool
    {
        return is_file($this->payloadMarkerPath);
    }

    public function cleanup(): void
    {
        if (is_file($this->osReleasePath)) {
            unlink($this->osReleasePath);
        }

        if (is_file($this->mutationMarkerPath)) {
            unlink($this->mutationMarkerPath);
        }

        if (is_file($this->payloadMarkerPath)) {
            unlink($this->payloadMarkerPath);
        }
    }
}
