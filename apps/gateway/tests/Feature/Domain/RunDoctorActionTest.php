<?php

declare(strict_types=1);

use App\Actions\Doctor\RunDoctorAction;
use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

describe('RunDoctorAction', function (): void {
    it('returns a healthy empty report without inspecting nodes', function (): void {
        $inspector = bind_run_doctor_inspector();
        $consumer = new Node(['id' => 999_999]);

        $report = app(RunDoctorAction::class)->execute($consumer, null, [DoctorFamily::Node]);

        expect($report->healthy)
            ->toBeTrue()
            ->and($report->nodes)
            ->toBe([])
            ->and($report->summary)
            ->toBe([
                'nodes' => 0,
                'families' => 0,
                'checks' => 0,
                'drift' => 0,
                'unverifiable' => 0,
            ])
            ->and($inspector->nodeIds)
            ->toBe([]);
    });

    it('selects only accessible nodes in name and ID order', function (): void {
        $consumer = run_doctor_node('consumer');
        $firstAlpha = run_doctor_node('alpha-a');
        $secondAlpha = run_doctor_node('alpha-b');
        $beta = run_doctor_node('beta');
        run_doctor_node('inaccessible');
        $consumer->accessibleNodes()->attach([$secondAlpha->id, $beta->id, $firstAlpha->id]);
        $inspector = bind_run_doctor_inspector();

        $report = app(RunDoctorAction::class)->execute($consumer, null, [DoctorFamily::Node]);

        expect(array_map(
            static fn (DoctorNodeReportData $node): int => $node->nodeId,
            $report->nodes,
        ))
            ->toBe([$firstAlpha->id, $secondAlpha->id, $beta->id])
            ->and($inspector->nodeIds)
            ->toBe([$firstAlpha->id, $secondAlpha->id, $beta->id]);
    });

    it('selects one accessible filtered node', function (): void {
        $consumer = run_doctor_node('consumer');
        $selected = run_doctor_node('selected');
        $other = run_doctor_node('other');
        $consumer->accessibleNodes()->attach([$selected->id, $other->id]);
        $inspector = bind_run_doctor_inspector();

        $report = app(RunDoctorAction::class)->execute(
            $consumer,
            $selected->id,
            [DoctorFamily::Node],
        );

        expect($report->nodes)
            ->toHaveCount(1)
            ->and($report->nodes[0]->nodeId)
            ->toBe($selected->id)
            ->and($inspector->nodeIds)
            ->toBe([$selected->id]);
    });

    it('preserves a missing filtered node exception without inspecting', function (): void {
        $consumer = run_doctor_node('consumer');
        $inspector = bind_run_doctor_inspector();

        expect(fn () => app(RunDoctorAction::class)->execute(
            $consumer,
            999_999,
            [DoctorFamily::Node],
        ))
            ->toThrow(ModelNotFoundException::class);

        expect($inspector->nodeIds)->toBe([]);
    });

    it('denies an inaccessible filtered node before inspecting it', function (): void {
        $consumer = run_doctor_node('consumer');
        $inaccessible = run_doctor_node('inaccessible');
        $inspector = bind_run_doctor_inspector();

        expect(fn () => app(RunDoctorAction::class)->execute(
            $consumer,
            $inaccessible->id,
            [DoctorFamily::Node],
        ))
            ->toThrow(AuthorizationException::class);

        expect($inspector->nodeIds)->toBe([]);
    });

    it('selects all eight families in canonical order when no filter is given', function (): void {
        $consumer = run_doctor_node('consumer');
        $selected = run_doctor_node('selected');
        $consumer->accessibleNodes()->attach($selected);
        $inspector = bind_run_doctor_inspector();

        $report = app(RunDoctorAction::class)->execute($consumer, $selected->id, []);

        expect(array_map(
            static fn (DoctorFamilyReportData $family): DoctorFamily => $family->family,
            $report->nodes[0]->families,
        ))
            ->toBe(DoctorFamily::cases())
            ->and($inspector->nodeIds)
            ->toBe([$selected->id]);
    });

    it('keeps a filtered family subset in canonical order', function (): void {
        $consumer = run_doctor_node('consumer');
        $selected = run_doctor_node('selected');
        $consumer->accessibleNodes()->attach($selected);
        bind_run_doctor_inspector();

        $report = app(RunDoctorAction::class)->execute(
            $consumer,
            $selected->id,
            [
                DoctorFamily::Firewall,
                DoctorFamily::Role,
                DoctorFamily::Node,
            ],
        );

        expect(array_map(
            static fn (DoctorFamilyReportData $family): DoctorFamily => $family->family,
            $report->nodes[0]->families,
        ))->toBe([
            DoctorFamily::Node,
            DoctorFamily::Role,
            DoctorFamily::Firewall,
        ]);
    });

    it('observes every selected node once when the node family is not selected', function (): void {
        $consumer = run_doctor_node('consumer');
        $first = run_doctor_node('first');
        $second = run_doctor_node('second');
        $consumer->accessibleNodes()->attach([$first->id, $second->id]);
        $inspector = bind_run_doctor_inspector();

        $report = app(RunDoctorAction::class)->execute($consumer, null, [DoctorFamily::Role]);

        expect($inspector->nodeIds)
            ->toBe([$first->id, $second->id])
            ->and($report->summary['nodes'])
            ->toBe(2)
            ->and($report->summary['families'])
            ->toBe(2);
    });

    it('passes an unreachable observation to every selected probe', function (): void {
        $consumer = run_doctor_node('consumer');
        $selected = run_doctor_node('selected');
        $consumer->accessibleNodes()->attach($selected);
        NodeRole::query()->create([
            'node_id' => $selected->id,
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        bind_run_doctor_inspector(new NodeInspectionData(false, null, null, null));

        $report = app(RunDoctorAction::class)->execute(
            $consumer,
            $selected->id,
            [
                DoctorFamily::Node,
                DoctorFamily::Role,
            ],
        );

        expect(array_map(
            static fn (DoctorFamilyReportData $family): string => $family->family->value,
            $report->nodes[0]->families,
        ))
            ->toBe(['node', 'role'])
            ->and($report->nodes[0]->families[0]->issues[0]->code)
            ->toBe('node.ssh_unreachable')
            ->and($report->nodes[0]->families[1]->issues[0]->code)
            ->toBe('role.node_unreachable');
    });

    it('maps a typed base inspection failure and continues selected probes', function (): void {
        $consumer = run_doctor_node('consumer');
        $selected = run_doctor_node('selected');
        $consumer->accessibleNodes()->attach($selected);
        $inspector = bind_run_doctor_inspector();
        $inspector->throwForNodeId = $selected->id;

        $report = app(RunDoctorAction::class)->execute(
            $consumer,
            $selected->id,
            [
                DoctorFamily::Node,
                DoctorFamily::Role,
            ],
        );

        expect($report->nodes[0]->families)
            ->toHaveCount(2)
            ->and($report->nodes[0]->families[0]->issues[0]->code)
            ->toBe('node.inspection_failed')
            ->and($report->nodes[0]->families[1]->family)
            ->toBe(DoctorFamily::Role)
            ->and($report->nodes[0]->families[1]->issues)
            ->toBe([]);
    });

    it('derives aggregate summaries from real probe reports', function (): void {
        $consumer = run_doctor_node('consumer');
        $selected = run_doctor_node('selected');
        $consumer->accessibleNodes()->attach($selected);
        bind_run_doctor_inspector(new NodeInspectionData(false, null, null, null));

        $report = app(RunDoctorAction::class)->execute($consumer, $selected->id, [DoctorFamily::Node]);

        expect($report->healthy)
            ->toBeFalse()
            ->and($report->summary)
            ->toBe([
                'nodes' => 1,
                'families' => 1,
                'checks' => 1,
                'drift' => 0,
                'unverifiable' => 1,
            ]);
    });
});

function run_doctor_node(string $name): Node
{
    static $number = 20;
    $number++;

    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => "10.44.0.{$number}",
    ]);
}

function bind_run_doctor_inspector(
    ?NodeInspectionData $inspection = null,
): RunDoctorRecordingNodeStateInspector {
    $inspector = new RunDoctorRecordingNodeStateInspector(
        $inspection ?? new NodeInspectionData(true, 'linux', 'x86_64', true),
    );
    app()->instance(NodeStateInspector::class, $inspector);

    return $inspector;
}

final class RunDoctorRecordingNodeStateInspector implements NodeStateInspector
{
    /** @var list<int> */
    public array $nodeIds = [];

    public ?int $throwForNodeId = null;

    public function __construct(
        private readonly NodeInspectionData $inspection,
    ) {}

    public function inspect(Node $node): NodeInspectionData
    {
        $this->nodeIds[] = $node->id;

        if ($node->id === $this->throwForNodeId) {
            throw new DoctorInspectionException;
        }

        return $this->inspection;
    }
}
