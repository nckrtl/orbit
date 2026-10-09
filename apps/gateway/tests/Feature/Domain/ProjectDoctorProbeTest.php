<?php

declare(strict_types=1);

use App\Actions\Doctor\ProjectDoctorProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\ProjectInspectionData;
use App\Domain\Doctor\ProjectStateInspector;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

it('returns a healthy empty report when no app projects a checkout on the node', function (): void {
    $node = app_probe_node();
    $other = app_probe_node();
    app_probe_projection(app_probe_app(), $other);
    $calls = 0;

    $report = new ProjectDoctorProbe(new class($calls) implements ProjectStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            $this->calls++;

            return new ProjectInspectionData(1, true);
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty()->and($calls)->toBe(0);
});

it('checks selected apps in id order and reports a bounded origin mismatch', function (): void {
    $node = app_probe_node();
    $other = app_probe_node();
    $first = app_probe_app();
    $second = app_probe_app();
    app_probe_projection($first, $node);
    app_probe_projection($second, $node);
    app_probe_projection(app_probe_app(), $other);
    $seen = [];

    $report = new ProjectDoctorProbe(new class($seen, $second) implements ProjectStateInspector
    {
        public function __construct(
            private array &$seen,
            private Project $mismatch,
        ) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            $this->seen[] = $project->id;

            return new ProjectInspectionData(1, ! $project->is($this->mismatch));
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($seen)
        ->toBe([$first->id, $second->id])
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('project.repository_origin_mismatch')
        ->and($report->issues[0]->resourceId)
        ->toBe($second->id)
        ->and($report->issues[0]->expected)
        ->toBe('matching')
        ->and($report->issues[0]->observed)
        ->toBe('mismatch')
        ->and(json_encode($report))
        ->not->toContain('github.com', 'private-origin');
});

it('ignores removal in flight when the only checkout mismatch belongs to a removing Instance', function (): void {
    $node = app_probe_node();
    $project = app_probe_app();
    $instance = app_probe_projection($project, $node);
    app_probe_mark_removing($instance);
    $calls = 0;

    $report = new ProjectDoctorProbe(new class($calls) implements ProjectStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            $this->calls++;

            return new ProjectInspectionData(1, false);
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)
        ->toBe(0)
        ->and($report->issues)
        ->toBeEmpty()
        ->and($calls)
        ->toBe(0);
});

it('reports App drift for an active Instance but skips a mismatching removing Instance', function (): void {
    $node = app_probe_node();
    $project = app_probe_app();
    $active = app_probe_projection($project, $node);
    $removing = app_probe_projection($project, $node);
    app_probe_mark_removing($removing);

    $report = new ProjectDoctorProbe(new class($active, $removing) implements ProjectStateInspector
    {
        public function __construct(
            private Instance $active,
            private Instance $removing,
        ) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            return new ProjectInspectionData(2, false, [(int) $this->active->id, (int) $this->removing->id]);
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('project.repository_origin_mismatch')
        ->and($report->issues[0]->resourceId)
        ->toBe($project->id);
});

it('ignores App drift for an Instance with provisioning in flight', function (): void {
    $node = app_probe_node();
    $project = app_probe_app();
    $instance = app_probe_projection($project, $node);
    $instance->update(['status' => InstanceState::CheckoutPrepared, 'provisioning_step' => 'checkout']);
    $calls = 0;

    $report = new ProjectDoctorProbe(new class($calls) implements ProjectStateInspector
    {
        public function __construct(private int &$calls) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            $this->calls++;

            return new ProjectInspectionData(1, false);
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)->toBe(0)
        ->and($report->issues)->toBeEmpty()
        ->and($calls)->toBe(0);
});

it('keeps settled checkout failures visible beside a provisioning Instance', function (): void {
    $node = app_probe_node();
    $project = app_probe_app();
    $settled = app_probe_projection($project, $node);
    $provisioning = app_probe_projection($project, $node);
    $provisioning->update([
        'status' => InstanceState::CheckoutPrepared,
        'provisioning_step' => 'checkout',
    ]);

    $report = new ProjectDoctorProbe(new class($settled, $provisioning) implements ProjectStateInspector
    {
        public function __construct(
            private Instance $settled,
            private Instance $provisioning,
        ) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            return new ProjectInspectionData(2, true, [], [(int) $this->settled->id, (int) $this->provisioning->id]);
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)->toBe(1)
        ->and($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('project.inspection_failed')
        ->and($report->issues[0]->resourceId)->toBe($project->id);
});

it('drops removal in flight mismatch when the Instance starts removing during inspection', function (): void {
    $node = app_probe_node();
    $project = app_probe_app();
    $instance = app_probe_projection($project, $node);

    $report = new ProjectDoctorProbe(new class($instance) implements ProjectStateInspector
    {
        public function __construct(
            private Instance $instance,
        ) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            app_probe_mark_removing($this->instance);

            return new ProjectInspectionData(1, false, [(int) $this->instance->id]);
        }
    })->inspect(app_probe_context($node));

    expect($instance->fresh()->status)
        ->toBe(InstanceState::Removing)
        ->and($report->issues)
        ->toBeEmpty();
});

it('ignores removal in flight checkout failures when another checkout remains healthy', function (): void {
    $node = app_probe_node();
    $project = app_probe_app();
    $removing = app_probe_projection($project, $node);
    $healthy = app_probe_projection($project, $node);

    $report = new ProjectDoctorProbe(new class($removing) implements ProjectStateInspector
    {
        public function __construct(private Instance $removing) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            app_probe_mark_removing($this->removing);

            return new ProjectInspectionData(2, true, [], [(int) $this->removing->id]);
        }
    })->inspect(app_probe_context($node));

    expect($healthy->fresh()->status)
        ->toBe(InstanceState::Active)
        ->and($report->issues)
        ->toBeEmpty();
});

it('reports a checkout failure while its Instance remains active', function (): void {
    $node = app_probe_node();
    $project = app_probe_app();
    $instance = app_probe_projection($project, $node);

    $report = new ProjectDoctorProbe(new class($instance) implements ProjectStateInspector
    {
        public function __construct(private Instance $failed) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            return new ProjectInspectionData(1, true, [], [(int) $this->failed->id]);
        }
    })->inspect(app_probe_context($node));

    expect($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('project.inspection_failed')
        ->and($report->issues[0]->resourceId)
        ->toBe($project->id);
});

it('short-circuits app inspection when the node is unreachable', function (): void {
    $node = app_probe_node();
    app_probe_projection(app_probe_app(), $node);
    $calls = 0;

    $report = new ProjectDoctorProbe(new class($calls) implements ProjectStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(app_probe_context($node, reachable: false));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('project.node_unreachable')
        ->and($report->issues[0]->resourceId)
        ->toBeNull()
        ->and($calls)
        ->toBe(0);
});

it('continues after a typed app inspection failure and counts the projected app', function (): void {
    $node = app_probe_node();
    $failed = app_probe_app();
    $healthy = app_probe_app();
    app_probe_projection($failed, $node);
    app_probe_projection($healthy, $node);

    $report = new ProjectDoctorProbe(new class($failed) implements ProjectStateInspector
    {
        public function __construct(
            private Project $failed,
        ) {}

        public function inspect(Project $project, Node $node): ProjectInspectionData
        {
            if ($project->is($this->failed)) {
                throw new DoctorInspectionException;
            }

            return new ProjectInspectionData(1, true);
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('project.inspection_failed')
        ->and($report->issues[0]->resourceId)
        ->toBe($failed->id)
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable');
});

function app_probe_node(): Node
{
    static $number = 40;
    $number++;

    return Node::query()->create([
        'name' => "app-probe-node-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => "10.44.0.{$number}",
    ]);
}

function app_probe_app(): Project
{
    static $number = 0;
    $number++;

    return Project::query()->create([
        'name' => "App {$number}",
        'slug' => "app-{$number}",
        'repository_url' => "https://github.com/acme/private-origin-{$number}.git",
        'apps' => fixture_apps(null),
    ]);
}

function app_probe_projection(Project $project, Node $node): Instance
{
    $number = $project->instances()->count() + 1;

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => "development-{$number}",
        'checkout_path' => "/home/orbit/apps/{$project->slug}/development-{$number}",
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);
}

function app_probe_mark_removing(Instance $instance): void
{
    // Model a concurrent persisted status change without building the unrelated removal inventory fixture.
    $trigger = DB::table('sqlite_master')
        ->where('type', 'trigger')
        ->where('name', 'instances_removal_status_update')
        ->value('sql');
    expect($trigger)->toBeString();
    DB::statement('DROP TRIGGER instances_removal_status_update');

    try {
        $instance->update(['status' => InstanceState::Removing]);
    } finally {
        DB::statement($trigger);
    }
}

function app_probe_context(Node $node, bool $reachable = true): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true));
}
