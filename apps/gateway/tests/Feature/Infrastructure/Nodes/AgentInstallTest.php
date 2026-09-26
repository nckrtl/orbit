<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeAgentRoleConverger;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

it('pins agent v0.2.0 assets and checksums from its release manifest', function (): void {
    expect(NodeAgentFootprint::Version)->toBe('0.2.0')
        ->and(NodeAgentFootprint::X8664Checksum)->toBe('ed3cb9978ef9e16683342cb11d5a3b3a4f54f6fc47b6ee0ec695090cef989b57')
        ->and(NodeAgentFootprint::Aarch64Checksum)->toBe('34389c2cb4424468e94f42dff1ee05c3a6b490f4286e56fea53dede796c406e3')
        ->and(NodeAgentFootprint::downloadUrl('x86_64'))
        ->toBe('https://github.com/nckrtl/orbit/releases/download/agent-v0.2.0/orbit-agent-0.2.0-linux-x86_64');
});

it('keeps the role converge going when the agent install fails', function (): void {
    Log::spy();
    $agent = new class implements NodeAgentRuntime
    {
        public function converge(Node $node): void
        {
            throw new RuntimeException('agent unavailable');
        }

        public function remove(Node $node): void
        {
            throw new RuntimeException('agent unavailable');
        }
    };
    $node = nodeAgentNode();
    $node->status = 'active';
    $node->platform = 'linux';
    $node->ssh_host_fingerprint = 'SHA256:'.str_repeat('A', 43);

    expect(fn () => (new NodeAgentRoleConverger($agent, new ManagedNodeEligibility))->converge($node))
        ->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')->once();
});

it('skips the download when the installed checksum matches', function (): void {
    $ssh = new AgentInstallSsh(NodeAgentFootprint::X8664Checksum);
    $agent = nodeAgentExecutor($ssh);

    $agent->converge(nodeAgentNode());

    expect(array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands))
        ->not->toContain([
            'sudo', 'curl', '--fail', '--location', '--silent', '--show-error',
            '--output', '/usr/local/bin/orbit-agent.orbit-candidate', '--', NodeAgentFootprint::downloadUrl('x86_64'),
        ]);
});

it('bounds the binary download inside the lock term', function (): void {
    $ssh = new AgentInstallSsh(null);
    nodeAgentExecutor($ssh)->converge(nodeAgentNode());

    $downloadIndex = array_search(
        ['sudo', 'curl', '--fail', '--location', '--silent', '--show-error', '--connect-timeout', '20', '--max-time', '120', '--output', '/usr/local/bin/orbit-agent.orbit-candidate', '--', NodeAgentFootprint::downloadUrl('x86_64')],
        array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands),
        true,
    );

    expect($downloadIndex)->toBeInt();
});

it('leaves the running agent alone on an unchanged converge', function (): void {
    $ssh = new AgentInstallStatefulSsh;
    $agent = nodeAgentExecutor($ssh);

    $agent->converge(nodeAgentNode());
    $ssh->commands = [];
    $ssh->connections = [];
    $agent->converge(nodeAgentNode());

    $arguments = array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands);

    expect($arguments)
        ->not->toContain(['sudo', 'curl', '--fail', '--location', '--silent', '--show-error', '--output', '/usr/local/bin/orbit-agent.orbit-candidate', '--', NodeAgentFootprint::downloadUrl('x86_64')])
        ->not->toContain(['sudo', 'systemctl', 'restart', 'orbit-agent'])
        ->toContain(['sudo', 'systemctl', 'enable', '--now', 'orbit-agent']);
});

it('writes the Gateway address into the agent configuration and restarts after a change', function (): void {
    $ssh = new AgentInstallSsh(null);
    $agent = nodeAgentExecutor($ssh);

    $agent->converge(nodeAgentNode());

    $writeCommands = array_values(array_filter(
        $ssh->commands,
        static fn (RemoteCommand $command): bool => $command->protectedInput !== null,
    ));
    $contents = array_map(static function (RemoteCommand $command): string {
        $stream = $command->protectedInput->stream();

        return stream_get_contents($stream) ?: '';
    }, $writeCommands);
    $arguments = array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands);

    expect($contents)
        ->toContain('gateway_url = "https://gateway.orbit"'."\n".'gateway_address = "10.44.0.1"'."\n")
        ->and(implode("\n", $contents))
        ->toContain(NodeAgentFootprint::Marker, 'Restart=always', 'RestartSec=2', 'CapabilityBoundingSet=', 'NoNewPrivileges=yes', 'ProtectSystem=strict', 'ProtectHome=tmpfs', 'BindReadOnlyPaths=-/home/orbit/apps', 'PrivateTmp=yes', 'MemoryMax=128M', 'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6', '-----BEGIN CERTIFICATE-----');

    expect($arguments)->toContain(
        ['sudo', 'systemctl', 'enable', '--now', 'orbit-agent'],
        ['sudo', 'systemctl', 'restart', 'orbit-agent'],
    );
});

