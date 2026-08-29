<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeDoctorIssueCode;
use App\Domain\Shared\LifecycleStatus;

/** @mago-expect lint:cyclomatic-complexity The probe keeps each stable node issue branch explicit. */
final readonly class NodeDoctorProbe implements DoctorFamilyProbe
{
    public function family(): DoctorFamily
    {
        return DoctorFamily::Node;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $node = $context->node;
        $inspection = $context->inspection;
        $issues = [];
        if ($node->status !== LifecycleStatus::Active) {
            $issues[] = new DoctorIssueData(
                NodeDoctorIssueCode::LifecycleNotActive,
                DoctorIssueKind::Drift,
                'node',
                $node->id,
                $node->name,
                'Node lifecycle is not active.',
                expected: 'active',
                observed: $node->status->value,
            );
        }
        if ($context->inspectionFailed) {
            $issues[] = new DoctorIssueData(
                NodeDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                'node',
                $node->id,
                $node->name,
                'Node observation failed.',
                expected: null,
                observed: null,
            );

            return DoctorFamilyReportData::fromIssues(DoctorFamily::Node, 1, $issues);
        }
        if (! $inspection->reachable) {
            $issues[] = new DoctorIssueData(
                NodeDoctorIssueCode::SshUnreachable,
                DoctorIssueKind::Unverifiable,
                'node',
                $node->id,
                $node->name,
                'Node could not be reached through SSH.',
                expected: true,
                observed: false,
            );

            return DoctorFamilyReportData::fromIssues(DoctorFamily::Node, 1, $issues);
        }
        $expectedPlatform = strtolower($node->platform) === 'linux' ? 'linux' : null;
        $expectedArchitecture = $this->managedArchitecture($node->architecture);
        $managedIdentitySupported =
            $expectedPlatform !== null && ($node->architecture === null || $expectedArchitecture !== null);
        if (! $managedIdentitySupported) {
            $issues[] = new DoctorIssueData(
                NodeDoctorIssueCode::InspectionFailed,
                DoctorIssueKind::Unverifiable,
                'node',
                $node->id,
                $node->name,
                'Node managed identity cannot be verified.',
                expected: 'supported',
                observed: 'unsupported',
            );
        }
        if ($expectedPlatform !== null && $this->observedPlatform($inspection->platform) !== $expectedPlatform) {
            $issues[] = new DoctorIssueData(
                NodeDoctorIssueCode::PlatformMismatch,
                DoctorIssueKind::Drift,
                'node',
                $node->id,
                $node->name,
                'Node platform does not match.',
                expected: $expectedPlatform,
                observed: $this->observedPlatform($inspection->platform),
            );
        }
        if (
            ($node->architecture === null
            || $expectedArchitecture !== null)
            && $this->observedArchitecture($inspection->architecture) !== $expectedArchitecture
        ) {
            $issues[] = new DoctorIssueData(
                NodeDoctorIssueCode::ArchitectureMismatch,
                DoctorIssueKind::Drift,
                'node',
                $node->id,
                $node->name,
                'Node architecture does not match.',
                expected: $expectedArchitecture,
                observed: $this->observedArchitecture($inspection->architecture),
            );
        }
        if ($inspection->wireGuardAddressMatches !== true) {
            $issues[] = new DoctorIssueData(
                NodeDoctorIssueCode::WireGuardAddressMismatch,
                DoctorIssueKind::Drift,
                'node',
                $node->id,
                $node->name,
                'Managed WireGuard address is not present.',
                expected: true,
                observed: $inspection->wireGuardAddressMatches,
            );
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Node, 1, $issues);
    }

    private function managedArchitecture(?string $architecture): ?string
    {
        return match ($architecture === null ? null : strtolower($architecture)) {
            null => null,
            'amd64', 'x86_64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => null,
        };
    }

    private function observedPlatform(?string $platform): ?string
    {
        $normalized = $platform === null ? null : strtolower($platform);

        return match ($normalized) {
            null => null,
            'linux', 'darwin' => $normalized,
            default => 'other',
        };
    }

    private function observedArchitecture(?string $architecture): ?string
    {
        return match ($architecture === null ? null : strtolower($architecture)) {
            null => null,
            'amd64', 'x86_64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => 'other',
        };
    }
}
