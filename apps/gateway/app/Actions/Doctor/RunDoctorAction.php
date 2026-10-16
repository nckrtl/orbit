<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorInspectionScope;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeDoctorIssueCode;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

final readonly class RunDoctorAction
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private NodeStateInspector $nodeInspector,
        private NodeDoctorProbe $nodeProbe,
        private RoleDoctorProbe $roleProbe,
        private ProjectDoctorProbe $appProbe,
        private InstanceDoctorProbe $instanceProbe,
        private ScheduleDoctorProbe $scheduleProbe,
        private ToolDoctorProbe $toolProbe,
        private ProcessDoctorProbe $processProbe,
        private FirewallDoctorProbe $firewallProbe,
        private DatabaseConnectionDoctorProbe $databaseConnectionProbe,
        private RouteDoctorProbe $routeProbe,
        private DatabaseServerDoctorProbe $databaseServerProbe,
    ) {}

    /** @param list<DoctorFamily> $families */
    public function execute(Node $consumer, ?int $nodeId, array $families): DoctorReportData
    {
        return $this->report($this->contexts($this->nodes($consumer, $nodeId)), $families);
    }

    /**
     * Inspect every Node and, unless a subset is named, every family.
     * Peer access grants do not drop Nodes from this fleet.
     *
     * @param  list<DoctorFamily>  $families
     */
    public function executeForFleet(array $families = []): DoctorReportData
    {
        return $this->report($this->contexts(Node::query()->orderBy('name')->orderBy('id')->get()), $families);
    }

    /**
     * Inspect one Node without an access check, for the Gateway's own verification such as the fleet
     * rollout (ADR 0202).
     *
     * @param  list<DoctorFamily>  $families
     */
    public function executeForNode(Node $node, array $families = []): DoctorReportData
    {
        return $this->report($this->contexts(new Collection([$node])), $families);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return array<int, DoctorNodeContext>
     */
    private function contexts(Collection $nodes): array
    {
        $selected = [];

        foreach ($nodes as $node) {
            $selected[$node->id] = $this->context($node);
        }

        return $selected;
    }

    /**
     * @param  array<int, DoctorNodeContext>  $selected
     * @param  list<DoctorFamily>  $families
     */
    private function report(array $selected, array $families): DoctorReportData
    {
        $scope = new DoctorInspectionScope($selected);
        $nodeReports = [];

        foreach ($selected as $context) {
            $context = $context->withScope($scope);
            $familyReports = [];

            foreach ($this->families($families) as $family) {
                $familyReports[] = $this->parked($context->node)
                    ? $this->parkedReport($family, $context->node)
                    : $this->probe($family)->inspect($context);
            }

            $nodeReports[] = DoctorNodeReportData::fromFamilies(
                $context->node->id,
                $context->node->name,
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

        $nodes = new Collection([$node]);

        return $nodes;
    }

    /**
     * A parked task VM is stopped on purpose, so SSH cannot reach it. Doctor does not inspect it: the `node`
     * family reports one informational `node.task_vm_parked`, and the other families check nothing.
     */
    private function parked(Node $node): bool
    {
        return TaskVm::query()->where('node_id', $node->id)->where('state', TaskVmState::Parked)->exists();
    }

    private function parkedReport(DoctorFamily $family, Node $node): DoctorFamilyReportData
    {
        return $family !== DoctorFamily::Node
            ? DoctorFamilyReportData::fromIssues($family, 0, [])
            : DoctorFamilyReportData::fromIssues(DoctorFamily::Node, 1, [new DoctorIssueData(
                NodeDoctorIssueCode::TaskVmParked, DoctorIssueKind::Informational, 'node', $node->id, $node->name,
                'The Node is a parked task VM. Orbit starts it for its group\'s next work.', expected: null, observed: 'parked',
            )]);
    }

    private function context(Node $node): DoctorNodeContext
    {
        if ($this->parked($node)) {
            return new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null));
        }
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
     * @param  list<DoctorFamily>  $requested
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
            DoctorFamily::Project => $this->appProbe,
            DoctorFamily::Instance => $this->instanceProbe,
            DoctorFamily::Schedule => $this->scheduleProbe,
            DoctorFamily::Tool => $this->toolProbe,
            DoctorFamily::Process => $this->processProbe,
            DoctorFamily::Firewall => $this->firewallProbe,
            DoctorFamily::DatabaseConnection => $this->databaseConnectionProbe,
            DoctorFamily::Route => $this->routeProbe,
            DoctorFamily::DatabaseServer => $this->databaseServerProbe,
        };
    }
}