function agent_unit_text(AgentInstallSsh $ssh): string
{
    return implode("\n", array_map(static fn (RemoteCommand $command): string => $command->protectedInput === null ? '' : (stream_get_contents($command->protectedInput->stream()) ?: ''), $ssh->commands));
}

it('binds back only the Instance root under an empty home', function (?ManagedUserAccount $account, array $settings, array $present, array $absent): void {
    $ssh = new AgentInstallSsh(null);
    $node = nodeAgentNode();
    $node->settings = $settings;

    nodeAgentExecutor($ssh, $account)->converge($node);

    expect(agent_unit_text($ssh))->toContain('CapabilityBoundingSet=', ...$present)
        ->not->toContain(...$absent);
})->with([
    'default root' => [new ManagedUserAccount('deploy', 'deploy', '/home/deploy'), [], ['ProtectHome=tmpfs', 'BindReadOnlyPaths=-/home/deploy/apps'], ['ProtectHome=read-only', 'ProtectHome=yes', 'SupplementaryGroups=']],
    'unsafe home' => [new ManagedUserAccount('orbit', 'orbit', '/home/orbit dir'), [], ['ProtectHome=yes'], ['BindReadOnlyPaths=']],
    'unknown account' => [null, [], ['ProtectHome=yes'], ['SupplementaryGroups=', 'BindReadOnlyPaths=']],
]);

it('writes the Gateway address while the gateway role itself converges', function (): void {
    $ssh = new AgentInstallSsh(null);
    $agent = nodeAgentExecutor($ssh);
    // `node:role:add gateway gateway --converge` marks the assignment provisioning while it runs.
    Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway))
        ->firstOrFail()->roles()->where('role', RoleName::Gateway)->update(['status' => LifecycleStatus::Provisioning]);

    $agent->converge(nodeAgentNode());

    $contents = array_map(static function (RemoteCommand $command): string {
        if ($command->protectedInput === null) {
            return '';
        }

        return stream_get_contents($command->protectedInput->stream()) ?: '';
    }, $ssh->commands);

    expect($contents)->toContain('gateway_url = "https://gateway.orbit"'."\n".'gateway_address = "10.44.0.1"'."\n");
});

it('restarts the agent when the Gateway address changes', function (): void {
    $ssh = new AgentInstallStatefulSsh;
    $agent = nodeAgentExecutor($ssh);
    $agent->converge(nodeAgentNode());

    Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway))
        ->firstOrFail()->update(['wireguard_ip' => '10.44.0.2']);
    $ssh->commands = [];
    $agent->converge(nodeAgentNode());

    $contents = array_map(static function (RemoteCommand $command): string {
        if ($command->protectedInput === null) {
            return '';
        }

        $stream = $command->protectedInput->stream();

        return stream_get_contents($stream) ?: '';
    }, $ssh->commands);

    expect($contents)->toContain('gateway_url = "https://gateway.orbit"'."\n".'gateway_address = "10.44.0.2"'."\n")
        ->and(array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands))
        ->toContain(['sudo', 'systemctl', 'restart', 'orbit-agent']);
});

it('stops, disables, and deletes all agent files during removal', function (): void {
    $ssh = new AgentInstallSsh(null, unitExists: true);

    nodeAgentExecutor($ssh)->remove(nodeAgentNode());

    expect(array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands))
        ->toBe([
            ['sudo', 'test', '-f', '/etc/systemd/system/orbit-agent.service'],
            ['sudo', 'systemctl', 'stop', 'orbit-agent'],
            ['sudo', 'systemctl', 'disable', 'orbit-agent'],
            ['sudo', 'rm', '-f', '--', '/etc/systemd/system/orbit-agent.service', '/usr/local/bin/orbit-agent'],
            ['sudo', 'rm', '-rf', '--', '/etc/orbit/agent'],
            ['sudo', 'systemctl', 'daemon-reload'],
            ['sudo', 'systemctl', 'reset-failed', 'orbit-agent'],
        ]);
});

it('completes agent removal when the unit has no failed record to reset', function (): void {
    $ssh = new class implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return $command->arguments === ['sudo', 'systemctl', 'reset-failed', 'orbit-agent']
                ? new CommandResult(1, '', 'Failed to reset failed state of unit orbit-agent.service: Unit orbit-agent.service not loaded.', 1, false)
                : new CommandResult(0, '', '', 1, false);
        }
    };

    nodeAgentExecutor($ssh)->remove(nodeAgentNode());

    expect(array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands))
        ->toContain(['sudo', 'systemctl', 'reset-failed', 'orbit-agent']);
});

