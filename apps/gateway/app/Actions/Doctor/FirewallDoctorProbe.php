<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\FirewallDoctorIssueCode;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;

final readonly class FirewallDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private FirewallInspector $inspector,
        private MetricsFirewallExpectationProvider $expectations,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Firewall;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $rules = FirewallRule::query()->where('node_id', $context->node->id)->orderBy('id')->get();
        $expectations = $this->expectations->for($context->node);

        if ($rules->isEmpty() && $expectations === []) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Firewall, 0, []);
        }

        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Firewall, $rules->count(), [new DoctorIssueData(
                FirewallDoctorIssueCode::NodeUnreachable,
                DoctorIssueKind::Unverifiable,
                'firewall',
                null,
                null,
                'Firewall state cannot be inspected because the node is unreachable.',
                'reachable',
                'unreachable',
            )]);
        }
        $issues = [];
        foreach ($rules as $rule) {
            $target = FirewallInspectionTarget::fromRule($rule);

            if ($rule->status !== LifecycleStatus::Active) {
                $issues[] = $this->issue(
                    $target,
                    FirewallDoctorIssueCode::LifecycleNotActive,
                    DoctorIssueKind::Drift,
                    'active',
                    $rule->status->value,
                );
                continue;
            }
            $issue = $this->inspectTarget($target);

            if ($issue instanceof DoctorIssueData) {
                $issues[] = $issue;
            }
        }

        foreach ($expectations as $target) {
            $issue = $this->inspectTarget($target);

            if ($issue instanceof DoctorIssueData) {
                $issues[] = $issue;
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Firewall, $rules->count(), $issues);
    }

    private function inspectTarget(FirewallInspectionTarget $target): ?DoctorIssueData
    {
        try {
            $inspection = $this->inspector->inspect($target);
        } catch (DoctorInspectionException) {
            return $this->issue(
                $target,
                FirewallDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                'verifiable',
                'unverifiable',
            );
        }

        if ($inspection->backend !== FirewallBackendStatus::Active) {
            return $this->issue(
                $target,
                FirewallDoctorIssueCode::BackendInactive,
                DoctorIssueKind::Drift,
                'active',
                $inspection->backend->value,
            );
        }

        if ($inspection->rule === FirewallRuleInspectionStatus::Exact) {
            return null;
        }

        $code = $inspection->rule === FirewallRuleInspectionStatus::Missing
            ? FirewallDoctorIssueCode::RuleMissing
            : FirewallDoctorIssueCode::RuleMismatch;

        return $this->issue(
            $target,
            $code,
            DoctorIssueKind::Drift,
            'exact',
            $inspection->rule->value,
        );
    }

    private function issue(
        FirewallInspectionTarget $target,
        FirewallDoctorIssueCode $code,
        DoctorIssueKind $kind,
        string $expected,
        string $observed,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            $kind,
            'firewall',
            $target->resourceId,
            $target->resourceName,
            'Firewall rule does not match its managed state.',
            $expected,
            $observed,
        );
    }
}
