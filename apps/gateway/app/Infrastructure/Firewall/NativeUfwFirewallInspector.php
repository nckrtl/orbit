<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallInspectionData;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Domain\Firewall\FirewallSource;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\FirewallRule;

final readonly class NativeUfwFirewallInspector implements FirewallInspector
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private CommandDeadline $deadline = new CommandDeadline,
        private UfwStatusParser $parser = new UfwStatusParser,
    ) {}

    public function inspect(FirewallRule $rule): FirewallInspectionData
    {
        $rule->loadMissing('node');
        $node = $rule->node;
        $host = $node->wireguard_address;
        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }
        try {
            $r = $this->ssh->execute(
                new SshConnection(
                    $host,
                    'orbit',
                    22,
                    $this->keys->privateKeyPath(),
                    $this->knownHosts->path(),
                    commandTimeout: $this->deadline->cap(30.0),
                ),
                new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']),
            );
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }
        if (! $r->succeeded() || $r->truncated) {
            throw new DoctorInspectionException;
        }
        if (preg_match('/\AStatus:\s+inactive\s*$/mi', $r->stdout) === 1) {
            return new FirewallInspectionData(FirewallBackendStatus::Inactive, FirewallRuleInspectionStatus::Missing);
        }
        if (preg_match('/\AStatus:\s+absent\s*$/mi', $r->stdout) === 1) {
            return new FirewallInspectionData(FirewallBackendStatus::Absent, FirewallRuleInspectionStatus::Missing);
        }
        if (preg_match('/\AStatus:\s+active\s*$/mi', $r->stdout) !== 1) {
            throw new DoctorInspectionException;
        }
        try {
            $family = FirewallSource::family($rule->source);
            $o = $this->parser->ownership(
                $r->stdout,
                new UfwRuleShape(
                    "orbit:node:{$rule->node_id}:firewall:{$rule->name}",
                    $rule->action->value,
                    'in',
                    $rule->source,
                    'any',
                    $rule->port,
                    $rule->protocol,
                    null,
                    null,
                    $family === 'both' ? null : $family,
                ),
            );
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        return new FirewallInspectionData(FirewallBackendStatus::Active, match ($o) {
            UfwRuleOwnership::Exact => FirewallRuleInspectionStatus::Exact,
            UfwRuleOwnership::Missing => FirewallRuleInspectionStatus::Missing,
            UfwRuleOwnership::Drift => FirewallRuleInspectionStatus::Drift,
        });
    }
}