it('removes the files when the agent unit is missing', function (): void {
    $ssh = new AgentInstallSsh(null);

    nodeAgentExecutor($ssh)->remove(nodeAgentNode());

    $arguments = array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands);

    expect($arguments)
        ->toContain(
            ['sudo', 'rm', '-f', '--', '/etc/systemd/system/orbit-agent.service', '/usr/local/bin/orbit-agent'],
            ['sudo', 'rm', '-rf', '--', '/etc/orbit/agent'],
        )
        ->not->toContain(
            ['sudo', 'systemctl', 'stop', 'orbit-agent'],
            ['sudo', 'systemctl', 'disable', 'orbit-agent'],
        );
});

it('removes a partially installed binary when no agent unit exists', function (): void {
    $ssh = new AgentInstallSsh(null);

    nodeAgentExecutor($ssh)->remove(nodeAgentNode());

    expect(array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands))
        ->toContain(['sudo', 'rm', '-f', '--', '/etc/systemd/system/orbit-agent.service', '/usr/local/bin/orbit-agent']);
});

it('fails agent removal when a remote cleanup command fails', function (): void {
    $ssh = new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            return new CommandResult(1, '', '', 1, false);
        }
    };

    expect(fn () => nodeAgentExecutor($ssh)->remove(nodeAgentNode()))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'agent.remove_failed');
});

it('fails with agent.checksum_mismatch', function (): void {
    $ssh = new AgentInstallSsh(null, '0000000000000000000000000000000000000000000000000000000000000000');
    $agent = nodeAgentExecutor($ssh);

    try {
        $agent->converge(nodeAgentNode());
        test()->fail('Expected checksum verification to fail.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('agent.checksum_mismatch');
    }

    expect(array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands))
        ->toContain(['sudo', 'rm', '-f', '--', '/usr/local/bin/orbit-agent.orbit-candidate']);
});

function nodeAgentStoredNode(): Node
{
    return Node::query()->forceCreate([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.4',
        'user' => 'orbit',
        'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.4',
        'agent_secret_exempt' => true,
    ]);
}

/** @return list<list<string>> */
function nodeAgentArguments(object $ssh): array
{
    return array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands);
}

