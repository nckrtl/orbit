<?php

declare(strict_types=1);

use App\Actions\Doctor\ToolDoctorProbe;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Tools\ToolInspectionData;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\VersionConstraint;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\DB;

function tool_probe_node(): Node
{
    return Node::create(['name' => 'probe-node', 'public_ssh_host' => '127.0.0.1']);
}

it('reports no rows as healthy', function (): void {
    $node = tool_probe_node();
    $inspector = new class implements ToolInspector {
        public function inspect(Tool $tool): ToolInspectionData
        {
            throw new RuntimeException('unexpected');
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty();
});

it('reports an absent managed tool as drift', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector {
        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(false, null);
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect($report->issues[0]->code)->toBe('tool.not_installed');
});

it('keeps an installed tool healthy when its normalized version satisfies valid intent', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'version_constraint' => '^1.2',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector {
        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(true, '1.2.3');
        }
    };

    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toBeEmpty();
});

it('does not inspect rows when unreachable', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'status' => 'installed',
    ]);
    $calls = 0;
    $inspector = new class($calls) implements ToolInspector {
        public function __construct(
            public int &$calls,
        ) {}

        public function inspect(Tool $tool): ToolInspectionData
        {
            $this->calls++;
            throw new RuntimeException;
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(false, null, null, false)));
    expect($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('tool.node_unreachable')
        ->and($calls)
        ->toBe(0);
});

it('keeps unconstrained installed tools healthy and reports bounded mismatch and invalid intent', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'free',
        'status' => 'installed',
    ]);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'bad',
        'version_constraint' => 'not valid',
        'status' => 'installed',
    ]);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'mismatch',
        'version_constraint' => '^2.0',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector {
        public function inspect(Tool $tool): ToolInspectionData
        {
            return new ToolInspectionData(true, $tool->package === 'mismatch' ? '1.0.0' : '1.2.3');
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)),
    );
    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['tool.inspection_failed', 'tool.version_mismatch'])
        ->and($report->issues[0]->expected)
        ->toBe('verifiable')
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable');
});

it('converts inspector failures to bounded unverifiable findings', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'example',
        'status' => 'installed',
    ]);
    $inspector = new class implements ToolInspector {
        public function inspect(Tool $tool): ToolInspectionData
        {
            throw new \App\Domain\Tools\ToolInspectionException;
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)),
    );
    expect($report->issues[0]->code)
        ->toBe('tool.inspection_failed')
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable');
});

it('queries only the selected node and preserves tool id order', function (): void {
    $node = tool_probe_node();
    $other = Node::create(['name' => 'other-node', 'public_ssh_host' => '127.0.0.1']);
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    $otherManager = ToolManagerRecord::create(['node_id' => $other->id, 'name' => 'apt', 'status' => 'active']);
    $first = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'first',
        'status' => 'installed',
    ]);
    $second = Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'second',
        'status' => 'installed',
    ]);
    Tool::create([
        'node_id' => $other->id,
        'tool_manager_id' => $otherManager->id,
        'package' => 'other',
        'status' => 'installed',
    ]);
    $seen = [];
    $inspector = new class($seen) implements ToolInspector {
        public function __construct(
            public array &$seen,
        ) {}

        public function inspect(Tool $tool): ToolInspectionData
        {
            $this->seen[] = $tool->id;

            return new ToolInspectionData(false, null);
        }
    };
    $report = new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect($seen)
        ->toBe([$first->id, $second->id])
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['tool.not_installed', 'tool.not_installed'])
        ->and(array_map(static fn ($issue): int => (int) $issue->resourceId, $report->issues))
        ->toBe([$first->id, $second->id]);
});

it('eager loads inspector relationships in the bounded tool query', function (): void {
    $node = tool_probe_node();
    $manager = ToolManagerRecord::create(['node_id' => $node->id, 'name' => 'apt', 'status' => 'active']);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'first',
        'status' => 'installed',
    ]);
    Tool::create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'second',
        'status' => 'installed',
    ]);
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });
    $inspector = new class implements ToolInspector {
        public function inspect(Tool $tool): ToolInspectionData
        {
            $tool->node;
            $tool->manager;

            return new ToolInspectionData(false, null);
        }
    };
    new ToolDoctorProbe($inspector, new VersionConstraint)
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'amd64', true)));
    expect($queries)->toBe(3);
});
