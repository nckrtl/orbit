<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class NodeAgentSshExecutor implements NodeAgentRuntime
{
    /** Closes each named checkout's regular `.env` to other users, and names every checkout it could not close on stderr. */
    public const string CloseEnvironmentsScript = <<<'BASH'
        failed=0
        for checkout in "$@"; do
          [ -d "$checkout" ] && [ ! -L "$checkout" ] || continue
          if ! find "$checkout" -maxdepth 1 -name .env -type f -exec chmod o-rwx {} + 2>/dev/null; then
            printf '%s\n' "$checkout" >&2
            failed=1
          fi
        done
        exit "$failed"
        BASH;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private LeafCertificateSigner $certificates,
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
    ) {}

    /**
     * The Node's Instance root, or null when the managed user or a safe path cannot be resolved.
     */
    private function instanceRoot(Node $node): ?string
    {
        try {
            $account = $this->accounts->resolve($node);
            $root = $this->storageRoots->resolveApps($this->nodeSettings->fromStored($node->settings), $account)->instance->value;
        } catch (Throwable) {
            return null;
        }

        if (preg_match('#\A(/[A-Za-z0-9._-]+)+\z#D', $root) !== 1 || str_contains($root, '/..') || str_contains($root, '/./')) {
            return null;
        }

        return $root;
    }

    /**
     * The unit lines that let the agent read the Node's task checkouts and nothing else under `/home` or
     * `/root` (ADR 0151). `/home` and `/root` become empty, and only the Instance root is bound back
     * read-only. Root without capabilities reads there only what other users may read. Without a
     * resolvable root, the agent sees no home directory at all.
     *
     * @return list<string>
     */
    private function checkoutAccess(?string $root): array
    {
        if ($root === null) {
            return ['ProtectHome=yes'];
        }

        $underHome = str_starts_with($root.'/', '/home/') || str_starts_with($root.'/', '/root/');

        return ['ProtectHome=tmpfs', ...($underHome ? ['BindReadOnlyPaths=-'.$root] : [])];
    }

    /**
     * Removes the world bits from the `.env` of every Instance checkout in the Instance root, so the
     * agent, like every other local user, cannot read it. Best effort: a failure logs a warning with
     * the checkouts it could not close and does not fail the converge.
     */
    private function closeInstanceEnvironments(Node $node, ?string $root): void
    {
        if ($root === null) {
            return;
        }

        $checkouts = AppInstance::query()
            ->where('node_id', $node->getKey())
            ->orderBy('id')
            ->pluck('checkout_path')
            ->filter(static fn (mixed $path): bool => is_string($path) && str_starts_with($path, $root.'/') && ! str_contains($path, '/..'))
            ->values()
            ->all();

        if ($checkouts === []) {
            return;
        }

        try {
            $result = $this->raw($node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', ...$checkouts],
                input: self::CloseEnvironmentsScript,
            ));
        } catch (Throwable $exception) {
            Log::warning('The agent converge could not close Instance environments.', ['node_id' => $node->getKey(), 'error' => $exception->getMessage()]);

            return;
        }

        if (! $result->succeeded()) {
            Log::warning('The agent converge could not close Instance environments.', [
                'node_id' => $node->getKey(),
                'checkouts' => array_values(array_filter(explode("\n", trim($result->stderr)), static fn (string $line): bool => $line !== '')),
            ]);
        }
    }

    public function converge(Node $node): void
    {
        $architecture = is_string($node->architecture) ? $node->architecture : '';

        try {
            $checksum = NodeAgentFootprint::checksum($architecture);
        } catch (\InvalidArgumentException $exception) {
            throw new ResourceOperationException('agent.architecture_unsupported', 'The Node agent architecture is unsupported.', 422, $exception);
        }

        $gatewayAddress = $this->gatewayAddress();

        if (! is_string($gatewayAddress) || filter_var($gatewayAddress, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('agent.install_failed', 'The active Gateway has no managed WireGuard address.', 409);
        }

        $root = $this->instanceRoot($node);
        $configuration = "gateway_url = \"https://gateway.orbit\"\ngateway_address = \"{$gatewayAddress}\"\n";
        $certificate = $this->certificates->rootCertificate();
        $unit = implode("\n", [
            NodeAgentFootprint::Marker,
            '[Unit]',
            'Description=Orbit Node agent',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'ExecStart='.NodeAgentFootprint::BinaryPath,
            'Restart=always',
            'RestartSec=2',
            'User=root',
            'Group=root',
            'CapabilityBoundingSet=',
            'NoNewPrivileges=yes',
            'ProtectSystem=strict',
            ...$this->checkoutAccess($root),
            'PrivateTmp=yes',
            'MemoryMax=128M',
            'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);

        $this->run($node, new RemoteCommand(['sudo', 'install', '-d', '-o', 'root', '-g', 'root', '-m', '0755', '/etc/orbit/agent']), 'agent.install_failed');
        $changed = $this->installBinary($node, $checksum, $architecture);
        $changed = $this->publishFile($node, NodeAgentFootprint::ConfigurationPath, $configuration, 0644) || $changed;
        $changed = $this->publishFile($node, NodeAgentFootprint::CertificatePath, $certificate, 0644) || $changed;
        $changed = $this->publishFile($node, NodeAgentFootprint::UnitPath, $unit, 0644) || $changed;
        $this->closeInstanceEnvironments($node, $root);

        $this->run($node, new RemoteCommand(['sudo', 'systemctl', 'daemon-reload']), 'agent.install_failed');
        $this->run($node, new RemoteCommand(['sudo', 'systemctl', 'enable', '--now', NodeAgentFootprint::Service]), 'agent.install_failed');

        if ($changed) {
            $this->run($node, new RemoteCommand(['sudo', 'systemctl', 'restart', NodeAgentFootprint::Service]), 'agent.install_failed');
        }
    }

    public function remove(Node $node): void
    {
        $failure = null;

        try {
            $unit = $this->raw($node, new RemoteCommand(['sudo', 'test', '-f', NodeAgentFootprint::UnitPath]));

            if ($unit->succeeded()) {
                $this->attemptRemovalCommand($node, new RemoteCommand(['sudo', 'systemctl', 'stop', NodeAgentFootprint::Service]), $failure);
                $this->attemptRemovalCommand($node, new RemoteCommand(['sudo', 'systemctl', 'disable', NodeAgentFootprint::Service]), $failure);
            } elseif ($unit->exitCode !== 1) {
                $failure = new ResourceOperationException('agent.remove_failed', 'The Node agent could not be removed.', 502);
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->attemptRemovalCommand($node, new RemoteCommand([
            'sudo', 'rm', '-f', '--',
            NodeAgentFootprint::UnitPath,
            NodeAgentFootprint::BinaryPath,
        ]), $failure);
        $this->attemptRemovalCommand($node, new RemoteCommand(['sudo', 'rm', '-rf', '--', '/etc/orbit/agent']), $failure);
        $this->attemptRemovalCommand($node, new RemoteCommand(['sudo', 'systemctl', 'daemon-reload']), $failure);
        $this->resetFailedUnit($node);

        if ($failure instanceof Throwable) {
            throw new ResourceOperationException('agent.remove_failed', 'The Node agent could not be removed.', 502, $failure);
        }
    }

    /**
     * Clears the failed-unit record that systemd keeps after a crashed agent's unit file is removed.
     * The command fails for a unit that is not failed or no longer loaded, so its outcome is ignored.
     */
    private function resetFailedUnit(Node $node): void
    {
        try {
            $this->raw($node, new RemoteCommand(['sudo', 'systemctl', 'reset-failed', NodeAgentFootprint::Service]));
        } catch (Throwable) {
            return;
        }
    }

    private function attemptRemovalCommand(Node $node, RemoteCommand $command, ?Throwable &$failure): void
    {
        try {
            if (! $this->raw($node, $command)->succeeded()) {
                $failure ??= new ResourceOperationException('agent.remove_failed', 'The Node agent could not be removed.', 502);
            }
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
    }

    /**
     * The Gateway's WireGuard address. A `gateway` role converge marks its assignment provisioning
     * while it runs, and it converges the agent too, so a converging holder counts; an active one wins.
     */
    private function gatewayAddress(): mixed
    {
        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $status) {
            $address = Node::query()
                ->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($query) => $query
                    ->where('role', RoleName::Gateway)
                    ->where('status', $status))
                ->value('wireguard_ip');

            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }

    private function installBinary(Node $node, string $checksum, string $architecture): bool
    {
        $installed = $this->raw($node, new RemoteCommand(['sudo', 'sha256sum', '--', NodeAgentFootprint::BinaryPath]));

        if ($installed->succeeded() && $this->checksum($installed->stdout) === $checksum) {
            return false;
        }

        $candidate = NodeAgentFootprint::BinaryPath.NodeAgentFootprint::CandidateSuffix;
        $this->run($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]), 'agent.binary_download_failed');
        $this->run($node, new RemoteCommand([
            'sudo', 'curl', '--fail', '--location', '--silent', '--show-error',
            '--output', $candidate, '--', NodeAgentFootprint::downloadUrl($architecture),
        ]), 'agent.binary_download_failed');
        $downloaded = $this->run($node, new RemoteCommand(['sudo', 'sha256sum', '--', $candidate]), 'agent.binary_inspection_failed');

        if ($this->checksum($downloaded->stdout) !== $checksum) {
            $this->raw($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]));
            throw new ResourceOperationException('agent.checksum_mismatch', 'The downloaded Node agent failed checksum verification.', 502);
        }

        $this->run($node, new RemoteCommand(['sudo', 'chown', 'root:root', '--', $candidate]), 'agent.install_failed');
        $this->run($node, new RemoteCommand(['sudo', 'chmod', '0755', '--', $candidate]), 'agent.install_failed');
        $this->run($node, new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, NodeAgentFootprint::BinaryPath]), 'agent.install_failed');

        return true;
    }

    private function publishFile(Node $node, string $path, string $contents, int $mode): bool
    {
        if ($this->readFile($node, $path) === $contents) {
            return false;
        }

        $candidate = $path.NodeAgentFootprint::CandidateSuffix;
        $this->run($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]), 'agent.install_failed');
        $this->run($node, new RemoteCommand(
            ['sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', sprintf('%04o', $mode), '/dev/stdin', $candidate],
            protectedInput: ProtectedInput::fromString($contents),
        ), 'agent.install_failed');
        $this->run($node, new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, $path]), 'agent.install_failed');

        return true;
    }

    private function readFile(Node $node, string $path): ?string
    {
        $exists = $this->raw($node, new RemoteCommand(['sudo', 'test', '-f', $path]));

        if ($exists->exitCode === 1) {
            return null;
        }

        if (! $exists->succeeded()) {
            throw new ResourceOperationException('agent.install_failed', 'The Node agent files could not be inspected.', 502);
        }

        $contents = $this->raw($node, new RemoteCommand(['sudo', 'cat', '--', $path]));

        if (! $contents->succeeded()) {
            throw new ResourceOperationException('agent.install_failed', 'The Node agent files could not be inspected.', 502);
        }

        return $contents->stdout;
    }

    private function checksum(string $output): string
    {
        return preg_split('/\s+/', trim($output), 2)[0] ?? '';
    }

    private function run(Node $node, RemoteCommand $command, string $errorCode): CommandResult
    {
        $result = $this->raw($node, $command);

        if (! $result->succeeded()) {
            throw new ResourceOperationException($errorCode, 'The Node agent could not be installed or converged.', 502);
        }

        return $result;
    }

    private function raw(Node $node, RemoteCommand $command): CommandResult
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException('agent.install_failed', 'The Node has no managed SSH address.', 409);
        }

        $connection = new SshConnection(
            host: $node->wireguard_ip,
            user: $node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
        );

        return $this->ssh->execute($connection, $command);
    }
}
