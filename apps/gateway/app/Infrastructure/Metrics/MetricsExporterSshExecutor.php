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
 * @mago-expect lint:cyclomatic-complexity The narrow executor keeps one ownership and recovery boundary.
 * @mago-expect lint:kan-defect The narrow executor keeps remote mutation and exact recovery together.
 * @mago-expect lint:too-many-methods Private command helpers keep every remote operation fixed and typed.
 */
final readonly class MetricsExporterSshExecutor implements MetricsExporterRuntime
{
    private const string ConfigurationPath = MetricsFootprint::ExporterDropIn;

    private const string FirewallComment = MetricsFootprint::ExporterFirewallComment;

    private const string OwnershipMarker = MetricsFootprint::ExporterDropInMarker;

    /** @mago-expect lint:excessive-parameter-list The executor keeps each fixed SSH, ownership, and recovery dependency explicit. */
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private UfwStatusParser $parser = new UfwStatusParser,
        private MetricsUninstallScript $uninstall = new MetricsUninstallScript,
        private NodeFirewallRuleCatalog $firewallRules = new NodeFirewallRuleCatalog,
    ) {}

    public function converge(Node $node, Node $metricsNode): void
    {
        $rule = $this->firewallRules->metricsExporter($node, $metricsNode);
        $shape = $rule->shape;
        $state = $this->snapshot($node, $metricsNode);
        $ownership = $state->firewallOwnership;
        $expected = $this->expectedConfiguration($node);

        try {
            $this->publishUninstallScript($node, 'metrics.exporter_uninstall_publish_failed');
            $this->run(
                $node,
                new RemoteCommand([
                    'sudo',
                    'apt-get',
                    'install',
                    '--yes',
                    '--no-install-recommends',
                    '--',
                    MetricsFootprint::ExporterPackage,
                ]),
                'metrics.exporter_install_failed',
                'The Metrics exporter package could not be installed.',
            );
            $this->publishConfiguration($node, $expected, 'metrics.exporter_configuration_failed');
            $this->setServiceActive($node, true, 'metrics.exporter_service_failed');

            if ($ownership === UfwRuleOwnership::Missing) {
                $this->addFirewall($node, $rule);
            }

            if (! hash_equals($expected, $this->configuration($node) ?? '')) {
                throw new ResourceOperationException(
                    'metrics.exporter_configuration_verify_failed',
                    'The Metrics exporter configuration could not be verified.',
                    502,
                );
            }

            if (! $this->serviceActive($node)) {
                throw new ResourceOperationException(
                    'metrics.exporter_service_verify_failed',
                    'The Metrics exporter service is not active.',
                    502,
                );
            }

            if ($this->parser->ownership($this->firewallStatus($node)->stdout, $shape) !== UfwRuleOwnership::Exact) {
                throw new ResourceOperationException(
                    'metrics.exporter_firewall_verify_failed',
                    'The Metrics exporter firewall rule could not be verified.',
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
        $shape = $this->firewallRules->metricsExporter($node, $metricsNode)->shape;
        $state = $this->snapshot($node, $metricsNode);
        $configuration = $state->configuration;
        $ownership = $state->firewallOwnership;

        try {
            if (is_string($configuration)) {
                $this->setServiceActive($node, false, 'metrics.exporter_service_remove_failed');
                $this->removeConfiguration($node, 'metrics.exporter_configuration_remove_failed');
            }

            if ($ownership === UfwRuleOwnership::Exact) {
                $this->removeFirewall($node, $state->firewallStatus);
            }

            if ($this->configuration($node) !== null) {
                throw new ResourceOperationException(
                    'metrics.exporter_configuration_remove_verify_failed',
                    'The Metrics exporter configuration remained after removal.',
                    502,
                );
            }

            if (is_string($configuration) && $this->serviceActive($node)) {
                throw new ResourceOperationException(
                    'metrics.exporter_service_remove_verify_failed',
                    'The Metrics exporter service remained active after removal.',
                    502,
                );
            }

            if ($this->parser->ownership($this->firewallStatus($node)->stdout, $shape) !== UfwRuleOwnership::Missing) {
                throw new ResourceOperationException(
                    'metrics.exporter_firewall_remove_verify_failed',
                    'The Metrics exporter firewall rule remained after removal.',
                    502,
                );
            }

            // Last, so any failure above leaves the escape where an operator
            // can still reach the footprint this removal did not finish.
            $this->removeUninstallScript($node, 'metrics.exporter_uninstall_remove_failed');
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
        $shape = $this->firewallRules->metricsExporter($node, $metricsNode)->shape;
        $firewall = $this->firewallStatus($node);
        $ownership = $this->parser->ownership($firewall->stdout, $shape);
        $this->guardFirewallOwnership($ownership);

        return new MetricsExporterState(
            configuration: $configuration,
            serviceActive: $this->serviceActive($node),
            firewallOwnership: $ownership,
            firewallStatus: $firewall->stdout,
        );
    }

    public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void
    {
        $rule = $this->firewallRules->metricsExporter($node, $metricsNode);
        $shape = $rule->shape;
        $currentConfiguration = $this->configuration($node);
        $this->guardConfigurationOwnership($currentConfiguration);

        if ($state->configuration !== $currentConfiguration) {
            is_string($state->configuration)
                ? $this->publishConfiguration(
                    $node,
                    $state->configuration,
                    'metrics.exporter_configuration_rollback_failed',
                )
                : $this->removeConfiguration($node, 'metrics.exporter_configuration_rollback_failed');
        }

        is_string($state->configuration)
            ? $this->publishUninstallScript($node, 'metrics.exporter_uninstall_rollback_failed')
            : $this->removeUninstallScript($node, 'metrics.exporter_uninstall_rollback_failed');

        if ($state->serviceActive || $this->serviceActive($node)) {
            $this->setServiceActive($node, $state->serviceActive, 'metrics.exporter_service_rollback_failed');
        }

        $firewall = $this->firewallStatus($node);
        $currentOwnership = $this->parser->ownership($firewall->stdout, $shape);
        $this->guardFirewallOwnership($currentOwnership);

        if (
            $state->firewallOwnership === UfwRuleOwnership::Exact
            && $currentOwnership === UfwRuleOwnership::Missing
        ) {
            $this->addFirewall($node, $rule);
        }

        if (
            $state->firewallOwnership === UfwRuleOwnership::Missing
            && $currentOwnership === UfwRuleOwnership::Exact
        ) {
            $this->removeFirewall($node, $firewall->stdout);
        }

        $this->verifyRestoredState($node, $shape, $state);
    }

    public function actual(Node $node, Node $metricsNode): string
    {
        try {
            $configuration = $this->configuration($node);
            $this->guardConfigurationOwnership($configuration);
            $shape = $this->firewallRules->metricsExporter($node, $metricsNode)->shape;
            $ownership = $this->parser->ownership($this->firewallStatus($node)->stdout, $shape);

            if ($ownership === UfwRuleOwnership::Drift) {
                return 'drift';
            }

            if ($configuration === null && $ownership === UfwRuleOwnership::Missing) {
                return 'inactive';
            }

            if ($configuration === null || $ownership !== UfwRuleOwnership::Exact) {
                return 'drift';
            }

            $service = $this->raw(
                $node,
                new RemoteCommand(['systemctl', 'is-active', MetricsFootprint::ExporterService]),
            );

            return $service->succeeded() && trim($service->stdout) === 'active'
                ? 'active'
                : 'inactive';
        } catch (ResourceOperationException $exception) {
            return $exception->status === 409 ? 'drift' : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function expectedConfiguration(Node $node): string
    {
        return (
            self::OwnershipMarker
            ."\n[Service]\nExecStart=\nExecStart=/usr/bin/"
            .MetricsFootprint::ExporterService
            ." --web.listen-address={$this->address($node)}:"
            .MetricsFootprint::ExporterPort
            ."\n"
        );
    }

    private function configuration(Node $node): ?string
    {
        $exists = $this->raw($node, new RemoteCommand(['sudo', 'test', '-e', self::ConfigurationPath]));

        if ($exists->exitCode === 1) {
            return null;
        }

        if (! $exists->succeeded()) {
            throw new ResourceOperationException(
                'metrics.exporter_configuration_inspection_failed',
                'The Metrics exporter configuration could not be inspected.',
                502,
            );
        }

        return $this->run(
            $node,
            new RemoteCommand(['sudo', 'cat', '--', self::ConfigurationPath]),
            'metrics.exporter_configuration_inspection_failed',
            'The Metrics exporter configuration could not be inspected.',
        )->stdout;
    }

    private function guardConfigurationOwnership(?string $configuration): void
    {
        if ($configuration === null || str_starts_with($configuration, self::OwnershipMarker."\n")) {
            return;
        }

        throw new ResourceOperationException(
            'metrics.exporter_configuration_ownership_drift',
            'Metrics exporter configuration ownership cannot be proved.',
            409,
        );
    }

    private function firewallStatus(Node $node): CommandResult
    {
        $status = $this->run(
            $node,
            new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']),
            'metrics.exporter_firewall_inspection_failed',
            'The Metrics exporter firewall state could not be inspected.',
        );

        if (preg_match('/^Status:\s+active$/mi', $status->stdout) !== 1) {
            throw new ResourceOperationException(
                'metrics.exporter_firewall_inactive',
                'UFW must be active for Metrics exporter convergence.',
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

    private function publishConfiguration(Node $node, string $configuration, string $errorCode): void
    {
        // `install` reads `/dev/stdin` and refuses an existing destination on
        // the uutils coreutils that Ubuntu ships, so the drop-in lands on a
        // candidate path and is moved into place.
        $candidate = self::ConfigurationPath.MetricsFootprint::CandidateSuffix;
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]),
            $errorCode,
            'The Metrics exporter configuration could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand(
                ['sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0644', '/dev/stdin', $candidate],
                protectedInput: ProtectedInput::fromString($configuration),
            ),
            $errorCode,
            'The Metrics exporter configuration could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, self::ConfigurationPath]),
            $errorCode,
            'The Metrics exporter configuration could not be published.',
        );
        $this->reloadUnits($node, $errorCode);
    }

    private function reloadUnits(Node $node, string $errorCode): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'systemctl', 'daemon-reload']),
            $errorCode,
            'The Metrics exporter unit could not be reloaded.',
        );
    }

    /**
     * Publishes the node-local escape beside the drop-in it removes.
     *
     * Every Metrics route authorizes against the one active Gateway, so a
     * fleet that loses its Gateway can reach no removal path at all. This
     * script is the way out. It is rendered from the same constants this
     * executor mutates, so it cannot drift from the state it removes, and it
     * is republished only when it differs, which costs a settled convergence
     * one extra command.
     */
    private function publishUninstallScript(Node $node, string $errorCode): void
    {
        $expected = $this->uninstall->render();
        $current = $this->raw($node, new RemoteCommand(['sudo', 'cat', '--', MetricsFootprint::UninstallScript]));

        if ($current->succeeded() && $current->stdout === $expected) {
            return;
        }

        $candidate = MetricsFootprint::UninstallScript.MetricsFootprint::CandidateSuffix;
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', $candidate]),
            $errorCode,
            'The Metrics uninstall script could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand(
                ['sudo', 'install', '-D', '-o', 'root', '-g', 'root', '-m', '0755', '/dev/stdin', $candidate],
                protectedInput: ProtectedInput::fromString($expected),
            ),
            $errorCode,
            'The Metrics uninstall script could not be staged.',
        );
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'mv', '-fT', '--', $candidate, MetricsFootprint::UninstallScript]),
            $errorCode,
            'The Metrics uninstall script could not be published.',
        );
    }

    /** Removes the escape and any candidate a failed publication left beside it. */
    private function removeUninstallScript(Node $node, string $errorCode): void
    {
        $this->run(
            $node,
            new RemoteCommand([
                'sudo',
                'rm',
                '-f',
                '--',
                MetricsFootprint::UninstallScript,
                MetricsFootprint::UninstallScript.MetricsFootprint::CandidateSuffix,
            ]),
            $errorCode,
            'The Metrics uninstall script could not be removed.',
        );
    }

    private function removeConfiguration(Node $node, string $errorCode): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'rm', '-f', '--', self::ConfigurationPath]),
            $errorCode,
            'The Metrics exporter configuration could not be removed.',
        );
        $this->reloadUnits($node, $errorCode);
    }

    private function setServiceActive(Node $node, bool $active, string $errorCode): void
    {
        $command = $active
            ? ['sudo', 'systemctl', 'enable', '--now', MetricsFootprint::ExporterService]
            : ['sudo', 'systemctl', 'disable', '--now', MetricsFootprint::ExporterService];

        $this->run(
            $node,
            new RemoteCommand($command),
            $errorCode,
            $active
                ? 'The Metrics exporter service could not be enabled.'
                : 'The Metrics exporter service could not be disabled.',
        );

        if ($active) {
            // The package starts the service on install with its own arguments;
            // a restart applies the Orbit drop-in to an already running unit.
            $this->run(
                $node,
                new RemoteCommand(['sudo', 'systemctl', 'restart', MetricsFootprint::ExporterService]),
                $errorCode,
                'The Metrics exporter service could not be restarted.',
            );
        }
    }

    private function serviceActive(Node $node): bool
    {
        $result = $this->raw(
            $node,
            new RemoteCommand(['systemctl', 'is-active', MetricsFootprint::ExporterService]),
        );

        if ($result->succeeded() && trim($result->stdout) === 'active') {
            return true;
        }

        if (in_array($result->exitCode, [3, 4], strict: true)) {
            return false;
        }

        throw new ResourceOperationException(
            'metrics.exporter_service_inspection_failed',
            'The Metrics exporter service state could not be inspected.',
            502,
        );
    }

    private function addFirewall(Node $node, UfwManagedRule $rule): void
    {
        $this->run(
            $node,
            new RemoteCommand($rule->arguments),
            'metrics.exporter_firewall_failed',
            'The Metrics exporter firewall rule could not be applied.',
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
            'metrics.exporter_firewall_remove_failed',
            'The Metrics exporter firewall rule could not be removed.',
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
                'metrics.exporter_rollback_failed',
                'Metrics exporter state could not be restored.',
                502,
                new ResourceOperationException(
                    'metrics.exporter_convergence_failed',
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
                'metrics.exporter_configuration_rollback_verify_failed',
                'Metrics exporter configuration recovery could not be verified.',
                502,
            );
        }

        if ($this->serviceActive($node) !== $state->serviceActive) {
            throw new ResourceOperationException(
                'metrics.exporter_service_rollback_verify_failed',
                'Metrics exporter service recovery could not be verified.',
                502,
            );
        }

        if ($this->parser->ownership($this->firewallStatus($node)->stdout, $shape) !== $state->firewallOwnership) {
            throw new ResourceOperationException(
                'metrics.exporter_firewall_rollback_verify_failed',
                'Metrics exporter firewall recovery could not be verified.',
                502,
            );
        }
    }

    private function firewallOwnershipDrift(): never
    {
        throw new ResourceOperationException(
            'metrics.exporter_firewall_ownership_drift',
            'Metrics exporter firewall ownership cannot be proved.',
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
                'metrics.exporter_address_invalid',
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
        return $this->ssh->execute($this->connection($node), $command);
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
