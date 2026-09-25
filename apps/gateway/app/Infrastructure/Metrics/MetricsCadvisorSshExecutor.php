<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Throwable;

/**
 * Installs, converges, and removes cAdvisor on one Node, mirroring `MetricsExporterSshExecutor`
 * for the node exporter. The node exporter comes from apt with a systemd drop-in; cAdvisor ships
 * no package, so this executor downloads a pinned static binary instead, verifies its checksum on
 * the Node before it is ever installed, and writes it a full systemd unit of its own rather than a
 * drop-in over someone else's.
 *
 * Unlike the exporter's apt package, the binary this executor installs is Orbit's alone to remove,
 * so `remove()` deletes it — after the unit, service, and firewall rule are confirmed gone, so a
 * failed removal can still roll the service back to active without needing the binary reinstalled.
 */
final readonly class MetricsCadvisorSshExecutor implements MetricsCadvisorRuntime
{
    private const string ConfigurationPath = MetricsFootprint::CadvisorUnitPath;

    private const string FirewallComment = MetricsFootprint::CadvisorFirewallComment;

    private const string OwnershipMarker = MetricsFootprint::CadvisorUnitMarker;

    /** The longest the pinned binary may take to download; the command itself gets 30 seconds more. */
    public const int DownloadMaxSeconds = 120;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private UfwStatusParser $parser = new UfwStatusParser,
        private NodeFirewallRuleCatalog $firewallRules = new NodeFirewallRuleCatalog,
    ) {}

    public function converge(Node $node, Node $metricsNode): void
    {
        $rule = $this->firewallRules->metricsCadvisor($node, $metricsNode);
        $shape = $rule->shape;
        $state = $this->snapshot($node, $metricsNode);
        $ownership = $state->firewallOwnership;
        $expected = $this->expectedUnit($node);

        try {
            $this->installBinary($node);
            $this->publishUnit($node, $expected, 'metrics.cadvisor_configuration_failed');
            $this->setServiceActive($node, true, 'metrics.cadvisor_service_failed');

            if ($state->staleFirewallSource !== null) {
                $this->removeFirewall($node, $state->firewallStatus);
            }

            if ($ownership !== UfwRuleOwnership::Exact) {
                $this->addFirewall($node, $rule);
            }

            if (! hash_equals($expected, $this->configuration($node) ?? '')) {
                throw new ResourceOperationException(
                    'metrics.cadvisor_configuration_verify_failed',
                    'The cAdvisor configuration could not be verified.',
                    502,
                );
            }

            if (! $this->serviceActive($node)) {
                throw new ResourceOperationException(
                    'metrics.cadvisor_service_verify_failed',
                    'The cAdvisor service is not active.',
                    502,
                );
            }

            if ($this->parser->ownership($this->firewallStatus($node)->stdout, $shape) !== UfwRuleOwnership::Exact) {
                throw new ResourceOperationException(
                    'metrics.cadvisor_firewall_verify_failed',
                    'The cAdvisor firewall rule could not be verified.',
                    502,
                );
            }
        } catch (Throwable $exception) {
            $this->recover(
                node: $node,
                metricsNode: $metricsNode,
                state: $state,
                previous: $exception,
            );
        }
    }

    public function remove(Node $node, Node $metricsNode): void
    {
        $shape = $this->firewallRules->metricsCadvisor($node, $metricsNode)->shape;
        $state = $this->snapshot($node, $metricsNode);
        $configuration = $state->configuration;
        $ownership = $state->firewallOwnership;

        try {
            if (is_string($configuration)) {
                $this->setServiceActive($node, false, 'metrics.cadvisor_service_remove_failed');
                $this->removeUnit($node, 'metrics.cadvisor_configuration_remove_failed');
            }

            if ($ownership === UfwRuleOwnership::Exact || $state->staleFirewallSource !== null) {
                $this->removeFirewall($node, $state->firewallStatus);
            }

            if ($this->configuration($node) !== null) {
                throw new ResourceOperationException(
                    'metrics.cadvisor_configuration_remove_verify_failed',
                    'The cAdvisor configuration remained after removal.',
                    502,
                );
            }

            if (is_string($configuration) && $this->serviceActive($node)) {
                throw new ResourceOperationException(
                    'metrics.cadvisor_service_remove_verify_failed',
                    'The cAdvisor service remained active after removal.',
                    502,
                );
            }

            if ($this->parser->ownership($this->firewallStatus($node)->stdout, $shape) !== UfwRuleOwnership::Missing) {
                throw new ResourceOperationException(
                    'metrics.cadvisor_firewall_remove_verify_failed',
                    'The cAdvisor firewall rule remained after removal.',
                    502,
                );
            }

            // Only once the unit, service, and firewall rule are confirmed gone: the binary is
            // not part of the rollback state above (like the exporter's apt package; see
            // MetricsExporterSshExecutor), so nothing below this line would need it reinstalled.
            $this->removeBinary($node);
        } catch (Throwable $exception) {
            $this->recover(
                node: $node,
                metricsNode: $metricsNode,
                state: $state,
                previous: $exception,
            );
        }
    }

    public function snapshot(Node $node, Node $metricsNode): MetricsExporterState
    {
        $configuration = $this->configuration($node);
        $this->guardConfigurationOwnership($configuration);
        $shape = $this->firewallRules->metricsCadvisor($node, $metricsNode)->shape;
        $firewall = $this->firewallStatus($node);
        $ownership = $this->parser->ownership($firewall->stdout, $shape);
        $staleSource = $ownership === UfwRuleOwnership::Drift
            ? $this->parser->staleSource($firewall->stdout, $shape)
            : null;

        if ($staleSource === null) {
            $this->guardFirewallOwnership($ownership);
        }

        return new MetricsExporterState(
            configuration: $configuration,
            serviceActive: $this->serviceActive($node),
            firewallOwnership: $ownership,
            firewallStatus: $firewall->stdout,
            staleFirewallSource: $staleSource,
        );
    }

    public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void
    {
        $rule = $this->firewallRules->metricsCadvisor($node, $metricsNode);
        $shape = $rule->shape;
        $currentConfiguration = $this->configuration($node);
        $this->guardConfigurationOwnership($currentConfiguration);

        if ($state->configuration !== $currentConfiguration) {
            is_string($state->configuration)
                ? $this->publishUnit(
                    $node,
                    $state->configuration,
                    'metrics.cadvisor_configuration_rollback_failed',
                )
                : $this->removeUnit($node, 'metrics.cadvisor_configuration_rollback_failed');
        }

        if ($state->serviceActive || $this->serviceActive($node)) {
            $this->setServiceActive($node, $state->serviceActive, 'metrics.cadvisor_service_rollback_failed');
        }

        $firewall = $this->firewallStatus($node);
        $currentSource = $this->firewallSource($firewall->stdout, $shape);
        $restoredSource = $state->firewallSource($shape->source);

        if ($currentSource !== $restoredSource) {
            if ($currentSource !== null) {
                $this->removeFirewall($node, $firewall->stdout);
            }

            if ($restoredSource !== null) {
                $this->addFirewall($node, $this->firewallRules->metricsAgentFromSource($rule, $restoredSource));
            }
        }

        $this->verifyRestoredState($node, $shape, $state);
    }

    /** The unit's `ExecStart` flags, kept to one line: `--disable_metrics` is the cardinality gate. */
    private function expectedUnit(Node $node): string
    {
        $flags = implode(' ', [
            '--listen_ip='.$this->address($node),
            '--port='.MetricsFootprint::CadvisorPort,
            '--store_container_labels=false',
            '--docker_only=false',
            '--disable_metrics='.implode(',', MetricsFootprint::CadvisorDisabledMetrics),
        ]);

        return implode("\n", [
            self::OwnershipMarker,
            '[Unit]',
            'Description=Orbit cAdvisor exporter',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'ExecStart='.MetricsFootprint::CadvisorBinaryPath.' '.$flags,
            'Restart=always',
            'RestartSec=2',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);
    }

    /**
     * Installs the pinned cAdvisor binary, unless it is already installed and already matches the
     * pinned checksum. A mismatch on the downloaded candidate fails convergence before the
     * candidate ever reaches `MetricsFootprint::CadvisorBinaryPath`.
     */
    private function installBinary(Node $node): void
    {
        $existing = $this->raw(
            $node,
            new RemoteCommand(['sudo', 'sha256sum', '--', MetricsFootprint::CadvisorBinaryPath]),
        );

        if ($existing->succeeded() && $this->checksum($existing->stdout) === MetricsFootprint::CadvisorChecksumSha256) {
            return;
        }

        $candidate = MetricsFootprint::CadvisorBinaryPath.MetricsFootprint::CandidateSuffix;

        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]),
            'metrics.cadvisor_binary_download_failed',
            'The cAdvisor binary could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand([
                'sudo', 'curl', '--fail', '--location', '--silent', '--show-error',
                '--connect-timeout', '10', '--max-time', (string) self::DownloadMaxSeconds,
                '--output', $candidate, '--', MetricsFootprint::CadvisorDownloadUrl,
            ], timeout: self::DownloadMaxSeconds + 30.0),
            'metrics.cadvisor_binary_download_failed',
            'The cAdvisor binary could not be downloaded.',
        );

        $downloaded = $this->run(
            $node,
            new RemoteCommand(['sudo', 'sha256sum', '--', $candidate]),
            'metrics.cadvisor_binary_inspection_failed',
            'The downloaded cAdvisor binary could not be inspected.',
        );

        if ($this->checksum($downloaded->stdout) !== MetricsFootprint::CadvisorChecksumSha256) {
            $this->raw($node, new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]));

            throw new ResourceOperationException(
                'metrics.cadvisor_checksum_mismatch',
                'The downloaded cAdvisor binary failed checksum verification.',
                502,
            );
        }

        $this->run(
            $node,
            new RemoteCommand(['sudo', 'chown', 'root:root', '--', $candidate]),
            'metrics.cadvisor_binary_install_failed',
            'The cAdvisor binary could not be installed.',
        );
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'chmod', '0755', '--', $candidate]),
            'metrics.cadvisor_binary_install_failed',
            'The cAdvisor binary could not be installed.',
        );
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, MetricsFootprint::CadvisorBinaryPath]),
            'metrics.cadvisor_binary_install_failed',
            'The cAdvisor binary could not be installed.',
        );
    }

    private function removeBinary(Node $node): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', MetricsFootprint::CadvisorBinaryPath]),
            'metrics.cadvisor_binary_remove_failed',
            'The cAdvisor binary could not be removed.',
        );
    }

    private function checksum(string $sha256sumOutput): string
    {
        $parts = preg_split('/\s+/', trim($sha256sumOutput), 2);

        return $parts[0] ?? '';
    }

    private function configuration(Node $node): ?string
    {
        $exists = $this->raw($node, new RemoteCommand(['sudo', 'test', '-e', self::ConfigurationPath]));

        if ($exists->exitCode === 1) {
            return null;
        }

        if (! $exists->succeeded()) {
            throw new ResourceOperationException(
                'metrics.cadvisor_configuration_inspection_failed',
                'The cAdvisor configuration could not be inspected.',
                502,
            );
        }

        return $this->run(
            $node,
            new RemoteCommand(['sudo', 'cat', '--', self::ConfigurationPath]),
            'metrics.cadvisor_configuration_inspection_failed',
            'The cAdvisor configuration could not be inspected.',
        )->stdout;
    }

    private function guardConfigurationOwnership(?string $configuration): void
    {
        if ($configuration === null || str_starts_with($configuration, self::OwnershipMarker."\n")) {
            return;
        }

        throw new ResourceOperationException(
            'metrics.cadvisor_configuration_ownership_drift',
            'cAdvisor configuration ownership cannot be proved.',
            409,
        );
    }

    private function firewallStatus(Node $node): CommandResult
    {
        $status = $this->run(
            $node,
            new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']),
            'metrics.cadvisor_firewall_inspection_failed',
            'The cAdvisor firewall state could not be inspected.',
        );

        if (preg_match('/^Status:\s+active$/mi', $status->stdout) !== 1) {
            throw new ResourceOperationException(
                'metrics.cadvisor_firewall_inactive',
                'UFW must be active for cAdvisor convergence.',
                409,
            );
        }

        return $status;
    }

    private function guardFirewallOwnership(UfwRuleOwnership $ownership): void
    {
        if ($ownership === UfwRuleOwnership::Drift) {
            $this->firewallOwnershipDrift();
        }
    }

    /**
     * The source the Orbit rule admits: the Metrics Node, a former Metrics Node, or null when there is no rule.
     */
    private function firewallSource(string $status, UfwRuleShape $shape): ?string
    {
        $ownership = $this->parser->ownership($status, $shape);

        if ($ownership === UfwRuleOwnership::Drift) {
            return $this->parser->staleSource($status, $shape) ?? $this->firewallOwnershipDrift();
        }

        return $ownership === UfwRuleOwnership::Exact ? $shape->source : null;
    }

    private function publishUnit(Node $node, string $unit, string $errorCode): void
    {
        // `install` reads `/dev/stdin` and refuses an existing destination on the uutils
        // coreutils Ubuntu ships, so the unit lands on a candidate path and is moved into place
        // (see MetricsExporterSshExecutor).
        $candidate = self::ConfigurationPath.MetricsFootprint::CandidateSuffix;
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]),
            $errorCode,
            'The cAdvisor configuration could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand(
                ['sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', $candidate],
                protectedInput: ProtectedInput::fromString($unit),
            ),
            $errorCode,
            'The cAdvisor configuration could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, self::ConfigurationPath]),
            $errorCode,
            'The cAdvisor configuration could not be published.',
        );
        $this->reloadUnits($node, $errorCode);
    }

    private function reloadUnits(Node $node, string $errorCode): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'systemctl', 'daemon-reload']),
            $errorCode,
            'The cAdvisor unit could not be reloaded.',
        );
    }

    private function removeUnit(Node $node, string $errorCode): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', self::ConfigurationPath]),
            $errorCode,
            'The cAdvisor configuration could not be removed.',
        );
        $this->reloadUnits($node, $errorCode);
        $this->resetFailedService($node);
    }

    /**
     * Clears the failed-unit record that systemd keeps after a crashed unit's file is removed.
     * The command fails for a unit that is not failed or no longer loaded, so its result is ignored.
     */
    private function resetFailedService(Node $node): void
    {
        $this->raw($node, new RemoteCommand(['sudo', 'systemctl', 'reset-failed', MetricsFootprint::CadvisorService]));
    }

    private function setServiceActive(Node $node, bool $active, string $errorCode): void
    {
        $command = $active
            ? ['sudo', 'systemctl', 'enable', '--now', MetricsFootprint::CadvisorService]
            : ['sudo', 'systemctl', 'disable', '--now', MetricsFootprint::CadvisorService];

        $this->run(
            $node,
            new RemoteCommand($command),
            $errorCode,
            $active
                ? 'The cAdvisor service could not be enabled.'
                : 'The cAdvisor service could not be disabled.',
        );

        if ($active) {
            // Applies a changed unit to an already-running process on re-convergence.
            $this->run(
                $node,
                new RemoteCommand(['sudo', 'systemctl', 'restart', MetricsFootprint::CadvisorService]),
                $errorCode,
                'The cAdvisor service could not be restarted.',
            );
        }
    }

    private function serviceActive(Node $node): bool
    {
        $result = $this->raw(
            $node,
            new RemoteCommand(['systemctl', 'is-active', MetricsFootprint::CadvisorService]),
        );

        if ($result->succeeded() && trim($result->stdout) === 'active') {
            return true;
        }

        if (in_array($result->exitCode, [3, 4], strict: true)) {
            return false;
        }

        throw new ResourceOperationException(
            'metrics.cadvisor_service_inspection_failed',
            'The cAdvisor service state could not be inspected.',
            502,
        );
    }

    private function addFirewall(Node $node, UfwManagedRule $rule): void
    {
        $this->run(
            $node,
            new RemoteCommand($rule->arguments),
            'metrics.cadvisor_firewall_failed',
            'The cAdvisor firewall rule could not be applied.',
        );
    }

    private function removeFirewall(Node $node, string $status): void
    {
        $numbers = $this->firewallRuleNumbers($status);

        if (count($numbers) !== 1) {
            $this->firewallOwnershipDrift();
        }

        $this->run(
            $node,
            new RemoteCommand(['sudo', 'ufw', '--force', 'delete', $numbers[0]]),
            'metrics.cadvisor_firewall_remove_failed',
            'The cAdvisor firewall rule could not be removed.',
        );
    }

    private function recover(
        Node $node,
        Node $metricsNode,
        MetricsExporterState $state,
        Throwable $previous,
    ): never {
        try {
            $this->restore($node, $metricsNode, $state);
        } catch (Throwable $rollback) {
            throw new ResourceOperationException(
                'metrics.cadvisor_rollback_failed',
                'cAdvisor state could not be restored.',
                502,
                new ResourceOperationException(
                    'metrics.cadvisor_convergence_failed',
                    $previous->getMessage(),
                    502,
                    $rollback,
                ),
            );
        }

        throw $previous;
    }

    private function verifyRestoredState(
        Node $node,
        UfwRuleShape $shape,
        MetricsExporterState $state,
    ): void {
        if ($this->configuration($node) !== $state->configuration) {
            throw new ResourceOperationException(
                'metrics.cadvisor_configuration_rollback_verify_failed',
                'cAdvisor configuration recovery could not be verified.',
                502,
            );
        }

        if ($this->serviceActive($node) !== $state->serviceActive) {
            throw new ResourceOperationException(
                'metrics.cadvisor_service_rollback_verify_failed',
                'cAdvisor service recovery could not be verified.',
                502,
            );
        }

        if ($this->firewallSource($this->firewallStatus($node)->stdout, $shape) !== $state->firewallSource($shape->source)) {
            throw new ResourceOperationException(
                'metrics.cadvisor_firewall_rollback_verify_failed',
                'cAdvisor firewall recovery could not be verified.',
                502,
            );
        }
    }

    private function firewallOwnershipDrift(): never
    {
        throw new ResourceOperationException(
            'metrics.cadvisor_firewall_ownership_drift',
            'cAdvisor firewall ownership cannot be proved.',
            409,
        );
    }

    /** @return list<string> */
    private function firewallRuleNumbers(string $status): array
    {
        $numbers = [];

        foreach (explode("\n", $status) as $line) {
            $matches = [];

            if (
                str_contains($line, '# '.self::FirewallComment)
                && preg_match('/^\s*\[\s*(\d+)\]/', $line, $matches) === 1
            ) {
                $numbers[] = $matches[1];
            }
        }

        return $numbers;
    }

    private function address(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.cadvisor_address_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
                409,
            );
        }

        return $address;
    }

    private function connection(Node $node): SshConnection
    {
        return new SshConnection(
            host: $this->address($node),
            user: $node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
        );
    }

    private function raw(Node $node, RemoteCommand $command): CommandResult
    {
        return MetricsRemoteCommand::execute($this->ssh, $this->connection($node), $node, $command);
    }

    private function run(
        Node $node,
        RemoteCommand $command,
        string $errorCode,
        string $message,
    ): CommandResult {
        $result = $this->raw($node, $command);

        if (! $result->succeeded()) {
            throw new ResourceOperationException($errorCode, $message, 502);
        }

        return $result;
    }
}
