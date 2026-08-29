<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

final readonly class RunDoctorAction
{
    /** @mago-expect lint:excessive-parameter-list The closed Doctor family set keeps every probe dependency explicit. */
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private NodeStateInspector $nodeInspector,
        private NodeDoctorProbe $nodeProbe,
        private RoleDoctorProbe $roleProbe,
        private AppDoctorProbe $appProbe,
        private InstanceDoctorProbe $instanceProbe,
        private WorkspaceDoctorProbe $workspaceProbe,
        private ToolDoctorProbe $toolProbe,
        private ProcessDoctorProbe $processProbe,
        private FirewallDoctorProbe $firewallProbe,
    ) {}

    /** @param list<DoctorFamily> $families */
    public function execute(Node $consumer, ?int $nodeId, array $families): DoctorReportData
    {
        $nodeReports = [];

        foreach ($this->nodes($consumer, $nodeId) as $node) {
            $context = $this->context($node);
            $familyReports = [];

            foreach ($this->families($families) as $family) {
                $familyReports[] = $this->probe($family)->inspect($context);
            }

            $nodeReports[] = DoctorNodeReportData::fromFamilies(
                $node->id,
                $node->name,
                $familyReports,
            );
        }

        return DoctorReportData::fromNodes($nodeReports);
    }

    /** @return Collection<int, Node> */
    private function nodes(Node $consumer, ?int $nodeId): Collection
    {
        if ($nodeId === null) {
            return Node::query()
                ->whereIn('id', $this->authorizer->accessibleNodeIds($consumer))
                ->orderBy('name')
                ->orderBy('id')
                ->get();
        }

        $node = Node::query()->findOrFail($nodeId);

        if (! $this->authorizer->allows($consumer, $node)) {
            throw new AuthorizationException;
        }

        /** @var Collection<int, Node> $nodes */
        $nodes = new Collection([$node]);

        return $nodes;
    }

    private function context(Node $node): DoctorNodeContext
    {
        try {
            return new DoctorNodeContext($node, $this->nodeInspector->inspect($node));
        } catch (DoctorInspectionException) {
            return new DoctorNodeContext(
                $node,
                new NodeInspectionData(false, null, null, null),
                inspectionFailed: true,
            );
        }
    }

    /**
     * @param list<DoctorFamily> $requested
     *
     * @return list<DoctorFamily>
     */
    private function families(array $requested): array
    {
        if ($requested === []) {
            return DoctorFamily::cases();
        }

        return array_values(array_filter(
            DoctorFamily::cases(),
            static fn (DoctorFamily $family): bool => in_array($family, $requested, strict: true),
        ));
    }

    private function probe(DoctorFamily $family): DoctorFamilyProbe
    {
        return match ($family) {
            DoctorFamily::Node => $this->nodeProbe,
            DoctorFamily::Role => $this->roleProbe,
            DoctorFamily::App => $this->appProbe,
            DoctorFamily::Instance => $this->instanceProbe,
            DoctorFamily::Workspace => $this->workspaceProbe,
            DoctorFamily::Tool => $this->toolProbe,
            DoctorFamily::Process => $this->processProbe,
            DoctorFamily::Firewall => $this->firewallProbe,
        };
    }
}
