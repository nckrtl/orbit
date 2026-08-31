<?php

declare(strict_types=1);

use App\Actions\Doctor\WorkspaceDoctorProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\WorkspaceInspectionData;
use App\Domain\Doctor\WorkspaceStateInspector;
use App\Domain\Instances\CertificateMode;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;

it('returns a healthy empty workspace report and excludes other nodes', function (): void {
    $node = workspace_probe_node();
    $other = workspace_probe_node();
    workspace_probe_workspace(workspace_probe_instance($other));
    $calls = 0;

    $report = new WorkspaceDoctorProbe(new class($calls) implements WorkspaceStateInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(Workspace $workspace): WorkspaceInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(workspace_probe_context($node));

    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty()->and($calls)->toBe(0);
});

it('checks healthy workspaces in id order', function (): void {
    $node = workspace_probe_node();
    $instance = workspace_probe_instance($node);
    $first = workspace_probe_workspace($instance);
    $second = workspace_probe_workspace($instance);
    $seen = [];

    $report = new WorkspaceDoctorProbe(new class($seen) implements WorkspaceStateInspector {
        public function __construct(
            private array &$seen,
        ) {}

        public function inspect(Workspace $workspace): WorkspaceInspectionData
        {
            $this->seen[] = $workspace->id;

            return new WorkspaceInspectionData(true, true, true, true, true, true, true, true);
        }
    })->inspect(workspace_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($seen)
        ->toBe([$first->id, $second->id])
        ->and($report->issues)
        ->toBeEmpty();
});

it('short-circuits workspace inspection when the node is unreachable', function (): void {
    $node = workspace_probe_node();
    workspace_probe_workspace(workspace_probe_instance($node));
    $calls = 0;

    $report = new WorkspaceDoctorProbe(new class($calls) implements WorkspaceStateInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(Workspace $workspace): WorkspaceInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(workspace_probe_context($node, reachable: false));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('workspace.node_unreachable')
        ->and($report->issues[0]->resourceId)
        ->toBeNull()
        ->and($calls)
        ->toBe(0);
});

it('reports lifecycle and every false workspace field in stable order without private values', function (): void {
    $node = workspace_probe_node();
    $workspace = workspace_probe_workspace(workspace_probe_instance($node), LifecycleStatus::Failed);

    $report = new WorkspaceDoctorProbe(new class implements WorkspaceStateInspector {
        public function inspect(Workspace $workspace): WorkspaceInspectionData
        {
            return new WorkspaceInspectionData(false, false, false, false, false, false, false, false);
        }
    })->inspect(workspace_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'workspace.lifecycle_not_active',
            'workspace.checkout_missing',
            'workspace.worktree_missing',
            'workspace.branch_mismatch',
            'workspace.document_root_missing',
            'workspace.caddy_projection_mismatch',
            'workspace.php_fpm_projection_mismatch',
            'workspace.certificate_projection_mismatch',
            'workspace.dns_projection_mismatch',
        ])
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$workspace->id])
        ->and(json_encode($report))
        ->not->toContain($workspace->branch, $workspace->checkout_path, 'private-error');
});

it('continues after a typed workspace inspection failure', function (): void {
    $node = workspace_probe_node();
    $instance = workspace_probe_instance($node);
    $failed = workspace_probe_workspace($instance);
    $healthy = workspace_probe_workspace($instance);

    $report = new WorkspaceDoctorProbe(new class($failed) implements WorkspaceStateInspector {
        public function __construct(
            private Workspace $failed,
        ) {}

        public function inspect(Workspace $workspace): WorkspaceInspectionData
        {
            if ($workspace->is($this->failed)) {
                throw new DoctorInspectionException;
            }

            return new WorkspaceInspectionData(true, true, true, true, true, true, true, true);
        }
    })->inspect(workspace_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('workspace.inspection_failed')
        ->and($report->issues[0]->resourceId)
        ->toBe($failed->id)
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable')
        ->and($healthy->id)
        ->toBeGreaterThan($failed->id);
});

function workspace_probe_node(): Node
{
    static $number = 80;
    $number++;

    return Node::query()->create([
        'name' => "workspace-probe-node-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => "10.44.0.{$number}",
    ]);
}

function workspace_probe_instance(Node $node): Instance
{
    static $number = 0;
    $number++;
    $app = App::query()->create([
        'name' => "Workspace App {$number}",
        'slug' => "workspace-app-{$number}",
        'repository_url' => 'https://github.com/acme/private-workspace.git',
    ]);

    return Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'development',
        'environment' => 'development',
        'checkout_path' => "/home/orbit/apps/{$app->slug}",
        'hostname' => "{$app->slug}-{$node->id}.test",
        'certificate_mode' => CertificateMode::OrbitCa,
        'status' => LifecycleStatus::Active,
    ]);
}

function workspace_probe_workspace(
    Instance $instance,
    LifecycleStatus $status = LifecycleStatus::Active,
): Workspace {
    $suffix = $instance->workspaces()->count() + 1;

    return Workspace::query()->create([
        'instance_id' => $instance->id,
        'name' => "workspace-{$suffix}",
        'branch' => "private-branch-{$suffix}",
        'checkout_path' => "/private/workspace/{$instance->id}/{$suffix}",
        'hostname' => "workspace-{$instance->id}-{$suffix}.test",
        'status' => $status,
        'error_code' => 'private-error',
    ]);
}

function workspace_probe_context(Node $node, bool $reachable = true): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true));
}
