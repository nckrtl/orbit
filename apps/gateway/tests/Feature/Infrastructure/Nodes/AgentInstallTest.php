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

function nodeAgentExecutor(SshExecutor $ssh, ?ManagedUserAccount $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit')): NodeAgentSshExecutor
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
