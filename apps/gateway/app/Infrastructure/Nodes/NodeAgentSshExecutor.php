<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NodeAgentSshExecutor implements NodeAgentRuntime
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private LeafCertificateSigner $certificates,
    ) {}

    public function converge(Node $node): void
    {
        $architecture = is_string($node->architecture) ? $node->architecture : '';

        try {
            $checksum = NodeAgentFootprint::checksum($architecture);
        } catch (\InvalidArgumentException $exception) {
            throw new ResourceOperationException('agent.architecture_unsupported', 'The Node agent architecture is unsupported.', 422, $exception);
        }

        $configuration = "gateway_url = \"https://gateway.orbit\"\n";
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
            'ProtectHome=yes',
            'PrivateTmp=yes',
            'MemoryMax=64M',
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

        $this->run($node, new RemoteCommand(['sudo', 'systemctl', 'daemon-reload']), 'agent.install_failed');
        $this->run($node, new RemoteCommand(['sudo', 'systemctl', 'enable', '--now', NodeAgentFootprint::Service]), 'agent.install_failed');

        if ($changed) {
            $this->run($node, new RemoteCommand(['sudo', 'systemctl', 'restart', NodeAgentFootprint::Service]), 'agent.install_failed');
        }
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
