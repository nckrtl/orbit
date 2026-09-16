<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorInspectionScope;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

final readonly class RunDoctorAction
{
    public function __construct(
        private NodeAccessAuthorizer $authorizer,
        private NodeStateInspector $nodeInspector,
        private NodeDoctorProbe $nodeProbe,
        private RoleDoctorProbe $roleProbe,
        private AppDoctorProbe $appProbe,
        private InstanceDoctorProbe $instanceProbe,
        private ScheduleDoctorProbe $scheduleProbe,
        private ToolDoctorProbe $toolProbe,
        private ProcessDoctorProbe $processProbe,
        private FirewallDoctorProbe $firewallProbe,
        private HerdrSessionDoctorProbe $herdrProbe,
        private DatabaseConnectionDoctorProbe $databaseConnectionProbe,
        private RouteDoctorProbe $routeProbe,
    ) {}

    /** @param list<DoctorFamily> $families */
    public function execute(Node $consumer, ?int $nodeId, array $families): DoctorReportData
    {
        $nodeReports = [];
        $selected = [];

        foreach ($this->nodes($consumer, $nodeId) as $node) {
            $selected[$node->id] = $this->context($node);
        }

        $scope = new DoctorInspectionScope($selected);

        foreach ($selected as $context) {
            $context = $context->withScope($scope);
            $familyReports = [];

            foreach ($this->families($families) as $family) {
                $familyReports[] = $this->probe($family)->inspect($context);
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
            DoctorFamily::App => $this->appProbe,
            DoctorFamily::Instance => $this->instanceProbe,
            DoctorFamily::Schedule => $this->scheduleProbe,
            DoctorFamily::Tool => $this->toolProbe,
            DoctorFamily::Process => $this->processProbe,
            DoctorFamily::Firewall => $this->firewallProbe,
            DoctorFamily::Herdr => $this->herdrProbe,
            DoctorFamily::DatabaseConnection => $this->databaseConnectionProbe,
            DoctorFamily::Route => $this->routeProbe,
        };
    }
}
