<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

/**
 * @mago-expect lint:cyclomatic-complexity The adapter keeps firewall ownership, mutation, verification, and recovery together.
 * @mago-expect lint:too-many-methods The private methods keep each fixed firewall operation narrow and non-generic.
 */
final readonly class MetricsPublicationSshExecutor
{
    private const string FirewallComment = MetricsFootprint::PublicationFirewallComment;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private UfwStatusParser $parser = new UfwStatusParser,
        private NodeFirewallRuleCatalog $firewallRules = new NodeFirewallRuleCatalog,
    ) {}

    public function converge(Node $metricsNode, string $gatewayAddress): bool
    {
        $rule = $this->firewallRules->metricsGrafanaUpstream($metricsNode, $gatewayAddress);
        $shape = $rule->shape;
        $status = $this->status($metricsNode);
        $ownership = $this->parser->ownership($status->stdout, $shape);

        if ($ownership === UfwRuleOwnership::Drift) {
            $this->ownershipDrift();
        }

        if ($ownership === UfwRuleOwnership::Exact) {
            return false;
        }

        try {
            $this->run(
                $metricsNode,
                new RemoteCommand($rule->arguments),
                'metrics.publication_firewall_apply_failed',
                'The Metrics Grafana firewall rule could not be applied.',
            );

            if ($this->parser->ownership($this->status($metricsNode)->stdout, $shape) !== UfwRuleOwnership::Exact) {
                throw new ResourceOperationException(
                    'metrics.publication_firewall_verify_failed',
                    'The Metrics Grafana firewall rule could not be verified.',
                    502,
                );
            }
        } catch (\Throwable $exception) {
            try {
                $this->remove($metricsNode, $gatewayAddress);
            } catch (\Throwable $rollback) {
                throw new ResourceOperationException(
                    'metrics.publication_firewall_rollback_failed',
                    'The Metrics Grafana firewall rule could not be restored.',
                    502,
                    new ResourceOperationException(
                        'metrics.publication_firewall_failed',
                        $exception->getMessage(),
                        502,
                        $rollback,
                    ),
                );
            }

            throw $exception;
        }

        return true;
    }

    public function remove(Node $metricsNode, string $gatewayAddress): void
    {
        $shape = $this->firewallRules->metricsGrafanaUpstream($metricsNode, $gatewayAddress)->shape;
        $status = $this->status($metricsNode);
        $ownership = $this->parser->ownership($status->stdout, $shape);

        if ($ownership === UfwRuleOwnership::Drift) {
            $this->ownershipDrift();
        }

        if ($ownership === UfwRuleOwnership::Missing) {
            return;
        }

        $numbers = $this->ruleNumbers($status->stdout);

        if (count($numbers) !== 1) {
            $this->ownershipDrift();
        }

        $this->run(
            $metricsNode,
            new RemoteCommand(['sudo', 'ufw', '--force', 'delete', $numbers[0]]),
            'metrics.publication_firewall_remove_failed',
            'The Metrics Grafana firewall rule could not be removed.',
        );

        if ($this->parser->ownership($this->status($metricsNode)->stdout, $shape) !== UfwRuleOwnership::Missing) {
            throw new ResourceOperationException(
                'metrics.publication_firewall_remove_verify_failed',
                'The Metrics Grafana firewall rule remained after removal.',
                502,
            );
        }
    }

    /**
     * Removes the Grafana upstream rule without knowing the Gateway.
     *
     * The full ownership shape needs the Gateway address the rule allows, and
     * that address is exactly what is missing when Metrics is disabled with no
     * active Gateway. The Orbit comment is the rule's own identity, so
     * abandonment matches on it alone and still proves the rule is gone.
     */
    public function abandon(Node $metricsNode): void
    {
        $numbers = $this->ruleNumbers($this->status($metricsNode)->stdout);

        if ($numbers === []) {
            return;
        }

        if (count($numbers) !== 1) {
            $this->ownershipDrift();
        }

        $this->run(
            $metricsNode,
            new RemoteCommand(['sudo', 'ufw', '--force', 'delete', $numbers[0]]),
            'metrics.publication_firewall_remove_failed',
            'The Metrics Grafana firewall rule could not be removed.',
        );

        if ($this->ruleNumbers($this->status($metricsNode)->stdout) !== []) {
            throw new ResourceOperationException(
                'metrics.publication_firewall_remove_verify_failed',
                'The Metrics Grafana firewall rule remained after removal.',
                502,
            );
        }
    }

    private function status(Node $node): CommandResult
    {
        $result = $this->run(
            $node,
            new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']),
            'metrics.publication_firewall_inspection_failed',
            'The Metrics Grafana firewall state could not be inspected.',
        );

        if (preg_match('/^Status:\s+active$/mi', $result->stdout) !== 1) {
            throw new ResourceOperationException(
                'metrics.publication_firewall_inactive',
                'UFW must be active for Metrics Grafana publication.',
                409,
            );
        }

        return $result;
    }

    private function address(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.publication_address_invalid',
                'Metrics publication requires valid WireGuard IPv4 addresses.',
                409,
            );
        }

        return $address;
    }

    private function connection(Node $node): SshConnection
    {
        return new SshConnection(
            host: $this->address($node),
            user: 'orbit',
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
        );
    }

    private function run(
        Node $node,
        RemoteCommand $command,
        string $errorCode,
        string $message,
    ): CommandResult {
        $result = $this->ssh->execute($this->connection($node), $command);

        if (! $result->succeeded()) {
            throw new ResourceOperationException($errorCode, $message, 502);
        }

        return $result;
    }

    /**
     * Numbers the UFW rules whose comment is exactly Orbit's Grafana marker.
     *
     * The comment ends the line, so the match is anchored there. A prefix
     * match would also claim a future neighbour such as
     * `orbit:metrics-grafana-upstream-v2` and delete it silently.
     *
     * @return list<string>
     */
    private function ruleNumbers(string $status): array
    {
        $numbers = [];
        $comment = '# '.self::FirewallComment;

        foreach (explode("\n", $status) as $line) {
            $matches = [];

            if (
                str_ends_with(rtrim($line), $comment)
                && preg_match('/^\s*\[\s*(\d+)\]/', $line, $matches) === 1
            ) {
                $numbers[] = $matches[1];
            }
        }

        return $numbers;
    }

    private function ownershipDrift(): never
    {
        throw new ResourceOperationException(
            'metrics.publication_firewall_ownership_drift',
            'Metrics Grafana firewall ownership cannot be proved.',
            409,
        );
    }
}
