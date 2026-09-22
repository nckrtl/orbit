<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallInspectionBatchData;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Domain\Firewall\LiveFirewallBackendStatus;

final readonly class NativeUfwFirewallInspector implements FirewallInspector
{
    public function __construct(
        private NativeLiveUfwReader $live,
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

        $status = $this->live->read($node);
        $backend = match ($status['backend']) {
            LiveFirewallBackendStatus::Active => FirewallBackendStatus::Active,
            LiveFirewallBackendStatus::Inactive => FirewallBackendStatus::Inactive,
            LiveFirewallBackendStatus::Absent => FirewallBackendStatus::Absent,
            LiveFirewallBackendStatus::Unreachable => throw new DoctorInspectionException,
        };
        if ($backend !== FirewallBackendStatus::Active) {
            return $this->uniformResult($backend, $targets);
        }
        try {
            $ownerships = $this->parser->ownerships(
                $status['stdout'],
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
