<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeAgentRoleConverger;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Support\Facades\Log;

it('keeps the role converge going when the agent install fails', function (): void {
    Log::spy();
    $agent = new class implements NodeAgentRuntime
    {
        public function converge(Node $node): void
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

it('uses the SSH default timeout for the binary download', function (): void {
    $ssh = new AgentInstallSsh(null);
    nodeAgentExecutor($ssh)->converge(nodeAgentNode());

    $downloadIndex = array_search(
        ['sudo', 'curl', '--fail', '--location', '--silent', '--show-error', '--output', '/usr/local/bin/orbit-agent.orbit-candidate', '--', NodeAgentFootprint::downloadUrl('x86_64')],
        array_map(static fn (RemoteCommand $command): array => $command->arguments, $ssh->commands),
        true,
    );

    expect($downloadIndex)->toBeInt()
        ->and($ssh->connections[$downloadIndex]->commandTimeout)->toBe(900.0);
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

it('writes the hardened agent unit and files and restarts after a change', function (): void {
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
        ->toContain('gateway_url = "https://gateway.orbit"'."\n")
        ->and(implode("\n", $contents))
        ->toContain(NodeAgentFootprint::Marker, 'Restart=always', 'RestartSec=2', 'CapabilityBoundingSet=', 'NoNewPrivileges=yes', 'ProtectSystem=strict', 'ProtectHome=yes', 'PrivateTmp=yes', 'MemoryMax=64M', 'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6', '-----BEGIN CERTIFICATE-----');

    expect($arguments)->toContain(
        ['sudo', 'systemctl', 'enable', '--now', 'orbit-agent'],
        ['sudo', 'systemctl', 'restart', 'orbit-agent'],
    );
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

function nodeAgentExecutor(SshExecutor $ssh): NodeAgentSshExecutor
{
    return new NodeAgentSshExecutor(
        $ssh,
        new AgentInstallKeys,
        new AgentInstallKnownHosts,
        new AgentInstallCertificates,
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

    public function __construct(private ?string $installedChecksum, private string $candidateChecksum = NodeAgentFootprint::X8664Checksum) {}

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

        if (($arguments[1] ?? null) === 'test') {
            return new CommandResult(1, '', '', 1, false);
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

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $this->connections[] = $connection;
        $arguments = $command->arguments;

        if (($arguments[1] ?? null) === 'sha256sum') {
            $path = $arguments[array_key_last($arguments)];
            $checksum = $this->checksums[$path] ?? null;

            return $checksum === null
                ? new CommandResult(1, '', '', 1, false)
                : new CommandResult(0, $checksum.'  '.$path."\n", '', 1, false);
        }

        if (($arguments[1] ?? null) === 'curl') {
            $candidate = $arguments[array_search('--output', $arguments, true) + 1];
            $this->checksums[$candidate] = NodeAgentFootprint::X8664Checksum;

            return new CommandResult(0, '', '', 1, false);
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
            $this->files[$arguments[array_key_last($arguments)]] = stream_get_contents($stream) ?: '';

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
