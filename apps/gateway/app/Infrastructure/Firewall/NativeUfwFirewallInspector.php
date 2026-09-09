<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallInspectionBatchData;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;

final readonly class NativeUfwFirewallInspector implements FirewallInspector
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private CommandDeadline $deadline = new CommandDeadline,
        private UfwStatusParser $parser = new UfwStatusParser,
    ) {}

    public function inspect(array $targets): FirewallInspectionBatchData
    {
        $node = $targets[0]->node;

        foreach ($targets as $target) {
            if (! $target->node->is($node)) {
                throw new DoctorInspectionException;
            }
        }

        $host = $node->wireguard_ip;
        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }
        try {
            $r = $this->ssh->execute(
                new SshConnection(
                    $host,
                    $node->user,
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
            return $this->uniformResult(FirewallBackendStatus::Inactive, $targets);
        }
        if (preg_match('/\AStatus:\s+absent\s*$/mi', $r->stdout) === 1) {
            return $this->uniformResult(FirewallBackendStatus::Absent, $targets);
        }
        if (preg_match('/\AStatus:\s+active\s*$/mi', $r->stdout) !== 1) {
            throw new DoctorInspectionException;
        }
        try {
            $ownerships = $this->parser->ownerships(
                $r->stdout,
                array_map(
                    static fn (FirewallInspectionTarget $target): UfwRuleShape => new UfwRuleShape(
                        $target->shape->comment,
                        $target->shape->action,
                        $target->shape->direction,
                        $target->shape->source,
                        $target->shape->destination,
                        $target->shape->port,
                        $target->shape->protocol,
                        $target->shape->inInterface,
                        $target->shape->outInterface,
                        $target->shape->family,
                    ),
                    $targets,
                ),
            );
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        return new FirewallInspectionBatchData(
            FirewallBackendStatus::Active,
            array_map(static fn (UfwRuleOwnership $ownership): FirewallRuleInspectionStatus => match ($ownership) {
                UfwRuleOwnership::Exact => FirewallRuleInspectionStatus::Exact,
                UfwRuleOwnership::Missing => FirewallRuleInspectionStatus::Missing,
                UfwRuleOwnership::Drift => FirewallRuleInspectionStatus::Drift,
            }, $ownerships),
        );
    }

    /** @param non-empty-list<FirewallInspectionTarget> $targets */
    private function uniformResult(
        FirewallBackendStatus $backend,
        array $targets,
    ): FirewallInspectionBatchData {
        return new FirewallInspectionBatchData(
            $backend,
            array_fill(0, count($targets), FirewallRuleInspectionStatus::Missing),
        );
    }
}
