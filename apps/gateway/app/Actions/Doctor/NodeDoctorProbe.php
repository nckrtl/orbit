<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeDiskFilesystemData;
use App\Domain\Doctor\NodeDoctorIssueCode;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Models\Node;
use Throwable;

final readonly class NodeDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private ManagedNodeEligibility $eligibility = new ManagedNodeEligibility,
        private ?AgentStateView $view = null,
        private ?WebSocketCredentialManager $websocket = null,
        private ?FleetReleaseLag $releaseLag = null,
    ) {}

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
        if (! $this->eligibility->isManagedForObservation($node)) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Node, 1, $issues);
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
        $expectedPlatform = match (strtolower($node->platform)) {
            'linux' => 'linux',
            'macos' => 'darwin',
            default => null,
        };
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
        foreach ($inspection->diskFilesystems as $filesystem) {
            if (! $this->diskIsLow($filesystem)) {
                continue;
            }
            $issues[] = new DoctorIssueData(
                NodeDoctorIssueCode::DiskLow,
                DoctorIssueKind::Drift,
                'node',
                $node->id,
                $node->name,
                'Node filesystem has low free space or free inodes.',
                expected: 'at least 10% and 2 GiB free space and 10% free inodes',
                observed: sprintf(
                    '%s: %d KiB free of %d KiB; %s',
                    $filesystem->location,
                    $filesystem->availableKiB,
                    $filesystem->sizeKiB,
                    $filesystem->totalInodes === null
                        ? 'inodes unavailable'
                        : sprintf('%d of %d inodes free', $filesystem->freeInodes, $filesystem->totalInodes),
                ),
            );
        }
        if ($this->eligibility->allows($node)) {
            if ($inspection->agentBinaryExists === true && $inspection->agentChecksumMatches === false) {
                $issues[] = new DoctorIssueData(
                    NodeDoctorIssueCode::AgentBinaryMismatch,
                    DoctorIssueKind::Drift,
                    'node',
                    $node->id,
                    $node->name,
                    'Node agent binary does not match the pinned checksum.',
                    expected: true,
                    observed: false,
                );
            }
            if ($inspection->agentBinaryExists === false || $inspection->agentUnitExists === false) {
                $issues[] = new DoctorIssueData(
                    NodeDoctorIssueCode::AgentMissing,
                    DoctorIssueKind::Drift,
                    'node',
                    $node->id,
                    $node->name,
                    'Node agent binary or systemd unit is missing.',
                    expected: true,
                    observed: false,
                );
            }
            if ($inspection->agentUnitExists === true && $inspection->agentActive === false) {
                $issues[] = new DoctorIssueData(
                    NodeDoctorIssueCode::AgentInactive,
                    DoctorIssueKind::Drift,
                    'node',
                    $node->id,
                    $node->name,
                    'Node agent systemd unit is not active.',
                    expected: true,
                    observed: false,
                );
            }
            $secret = $inspection->agentBinaryExists === true ? $this->agentSecretProblem($node, $inspection) : null;
            if ($secret !== null) {
                $issues[] = new DoctorIssueData(
                    NodeDoctorIssueCode::AgentSecretMismatch,
                    DoctorIssueKind::Drift,
                    'node',
                    $node->id,
                    $node->name,
                    'Node agent secret does not match the Gateway record.',
                    expected: 'match',
                    observed: $secret,
                );
            }
            if (new NodeCliState()->isForeign($node)) {
                $issues[] = new DoctorIssueData(
                    NodeDoctorIssueCode::CliForeign,
                    DoctorIssueKind::Drift,
                    'node',
                    $node->id,
                    $node->name,
                    '/usr/local/bin/orbit is a CLI Orbit did not install, so the fleet rollout leaves the Node out.',
                    expected: 'orbit release',
                    observed: 'foreign',
                );
            }
            $lag = ($this->releaseLag ?? app(FleetReleaseLag::class))->observe($node);
            if ($lag !== null) {
                $issues[] = new DoctorIssueData(
                    NodeDoctorIssueCode::ReleaseLag,
                    DoctorIssueKind::Drift,
                    'node',
                    $node->id,
                    $node->name,
                    'The Node does not run the desired fleet state of the Gateway release.',
                    expected: $lag['expected'],
                    observed: $lag['observed'],
                );
            }
            $view = $inspection->agentActive === true ? $this->agentViewProblem($node) : null;
            if ($view !== null) {
                $issues[] = new DoctorIssueData(
                    NodeDoctorIssueCode::AgentViewStale,
                    DoctorIssueKind::Drift,
                    'node',
                    $node->id,
                    $node->name,
                    'The Gateway has no fresh view of this Node agent.',
                    expected: 'fresh',
                    observed: $view,
                );
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Node, 1, $issues);
    }

    private function diskIsLow(NodeDiskFilesystemData $filesystem): bool
    {
        return $filesystem->availableKiB < 2 * 1024 * 1024
            || $filesystem->availableKiB < $filesystem->sizeKiB / 10
            || ($filesystem->totalInodes !== null
                && $filesystem->freeInodes !== null
                && $filesystem->freeInodes < $filesystem->totalInodes / 10);
    }

    /** Reports a missing or mismatched agent secret for an eligible managed Node (ADR 0155). */
    private function agentSecretProblem(Node $node, NodeInspectionData $inspection): ?string
    {
        $stored = $node->agent_secret_hash;

        if ($inspection->agentSecretChecksum === null) {
            return 'missing';
        }

        return is_string($stored) && $stored !== '' && hash_equals($stored, $inspection->agentSecretChecksum) ? null : 'mismatch';
    }

    /**
     * Why the Gateway has no fresh view of the Node's active agent: `subscriber_down`,
     * `disconnected`, `missing`, or `stale`. Null when the view is fresh or no `websocket` role
     * is active, because without Reverb there is nothing to subscribe to.
     */
    private function agentViewProblem(Node $node): ?string
    {
        $nodeId = $node->getKey();

        if (! is_int($nodeId)) {
            return null;
        }

        try {
            if (($this->websocket ?? app(WebSocketCredentialManager::class))->current() === null) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $view = $this->view ?? app(AgentStateView::class);
        $subscriber = $view->subscriber();

        if ($subscriber === null || ! $subscriber->isCurrent()) {
            return 'subscriber_down';
        }

        if (! $subscriber->connected) {
            return 'disconnected';
        }

        return match ($view->node($nodeId)->freshness) {
            AgentViewFreshness::Fresh => null,
            AgentViewFreshness::Stale => 'stale',
            AgentViewFreshness::Missing => 'missing',
        };
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