describe('the agent secret', function (): void {
    it('keeps an agent that sends no secret exempt and writes no secret', function (): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        $node->forceFill(['agent_secret_hash' => str_repeat('c', 64), 'agent_secret_exempt' => false])->save();

        nodeAgentExecutor($ssh)->converge($node);

        expect(NodeAgentFootprint::sendsSecret())->toBeFalse()
            ->and($ssh->file(NodeAgentFootprint::SecretPath))->toBeNull()
            ->and($node->fresh()?->agent_secret_exempt)->toBeTrue()
            ->and($node->fresh()?->agent_secret_hash)->toBeNull();
    });

    it('writes a root-only secret through stdin and stores only its hash', function (): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();

        nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince)->converge($node);

        $secret = (string) $ssh->file(NodeAgentFootprint::SecretPath);
        $fresh = $node->fresh();

        $arguments = nodeAgentArguments($ssh);
        $directory = array_search(['sudo', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0700', '/etc/orbit/agent'], $arguments, true);
        $write = array_search(['sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0600', '/dev/stdin', NodeAgentFootprint::SecretPath.'.orbit-candidate'], $arguments, true);

        expect($directory)->toBeInt()->toBeLessThan($write);
        expect($secret)->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($ssh->modes[NodeAgentFootprint::SecretPath])->toBe('-o root -g root -m 0600')
            ->and($fresh?->agent_secret_hash)->toBe(hash('sha256', $secret))
            ->and($fresh?->agent_secret_exempt)->toBeFalse()
            ->and(json_encode(nodeAgentArguments($ssh)))->not->toContain($secret)
            ->and(nodeAgentArguments($ssh))
            ->toContain(['sudo', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0700', '/etc/orbit/agent'])
            ->toContain(['sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0600', '/dev/stdin', NodeAgentFootprint::SecretPath.'.orbit-candidate'])
            ->toContain(['sudo', 'systemctl', 'restart', 'orbit-agent'])
            ->not->toContain(['sudo', 'cat', '--', NodeAgentFootprint::SecretPath]);
    });

    it('keeps a matching secret and leaves the running agent alone', function (): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        $agent = nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince);
        $agent->converge($node);
        $secret = $ssh->file(NodeAgentFootprint::SecretPath);
        $ssh->commands = [];

        $agent->converge($node->fresh() ?? $node);

        expect($ssh->file(NodeAgentFootprint::SecretPath))->toBe($secret)
            ->and($node->fresh()?->agent_secret_hash)->toBe(hash('sha256', (string) $secret))
            ->and(nodeAgentArguments($ssh))
            ->toContain(['sudo', 'sha256sum', '--', NodeAgentFootprint::SecretPath])
            ->not->toContain(['sudo', 'systemctl', 'restart', 'orbit-agent']);
    });

    it('writes a new secret when the file is missing or differs', function (?string $contents): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        $agent = nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince);
        $agent->converge($node);
        $first = $ssh->file(NodeAgentFootprint::SecretPath);
        $ssh->putFile(NodeAgentFootprint::SecretPath, $contents);
        $ssh->commands = [];

        $agent->converge($node->fresh() ?? $node);
        $second = (string) $ssh->file(NodeAgentFootprint::SecretPath);

        expect($second)->not->toBe($first)->not->toBe($contents)
            ->and($node->fresh()?->agent_secret_hash)->toBe(hash('sha256', $second))
            ->and(nodeAgentArguments($ssh))->toContain(['sudo', 'systemctl', 'restart', 'orbit-agent']);
    })->with([
        'missing' => [null],
        'edited' => ['edited-by-hand'],
    ]);

    it('writes the secret file before it swaps the binary, and keeps the Node exempt when the download fails', function (): void {
        $ssh = new AgentInstallStatefulSsh;
        $ssh->fails = static fn (array $arguments): bool => ($arguments[1] ?? null) === 'curl';
        $node = nodeAgentStoredNode();

        expect(fn () => nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince)->converge($node))
            ->toThrow(ResourceOperationException::class);

        $arguments = nodeAgentArguments($ssh);
        $secret = array_search(['sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0600', '/dev/stdin', NodeAgentFootprint::SecretPath.'.orbit-candidate'], $arguments, true);
        $download = array_key_first(array_filter($arguments, static fn (array $command): bool => ($command[1] ?? null) === 'curl'));

        expect($secret)->toBeInt()->toBeLessThan($download)
            ->and($ssh->file(NodeAgentFootprint::SecretPath))->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($node->fresh()?->agent_secret_exempt)->toBeTrue()
            ->and($node->fresh()?->agent_secret_hash)->toBeNull()
            ->and($arguments)->not->toContain(['sudo', 'systemctl', 'restart', 'orbit-agent']);
    });

    it('keeps the Node exempt when the agent fails to restart after the secret file is written', function (string $failing): void {
        $ssh = new AgentInstallStatefulSsh;
        $ssh->fails = static fn (array $arguments): bool => ($arguments[1] ?? null) === 'systemctl' && ($arguments[2] ?? null) === $failing;
        $node = nodeAgentStoredNode();

        expect(fn () => nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince)->converge($node))
            ->toThrow(ResourceOperationException::class);

        expect($ssh->file(NodeAgentFootprint::SecretPath))->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($node->fresh()?->agent_secret_exempt)->toBeTrue()
            ->and($node->fresh()?->agent_secret_hash)->toBeNull();

        $ssh->fails = null;
        nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince)->converge($node->fresh() ?? $node);

        expect($node->fresh()?->agent_secret_exempt)->toBeFalse()
            ->and($node->fresh()?->agent_secret_hash)->toBe(hash('sha256', (string) $ssh->file(NodeAgentFootprint::SecretPath)));
    })->with(['daemon-reload', 'enable', 'restart']);

    it('ends the exemption only after the agent that sends the secret restarted', function (): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        $atRestart = null;
        $ssh->before = static function (array $arguments) use (&$atRestart, $node): void {
            if ($arguments === ['sudo', 'systemctl', 'restart', 'orbit-agent']) {
                $atRestart = Node::query()->whereKey($node->getKey())->first(['agent_secret_hash', 'agent_secret_exempt'])?->only(['agent_secret_hash', 'agent_secret_exempt']);
            }
        };

        nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince)->converge($node);

        expect($atRestart)->toBe(['agent_secret_hash' => null, 'agent_secret_exempt' => true])
            ->and($node->fresh()?->agent_secret_exempt)->toBeFalse();
    });

    it('stores a replacement hash only after the restart, so a failed rotation shows as a mismatch the next converge repairs', function (string $failing): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        $agent = nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince);
        $agent->converge($node);
        $old = $node->fresh()?->agent_secret_hash;
        $ssh->putFile(NodeAgentFootprint::SecretPath, null);
        $atRestart = null;
        $ssh->before = static function (array $arguments) use (&$atRestart, $node): void {
            if ($arguments === ['sudo', 'systemctl', 'restart', 'orbit-agent']) {
                $atRestart = Node::query()->whereKey($node->getKey())->value('agent_secret_hash');
            }
        };
        $ssh->fails = static fn (array $arguments): bool => ($arguments[1] ?? null) === 'systemctl' && ($arguments[2] ?? null) === $failing;

        expect(fn () => $agent->converge($node->fresh() ?? $node))->toThrow(ResourceOperationException::class);

        $written = hash('sha256', (string) $ssh->file(NodeAgentFootprint::SecretPath));

        expect($atRestart)->toBeIn([null, $old])
            ->and($node->fresh()?->agent_secret_hash)->toBe($old)->not->toBe($written)
            ->and($node->fresh()?->agent_secret_exempt)->toBeFalse();

        $ssh->fails = null;
        $ssh->before = null;
        $ssh->commands = [];
        $agent->converge($node->fresh() ?? $node);

        expect($node->fresh()?->agent_secret_hash)->toBe(hash('sha256', (string) $ssh->file(NodeAgentFootprint::SecretPath)))
            ->not->toBe($old)
            ->and(nodeAgentArguments($ssh))->toContain(['sudo', 'systemctl', 'restart', 'orbit-agent']);
    })->with(['daemon-reload', 'enable', 'restart']);

    it('stores the replacement hash only once the restart succeeded', function (): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        $agent = nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince);
        $agent->converge($node);
        $old = $node->fresh()?->agent_secret_hash;
        $ssh->putFile(NodeAgentFootprint::SecretPath, 'edited-by-hand');
        $atRestart = null;
        $ssh->before = static function (array $arguments) use (&$atRestart, $node): void {
            if ($arguments === ['sudo', 'systemctl', 'restart', 'orbit-agent']) {
                $atRestart = Node::query()->whereKey($node->getKey())->value('agent_secret_hash');
            }
        };

        $agent->converge($node->fresh() ?? $node);

        expect($atRestart)->toBe($old)
            ->and($node->fresh()?->agent_secret_hash)->toBe(hash('sha256', (string) $ssh->file(NodeAgentFootprint::SecretPath)));
    });

    it('enters the exemption before it restarts into an agent that sends no secret', function (?string $failing): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince)->converge($node);
        $ssh->putChecksum(NodeAgentFootprint::BinaryPath, str_repeat('d', 64));
        $atRestart = null;
        $ssh->before = static function (array $arguments) use (&$atRestart, $node): void {
            if ($arguments === ['sudo', 'systemctl', 'restart', 'orbit-agent']) {
                $atRestart = Node::query()->whereKey($node->getKey())->first(['agent_secret_hash', 'agent_secret_exempt'])?->only(['agent_secret_hash', 'agent_secret_exempt']);
            }
        };
        $ssh->fails = $failing === null ? null : static fn (array $arguments): bool => $arguments === ['sudo', 'systemctl', $failing, 'orbit-agent'];

        try {
            nodeAgentExecutor($ssh)->converge($node->fresh() ?? $node);
        } catch (ResourceOperationException) {
            expect($failing)->not->toBeNull();
        }

        expect($atRestart)->toBe(['agent_secret_hash' => null, 'agent_secret_exempt' => true])
            ->and($node->fresh()?->agent_secret_exempt)->toBeTrue()
            ->and($node->fresh()?->agent_secret_hash)->toBeNull();
    })->with(['succeeds' => [null], 'restart fails' => ['restart']]);

    it('keeps the stored hash when an agent that sends no secret fails to install', function (): void {
        $ssh = new AgentInstallStatefulSsh;
        $ssh->fails = static fn (array $arguments): bool => ($arguments[1] ?? null) === 'curl';
        $node = nodeAgentStoredNode();
        $node->forceFill(['agent_secret_hash' => str_repeat('c', 64), 'agent_secret_exempt' => false])->save();

        expect(fn () => nodeAgentExecutor($ssh)->converge($node))->toThrow(ResourceOperationException::class);

        expect($node->fresh()?->agent_secret_hash)->toBe(str_repeat('c', 64))
            ->and($node->fresh()?->agent_secret_exempt)->toBeFalse();
    });

    it('enters the exemption before it swaps in an agent that sends no secret', function (): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince)->converge($node);
        $ssh->putChecksum(NodeAgentFootprint::BinaryPath, str_repeat('d', 64));
        $atSwap = null;
        $ssh->before = static function (array $arguments) use (&$atSwap, $node): void {
            if ($arguments === ['sudo', 'mv', '-fT', '--', NodeAgentFootprint::BinaryPath.'.orbit-candidate', NodeAgentFootprint::BinaryPath]) {
                $atSwap = Node::query()->whereKey($node->getKey())->first(['agent_secret_hash', 'agent_secret_exempt'])?->only(['agent_secret_hash', 'agent_secret_exempt']);
            }
        };
        $ssh->fails = static fn (array $arguments): bool => $arguments === ['sudo', 'systemctl', 'daemon-reload'];

        expect(fn () => nodeAgentExecutor($ssh)->converge($node->fresh() ?? $node))->toThrow(ResourceOperationException::class);

        expect($atSwap)->toBe(['agent_secret_hash' => null, 'agent_secret_exempt' => true])
            ->and($node->fresh()?->agent_secret_exempt)->toBeTrue();
    });

    it('stops without touching the record when another converge took over its expired lock', function (string $step): void {
        $ssh = new AgentInstallStatefulSsh;
        $node = nodeAgentStoredNode();
        $name = 'node-agent:id:'.$node->getKey();
        $ssh->before = static function (array $arguments) use ($step, $name): void {
            if (($arguments[1] ?? null) === $step) {
                app(NodeLocks::class)->lock($name, 1)->forceRelease();
                app(NodeLocks::class)->lock($name, 60)->get();
            }
        };

        try {
            nodeAgentExecutor($ssh, version: NodeAgentFootprint::SecretSince)->converge($node);
            test()->fail('Expected the converge to stop after it lost its lock.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('agent.converge_lock_lost');
        } finally {
            app(NodeLocks::class)->lock($name, 1)->forceRelease();
        }

        expect($node->fresh()?->agent_secret_exempt)->toBeTrue()
            ->and($node->fresh()?->agent_secret_hash)->toBeNull()
            ->and(nodeAgentArguments($ssh))->not->toContain(['sudo', 'systemctl', 'restart', 'orbit-agent']);
    })->with(['install', 'curl', 'mv']);

    it('serializes converges of the same Node', function (): void {
        $node = nodeAgentStoredNode();
        $lock = app(NodeLocks::class)->lock('node-agent:id:'.$node->getKey(), 60);
        $lock->get();

        try {
            nodeAgentExecutor(new AgentInstallStatefulSsh, version: NodeAgentFootprint::SecretSince, lockWaitSeconds: 0)->converge($node);
            test()->fail('Expected the held lock to refuse the converge.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('agent.converge_busy');
        } finally {
            $lock->release();
        }

        $ssh = new AgentInstallStatefulSsh;
        $ssh->fails = static fn (array $arguments): bool => ($arguments[1] ?? null) === 'curl';
        expect(fn () => nodeAgentExecutor($ssh, lockWaitSeconds: 0)->converge($node))->toThrow(ResourceOperationException::class);

        nodeAgentExecutor(new AgentInstallStatefulSsh, lockWaitSeconds: 0)->converge($node);

        expect($node->fresh()?->agent_secret_exempt)->toBeTrue();
    });
});

