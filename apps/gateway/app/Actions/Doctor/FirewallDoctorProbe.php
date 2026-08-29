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
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;

final readonly class FirewallDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private FirewallInspector $inspector,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Firewall;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $rules = FirewallRule::query()->where('node_id', $context->node->id)->orderBy('id')->get();
        if ($rules->isEmpty()) {
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
            if ($rule->status !== LifecycleStatus::Active) {
                $issues[] = $this->issue(
                    $rule,
                    FirewallDoctorIssueCode::LifecycleNotActive,
                    DoctorIssueKind::Drift,
                    'active',
                    $rule->status->value,
                );
                continue;
            }
            try {
                $inspection = $this->inspector->inspect($rule);
            } catch (DoctorInspectionException) {
                $issues[] = $this->issue(
                    $rule,
                    FirewallDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'verifiable',
                    'unverifiable',
                );
                continue;
            }
            if ($inspection->backend !== FirewallBackendStatus::Active) {
                $issues[] = $this->issue(
                    $rule,
                    FirewallDoctorIssueCode::BackendInactive,
                    DoctorIssueKind::Drift,
                    'active',
                    $inspection->backend->value,
                );
                continue;
            }
            if ($inspection->rule !== FirewallRuleInspectionStatus::Exact) {
                $code = $inspection->rule === FirewallRuleInspectionStatus::Missing
                    ? FirewallDoctorIssueCode::RuleMissing
                    : FirewallDoctorIssueCode::RuleMismatch;
                $issues[] = $this->issue($rule, $code, DoctorIssueKind::Drift, 'exact', $inspection->rule->value);
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Firewall, $rules->count(), $issues);
    }

    private function issue(
        FirewallRule $rule,
        FirewallDoctorIssueCode $code,
        DoctorIssueKind $kind,
        string $expected,
        string $observed,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            $kind,
            'firewall',
            $rule->id,
            $rule->name,
            'Firewall rule does not match its managed state.',
            $expected,
            $observed,
        );
    }
}
