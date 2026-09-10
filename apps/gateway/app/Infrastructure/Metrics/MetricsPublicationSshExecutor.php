<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class MetricsPublicationSshExecutor
{
    private const string WireGuardTrustComment = 'orbit:wireguard-members';

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private UfwStatusParser $parser = new UfwStatusParser,
        private NodeFirewallRuleCatalog $firewallRules = new NodeFirewallRuleCatalog,
    ) {}

    public function converge(Node $metricsNode, string $gatewayAddress): bool
    {
        $allow = $this->firewallRules->metricsGrafanaUpstream($metricsNode, $gatewayAddress);
        $deny = $this->firewallRules->metricsGrafanaIsolation($metricsNode);
        $status = $this->status($metricsNode);
        $ownerships = $this->parser->ownerships($status->stdout, [$allow->shape, $deny->shape]);

        if (in_array(UfwRuleOwnership::Drift, $ownerships, strict: true)) {
            $this->ownershipDrift();
        }

        if (
            $ownerships === [UfwRuleOwnership::Exact, UfwRuleOwnership::Exact]
            && $this->ordered($status->stdout)
        ) {
            return false;
        }

        if ($ownerships[1] === UfwRuleOwnership::Exact && ! $this->denyPrecedesTrust($status->stdout)) {
            $this->deleteComment($metricsNode, $status->stdout, MetricsFootprint::PublicationFirewallDenyComment);
            $status = $this->status($metricsNode);
            $ownerships[1] = UfwRuleOwnership::Missing;
        }

        if ($ownerships[1] === UfwRuleOwnership::Missing) {
            $this->insert($metricsNode, $deny, 1);
            $status = $this->status($metricsNode);

            if ($this->parser->ownership($status->stdout, $deny->shape) !== UfwRuleOwnership::Exact) {
                throw new ResourceOperationException(
                    'metrics.publication_firewall_verify_failed',
                    'The Metrics Grafana isolation rule could not be verified.',
                    502,
                );
            }

            if (! $this->denyPrecedesTrust($status->stdout)) {
                $this->orderingDrift();
            }
        }

        if ($this->parser->ownership($status->stdout, $allow->shape) === UfwRuleOwnership::Exact) {
            $allowNumber = $this->singleRuleNumber($status->stdout, MetricsFootprint::PublicationFirewallComment);
            $denyNumber = $this->singleRuleNumber($status->stdout, MetricsFootprint::PublicationFirewallDenyComment);

            if ($allowNumber > $denyNumber) {
                $this->deleteComment($metricsNode, $status->stdout, MetricsFootprint::PublicationFirewallComment);
            }
        }

        $status = $this->status($metricsNode);

        if ($this->parser->ownership($status->stdout, $allow->shape) === UfwRuleOwnership::Missing) {
            $this->insert($metricsNode, $allow, 1);
        }

        $verification = $this->status($metricsNode);
        $verified = $this->parser->ownerships($verification->stdout, [$allow->shape, $deny->shape]);

        if (
            $verified !== [UfwRuleOwnership::Exact, UfwRuleOwnership::Exact]
            || ! $this->ordered($verification->stdout)
        ) {
            throw new ResourceOperationException(
                'metrics.publication_firewall_verify_failed',
                'The ordered Metrics Grafana firewall boundary could not be verified.',
                502,
            );
        }

        return true;
    }

    public function remove(Node $metricsNode, string $gatewayAddress): void
    {
        $allow = $this->firewallRules->metricsGrafanaUpstream($metricsNode, $gatewayAddress);
        $deny = $this->firewallRules->metricsGrafanaIsolation($metricsNode);
        $status = $this->status($metricsNode);
        $ownerships = $this->parser->ownerships($status->stdout, [$allow->shape, $deny->shape]);

        if (in_array(UfwRuleOwnership::Drift, $ownerships, strict: true)) {
            $this->ownershipDrift();
        }

        if ($ownerships === [UfwRuleOwnership::Missing, UfwRuleOwnership::Missing]) {
            return;
        }

        $this->deleteComments($metricsNode, $status->stdout, [
            MetricsFootprint::PublicationFirewallComment,
            MetricsFootprint::PublicationFirewallDenyComment,
        ]);

        $verification = $this->parser->ownerships(
            $this->status($metricsNode)->stdout,
            [$allow->shape, $deny->shape],
        );

        if ($verification !== [UfwRuleOwnership::Missing, UfwRuleOwnership::Missing]) {
            throw new ResourceOperationException(
                'metrics.publication_firewall_remove_verify_failed',
                'A Metrics Grafana firewall rule remained after removal.',
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
        $status = $this->status($metricsNode)->stdout;
        $numbers = $this->ownedRuleNumbers($status);

        if ($numbers === []) {
            return;
        }

        rsort($numbers, SORT_NUMERIC);

        foreach ($numbers as $number) {
            $this->delete($metricsNode, $number);
        }

        if ($this->ownedRuleNumbers($this->status($metricsNode)->stdout) !== []) {
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
            user: $node->user,
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
    private function ruleNumbers(string $status, string $comment): array
    {
        $numbers = [];
        $suffix = '# '.$comment;

        foreach (explode("\n", $status) as $line) {
            $matches = [];

            if (
                str_ends_with(rtrim($line), $suffix)
                && preg_match('/^\s*\[\s*(\d+)\]/', $line, $matches) === 1
            ) {
                $numbers[] = $matches[1];
            }
        }

        return $numbers;
    }

    /** @return list<string> */
    private function ownedRuleNumbers(string $status): array
    {
        return [
            ...$this->ruleNumbers($status, MetricsFootprint::PublicationFirewallComment),
            ...$this->ruleNumbers($status, MetricsFootprint::PublicationFirewallDenyComment),
        ];
    }

    private function singleRuleNumber(string $status, string $comment): int
    {
        $numbers = $this->ruleNumbers($status, $comment);

        if (count($numbers) !== 1) {
            $this->ownershipDrift();
        }

        return (int) $numbers[0];
    }

    /** @param list<string> $comments */
    private function deleteComments(Node $node, string $status, array $comments): void
    {
        $numbers = [];

        foreach ($comments as $comment) {
            array_push($numbers, ...$this->ruleNumbers($status, $comment));
        }

        rsort($numbers, SORT_NUMERIC);

        foreach ($numbers as $number) {
            $this->delete($node, $number);
        }
    }

    private function deleteComment(Node $node, string $status, string $comment): void
    {
        $number = $this->singleRuleNumber($status, $comment);
        $this->delete($node, (string) $number);
    }

    private function delete(Node $node, string $number): void
    {
        $this->run(
            $node,
            new RemoteCommand(['sudo', 'ufw', '--force', 'delete', $number]),
            'metrics.publication_firewall_remove_failed',
            'The Metrics Grafana firewall rule could not be removed.',
        );
    }

    private function insert(Node $node, UfwManagedRule $rule, int $position): void
    {
        $arguments = $rule->arguments;
        array_splice($arguments, 2, 0, ['insert', (string) $position]);

        $this->run(
            $node,
            new RemoteCommand($arguments),
            'metrics.publication_firewall_apply_failed',
            'The Metrics Grafana firewall rule could not be applied.',
        );
    }

    private function ordered(string $status): bool
    {
        $allow = $this->singleRuleNumber($status, MetricsFootprint::PublicationFirewallComment);
        $deny = $this->singleRuleNumber($status, MetricsFootprint::PublicationFirewallDenyComment);

        return $allow < $deny && $this->denyPrecedesTrust($status);
    }

    private function denyPrecedesTrust(string $status): bool
    {
        $deny = $this->singleRuleNumber($status, MetricsFootprint::PublicationFirewallDenyComment);
        $trust = $this->ruleNumbers($status, self::WireGuardTrustComment);

        return $trust === [] || $deny < (int) min($trust);
    }

    private function ownershipDrift(): never
    {
        throw new ResourceOperationException(
            'metrics.publication_firewall_ownership_drift',
            'Metrics Grafana firewall ownership cannot be proved.',
            409,
        );
    }

    private function orderingDrift(): never
    {
        throw new ResourceOperationException(
            'metrics.publication_firewall_ordering_drift',
            'Metrics Grafana firewall ordering cannot be proved.',
            409,
        );
    }
}
