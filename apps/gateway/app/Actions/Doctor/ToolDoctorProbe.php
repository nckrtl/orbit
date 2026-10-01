<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstalledPackageInventory;
use App\Domain\Doctor\ToolDoctorIssueCode;
use App\Domain\Tools\ToolInspectionException;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\VersionConstraint;
use App\Models\Tool;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Throwable;

final readonly class ToolDoctorProbe implements DoctorFamilyProbe
{
    private const string VERSION_TOKEN = '/\A[A-Za-z0-9._+:-]{1,64}\z/D';

    public function __construct(
        private ToolInspector $inspector,
        private VersionConstraint $constraints,
        private InstalledPackageInventory $inventory,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Tool;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $tools = Tool::query()
            ->with(['node', 'manager'])
            ->where('node_id', $context->node->id)
            ->orderBy('id')
            ->get();

        if (! $context->inspection->reachable) {
            if ($tools->isEmpty()) {
                return DoctorFamilyReportData::fromIssues(DoctorFamily::Tool, 0, []);
            }

            return DoctorFamilyReportData::fromIssues(DoctorFamily::Tool, $tools->count(), [
                new DoctorIssueData(
                    ToolDoctorIssueCode::NodeUnreachable,
                    DoctorIssueKind::Unverifiable,
                    'tool',
                    null,
                    null,
                    'Tool node is unreachable.',
                    null,
                    'unreachable',
                ),
            ]);
        }

        return DoctorFamilyReportData::fromIssues(
            DoctorFamily::Tool,
            $tools->count(),
            [...$this->registeredIssues($tools), ...$this->discoveryIssues($context, $tools)],
        );
    }

    /**
     * @param  Collection<int, Tool>  $tools
     * @return list<DoctorIssueData>
     */
    private function registeredIssues(Collection $tools): array
    {
        if ($tools->isEmpty()) {
            return [];
        }

        $issues = [];
        $tools = $tools->values();
        $outcomes = $this->inspector->inspectMany(array_values($tools->all()));

        foreach ($tools as $index => $tool) {
            try {
                $inspection = $outcomes[$index]->data();
                if (! $inspection->installed) {
                    $issues[] = new DoctorIssueData(
                        ToolDoctorIssueCode::NotInstalled,
                        DoctorIssueKind::Drift,
                        'tool',
                        $tool->id,
                        null,
                        'Managed tool is not installed.',
                        true,
                        false,
                    );

                    continue;
                }

                $constraint = $tool->getAttribute('version_constraint');
                if ($constraint !== null && ! is_string($constraint)) {
                    $issues[] = new DoctorIssueData(
                        ToolDoctorIssueCode::InspectionFailed,
                        DoctorIssueKind::Unverifiable,
                        'tool',
                        (int) $tool->id,
                        null,
                        'Tool inspection could not be verified.',
                        'verifiable',
                        'unverifiable',
                    );

                    continue;
                }

                if ($constraint === null) {
                    continue;
                }

                if (
                    ! $this->constraints->isValid($constraint)
                    || $inspection->normalizedVersion === null
                ) {
                    $issues[] = new DoctorIssueData(
                        ToolDoctorIssueCode::InspectionFailed,
                        DoctorIssueKind::Unverifiable,
                        'tool',
                        $tool->id,
                        null,
                        'Tool inspection could not be verified.',
                        'verifiable',
                        'unverifiable',
                    );

                    continue;
                }

                if (! $this->constraints->allows($inspection->normalizedVersion, $constraint)) {
                    $issues[] = new DoctorIssueData(
                        ToolDoctorIssueCode::VersionMismatch,
                        DoctorIssueKind::Drift,
                        'tool',
                        $tool->id,
                        null,
                        'Installed tool version does not satisfy intent.',
                        'satisfied',
                        'rejected',
                    );
                }
            } catch (ToolInspectionException) {
                $issues[] = new DoctorIssueData(
                    ToolDoctorIssueCode::InspectionFailed,
                    DoctorIssueKind::Unverifiable,
                    'tool',
                    $tool->id,
                    null,
                    'Tool inspection could not be verified.',
                    'verifiable',
                    'unverifiable',
                );
            }
        }

        return $issues;
    }

    /**
     * @param  Collection<int, Tool>  $tools
     * @return list<DoctorIssueData>
     */
    private function discoveryIssues(DoctorNodeContext $context, Collection $tools): array
    {
        try {
            $scans = $this->inventory->inspect($context->node);
        } catch (Throwable) {
            return [$this->inventoryInspectionFailed()];
        }

        $expected = [ToolManagerName::Brew, ToolManagerName::BrewCask, ToolManagerName::Vp];

        if (count($scans) !== count($expected)) {
            return [$this->inventoryInspectionFailed()];
        }

        foreach ($expected as $index => $manager) {
            if ($scans[$index]->manager !== $manager) {
                return [$this->inventoryInspectionFailed()];
            }
        }

        $scanIssues = [];
        $packages = [];

        foreach ($scans as $scan) {
            if ($scan->scanState === ToolInventoryScanState::Complete) {
                foreach ($scan->packages as $package) {
                    if (! $package->registered) {
                        $packages[] = $package;
                    }
                }

                continue;
            }

            if ($scan->scanState === ToolInventoryScanState::Unsupported) {
                continue;
            }

            $scanIssues[] = $this->scanIssue($scan, $this->managerIsUsed($tools, $scan->manager));
        }

        usort(
            $packages,
            static function (ToolInventoryPackage $left, ToolInventoryPackage $right): int {
                $rank = static fn (ToolManagerName $manager): int => match ($manager) {
                    ToolManagerName::Brew => 0,
                    ToolManagerName::BrewCask => 1,
                    ToolManagerName::Vp => 2,
                    default => 3,
                };

                return [$rank($left->manager), $left->package] <=> [$rank($right->manager), $right->package];
            },
        );

        $packageIssues = [];

        foreach ($packages as $package) {
            $packageIssues[] = new DoctorIssueData(
                ToolDoctorIssueCode::PackageUnregistered,
                DoctorIssueKind::Informational,
                'tool',
                null,
                $package->package,
                'Installed package has no Tool row.',
                $package->manager->value,
                $this->packageFact($package),
            );
        }

        return [...$scanIssues, ...$packageIssues];
    }

    private function scanIssue(ToolInventoryScan $scan, bool $managerIsUsed): DoctorIssueData
    {
        $informational = match ($scan->scanState) {
            ToolInventoryScanState::Absent => true,
            ToolInventoryScanState::Incomplete => false,
            ToolInventoryScanState::Conflicting => ! $managerIsUsed,
            ToolInventoryScanState::Complete, ToolInventoryScanState::Unsupported => throw new LogicException(
                'A finished inventory scan has no scan finding.',
            ),
        };

        return new DoctorIssueData(
            ToolDoctorIssueCode::InventoryScan,
            $informational ? DoctorIssueKind::Informational : DoctorIssueKind::Unverifiable,
            'tool',
            null,
            $scan->manager->value,
            'Package inventory scan is not complete.',
            'complete',
            $scan->scanState->value,
        );
    }

    /** @param Collection<int, Tool> $tools */
    private function managerIsUsed(Collection $tools, ToolManagerName $manager): bool
    {
        return $tools->contains(
            static fn (Tool $tool): bool => $tool->manager->name === $manager->value,
        );
    }

    private function packageFact(ToolInventoryPackage $package): string
    {
        $version = $package->installedVersion;
        if (! is_string($version) || preg_match(self::VERSION_TOKEN, $version) !== 1) {
            $version = 'unknown';
        }

        $block = $package->adoption === ToolInventoryPackage::SUPPORTED ? 'none' : $package->adoptionBlock;
        if (! is_string($block) || $block === '') {
            $block = 'none';
        }

        return 'version='.$version
            .';dependency='.($package->dependency ? 'yes' : 'no')
            .';adoption='.$package->adoption
            .';block='.$block;
    }

    private function inventoryInspectionFailed(): DoctorIssueData
    {
        return new DoctorIssueData(
            ToolDoctorIssueCode::InspectionFailed,
            DoctorIssueKind::Unverifiable,
            'tool',
            null,
            null,
            'Tool inspection could not be verified.',
            'verifiable',
            'unverifiable',
        );
    }
}