it('keeps the Node locks in a file store under ORBIT_HOME, whatever CACHE_STORE says', function (): void {
    $home = sys_get_temp_dir().'/orbit-node-locks-'.bin2hex(random_bytes(4));
    config(['orbit.home' => $home, 'cache.default' => 'database']);
    app()->forgetInstance(NodeLocks::class);

    try {
        $lock = app(NodeLocks::class)->lock('node-agent:id:1', 30);

        expect($lock->get())->toBeTrue()
            ->and(glob($home.'/cache/node-locks/*/*/*') ?: [])->not->toBe([])
            ->and(app(NodeLocks::class)->lock('node-agent:id:1', 30)->get())->toBeFalse();

        $lock->release();
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});

function nodeAgentExecutor(SshExecutor $ssh, ?ManagedUserAccount $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit'), string $version = NodeAgentFootprint::Version, int $lockWaitSeconds = 120): NodeAgentSshExecutor
{
    if (! Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->exists()) {
        $gateway = Node::query()->create([
            'name' => 'agent-install-gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.1',
            'wireguard_ip' => '10.44.0.1',
            'user' => 'orbit',
        ]);
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    }

    return new NodeAgentSshExecutor(
        $ssh,
        new AgentInstallKeys,
        new AgentInstallKnownHosts,
        new AgentInstallCertificates,
        new readonly class($account) implements ManagedUserAccountResolver
        {
            public function __construct(private ?ManagedUserAccount $account) {}

            public function resolve(Node $node): ManagedUserAccount
            {
                return $this->account ?? throw new RuntimeException('getent failed');
            }
        },
        app(StorageRootResolver::class),
        app(NodeSettingsNormalizer::class),
        $version,
        app(NodeLocks::class),
        $lockWaitSeconds,
    );
}

function nodeAgentNode(): Node
{
    return new Node([
        'name' => 'app-prod',
        'user' => 'orbit',
        'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.4',
    ]);
}

final class AgentInstallSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    public function __construct(
        private ?string $installedChecksum,
        private string $candidateChecksum = NodeAgentFootprint::X8664Checksum,
        private bool $unitExists = false,
        public bool $runScriptsLocally = false,
        public ?CommandResult $scriptResult = null,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $this->connections[] = $connection;
        $arguments = $command->arguments;

        if (($arguments[1] ?? null) === 'sha256sum') {
            $path = $arguments[array_key_last($arguments)];
            $checksum = str_ends_with($path, '.orbit-candidate') ? $this->candidateChecksum : $this->installedChecksum;

            return $checksum === null
                ? new CommandResult(1, '', '', 1, false)
                : new CommandResult(0, $checksum.'  '.$path."\n", '', 1, false);
        }

        if (($arguments[0] ?? null) === 'bash' && $this->scriptResult instanceof CommandResult) {
            return $this->scriptResult;
        }

        if (($arguments[0] ?? null) === 'bash' && $this->runScriptsLocally) {
            $process = new Process($arguments);
            $process->setInput((string) $command->input);
            $process->run();

            return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
        }

        if (($arguments[1] ?? null) === 'test') {
            return $this->unitExists
                ? new CommandResult(0, '', '', 1, false)
                : new CommandResult(1, '', '', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}

final class AgentInstallStatefulSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, string> */
    private array $checksums = [];

    /** @var array<string, string> */
    public array $modes = [];

    /** @var (Closure(list<string>): bool)|null Fails each command it matches. */
    public ?Closure $fails = null;

    /** @var (Closure(list<string>): void)|null Runs before each command. */
    public ?Closure $before = null;

    public function file(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function putChecksum(string $path, string $checksum): void
    {
        $this->checksums[$path] = $checksum;
    }

    public function putFile(string $path, ?string $contents): void
    {
        if ($contents === null) {
            unset($this->files[$path]);

            return;
        }

        $this->files[$path] = $contents;
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $this->connections[] = $connection;
        $arguments = $command->arguments;

        if ($this->before instanceof Closure) {
            ($this->before)($arguments);
        }

        if ($this->fails instanceof Closure && ($this->fails)($arguments)) {
            return new CommandResult(1, '', 'failed', 1, false);
        }

        if (($arguments[1] ?? null) === 'sha256sum') {
            $path = $arguments[array_key_last($arguments)];
            $checksum = $this->checksums[$path] ?? (isset($this->files[$path]) ? hash('sha256', $this->files[$path]) : null);

            return $checksum === null
                ? new CommandResult(1, '', '', 1, false)
                : new CommandResult(0, $checksum.'  '.$path."\n", '', 1, false);
        }

        if (($arguments[1] ?? null) === 'curl') {
            $candidate = $arguments[array_search('--output', $arguments, true) + 1];
            $this->checksums[$candidate] = NodeAgentFootprint::X8664Checksum;

            return new CommandResult(0, '', '', 1, false);
        }

        if (($arguments[0] ?? null) === 'bash' && $this->scriptResult instanceof CommandResult) {
            return $this->scriptResult;
        }

        if (($arguments[0] ?? null) === 'bash' && $this->runScriptsLocally) {
            $process = new Process($arguments);
            $process->setInput((string) $command->input);
            $process->run();

            return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
        }

        if (($arguments[1] ?? null) === 'test') {
            return array_key_exists($arguments[array_key_last($arguments)], $this->files)
                ? new CommandResult(0, '', '', 1, false)
                : new CommandResult(1, '', '', 1, false);
        }

        if (($arguments[1] ?? null) === 'cat') {
            $path = $arguments[array_key_last($arguments)];

            return new CommandResult(0, $this->files[$path] ?? '', '', 1, false);
        }

        if (($arguments[1] ?? null) === 'install' && $command->protectedInput !== null) {
            $stream = $command->protectedInput->stream();
            $target = $arguments[array_key_last($arguments)];
            $this->files[$target] = stream_get_contents($stream) ?: '';
            $this->modes[$target] = implode(' ', array_slice($arguments, 3, 6));

            return new CommandResult(0, '', '', 1, false);
        }

        if (($arguments[1] ?? null) === 'mv') {
            $source = $arguments[count($arguments) - 2];
            $destination = $arguments[count($arguments) - 1];
            if (isset($this->files[$source])) {
                $this->files[$destination] = $this->files[$source];
                unset($this->files[$source]);
            }
            if (isset($this->checksums[$source])) {
                $this->checksums[$destination] = $this->checksums[$source];
                unset($this->checksums[$source]);
            }
            if (isset($this->modes[$source])) {
                $this->modes[$destination] = $this->modes[$source];
                unset($this->modes[$source]);
            }

            return new CommandResult(0, '', '', 1, false);
        }

        if (($arguments[1] ?? null) === 'rm') {
            $path = $arguments[array_key_last($arguments)];
            unset($this->files[$path], $this->checksums[$path]);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}

final class AgentInstallKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class AgentInstallKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

final class AgentInstallCertificates implements LeafCertificateSigner
{
    public function sign(string $hostname, string $certificateRequest): string
    {
        return '';
    }

    public function rootCertificate(): string
    {
        return "-----BEGIN CERTIFICATE-----\nroot\n-----END CERTIFICATE-----\n";
    }
}

it('gets everything it needs to narrow the unit from the container', function (): void {
    $executor = app(NodeAgentSshExecutor::class);

    expect(new ReflectionProperty($executor, 'storageRoots')->getValue($executor))->toBeInstanceOf(StorageRootResolver::class)
        ->and(new ReflectionProperty($executor, 'nodeSettings')->getValue($executor))->toBeInstanceOf(NodeSettingsNormalizer::class)
        ->and(new ReflectionProperty($executor, 'accounts')->getValue($executor))->toBeInstanceOf(ManagedUserAccountResolver::class);
});

/** @return array{string, Node} A temporary managed home and a saved Node whose Instances live under its `apps`. */
function agent_env_home(): array
{
    $home = sys_get_temp_dir().'/orbit-agent-env-'.bin2hex(random_bytes(4));
    mkdir($home.'/apps/shop/dev', 0o755, true);
    mkdir($home.'/apps/shop/linked', 0o755, true);
    mkdir($home.'/outside', 0o755, true);
    $node = Node::query()->create([
        'name' => 'agent-env', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit',
        'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.45', 'wireguard_ip' => '10.44.0.45',
    ]);
    $app = App\Models\App::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git', 'default_branch' => 'main']);
    foreach (['dev' => $home.'/apps/shop/dev', 'linked' => $home.'/apps/shop/linked', 'outside' => $home.'/outside'] as $name => $path) {
        AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => $path, 'status' => 'source_resolved']);
    }

    return [$home, $node];
}

it('closes the environment of every Instance checkout in the Instance root on converge', function (): void {
    [$home, $node] = agent_env_home();
    file_put_contents($home.'/apps/shop/dev/.env', "APP_KEY=secret\n");
    chmod($home.'/apps/shop/dev/.env', 0o664);
    file_put_contents($home.'/outside/.env', "APP_KEY=secret\n");
    chmod($home.'/outside/.env', 0o664);
    file_put_contents($home.'/target.env', "APP_KEY=secret\n");
    chmod($home.'/target.env', 0o664);
    symlink($home.'/target.env', $home.'/apps/shop/linked/.env');
    $ssh = new AgentInstallSsh(null, runScriptsLocally: true);

    try {
        nodeAgentExecutor($ssh, new ManagedUserAccount('orbit', 'orbit', $home))->converge($node);
        clearstatcache();

        $script = array_values(array_filter($ssh->commands, static fn (RemoteCommand $command): bool => ($command->arguments[0] ?? null) === 'bash'));
        expect($script)->toHaveCount(1)
            ->and($script[0]->arguments)->toBe(['bash', '-seu', '--', $home.'/apps/shop/dev', $home.'/apps/shop/linked'])
            ->and(fileperms($home.'/apps/shop/dev/.env') & 0o777)->toBe(0o660)
            ->and(fileperms($home.'/outside/.env') & 0o777)->toBe(0o664)
            ->and(fileperms($home.'/target.env') & 0o777)->toBe(0o664);
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});

it('logs the checkouts whose environment it could not close and still converges', function (): void {
    [$home, $node] = agent_env_home();
    Log::spy();
    $ssh = new AgentInstallSsh(null, scriptResult: new CommandResult(1, '', $home."/apps/shop/dev\n", 1, false));

    try {
        nodeAgentExecutor($ssh, new ManagedUserAccount('orbit', 'orbit', $home))->converge($node);

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => $message === 'The agent converge could not close Instance environments.'
                && $context['checkouts'] === [$home.'/apps/shop/dev'],
        )->once();
        expect(array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands))
            ->toContain(['sudo', 'systemctl', 'enable', '--now', 'orbit-agent']);
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});
