<?php

declare(strict_types=1);

use App\Actions\Annotations\AnnotationStoreAction;
use App\Actions\Doctor\InstanceDoctorProbe;
use App\Data\Annotations\AnnotationInput;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorInspectionScope;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\PrivateRouteProjectionObservation;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\PublicRouteEdgeObservation;
use App\Domain\Doctor\RouteApplicationUrlInspector;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns a healthy empty instance report and excludes other nodes', function (): void {
    $node = instance_probe_node();
    $other = instance_probe_node();
    instance_probe_instance(instance_probe_app(), $other);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty()->and($calls)->toBe(0);
});

it('checks healthy Instances in id order', function (): void {
    $node = instance_probe_node();
    $first = instance_probe_instance(instance_probe_app(), $node);
    $second = instance_probe_instance(instance_probe_app(), $node);
    $seen = [];

    $report = new InstanceDoctorProbe(new class($seen) implements InstanceStateInspector
    {
        public function __construct(
            private array &$seen,
        ) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->seen[] = $instance->id;

            return new InstanceInspectionData(true, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($seen)
        ->toBe([$first->id, $second->id])
        ->and($report->issues)
        ->toBeEmpty();
});

it('reports lifecycle and every false instance field in stable order', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(instance_probe_app(), $node, InstanceState::Active);
    $instance->update(['provisioning_step' => null]);

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(false, false, false, false);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.checkout_missing',
            'instance.repository_layout_mismatch',
            'instance.origin_mismatch',
            'instance.source_identity_mismatch',
        ])
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id])
        ->and(collect($report->issues)->pluck('expected')->all())
        ->toBe(['matching', 'matching', 'matching', 'matching'])
        ->and(json_encode($report))
        ->not->toContain($instance->checkout_path);
});

it('does not report drift for an Instance with provisioning in flight', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(instance_probe_app(), $node, InstanceState::CheckoutPrepared);
    $instance->update(['provisioning_step' => null]);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(private int &$calls) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->calls++;

            return new InstanceInspectionData(false, false, false, false);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)->toBe(1)
        ->and($report->issues)->toBeEmpty()
        ->and($calls)->toBe(0);
});

it('reports failed provisioning reports immediately', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(instance_probe_app(), $node, InstanceState::CheckoutPrepared);
    $instance->update([
        'provisioning_step' => 'checkout',
        'failed_step' => 'checkout',
        'error_code' => 'checkout_failed',
    ]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($report->issues)
        ->not->toBeEmpty()
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->not->toContain('instance.provisioning_stuck');
});

it('reports one stuck issue for an Instance with provisioning in flight beyond the bound', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(instance_probe_app(), $node, InstanceState::CheckoutPrepared);
    $instance->update(['provisioning_step' => 'checkout']);
    DB::table('instances')->where('id', $instance->id)
        ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckProvisioningMinutes + 1)]);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(private int &$calls) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->calls++;

            return new InstanceInspectionData(false, false, false, false);
        }
    })->inspect(instance_probe_context($node));

    expect($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('instance.provisioning_stuck')
        ->and($report->issues[0]->resourceId)->toBe($instance->id)
        ->and($report->issues[0]->kind->value)->toBe('drift')
        ->and($calls)->toBe(0);
});

it('checks Instances with the active provisioning step inside and beyond the stuck bound', function (): void {
    $node = instance_probe_node();

    foreach (['active'] as $step) {
        foreach ([false, true] as $olderThanBound) {
            $instance = instance_probe_instance(instance_probe_app(), $node);
            $instance->update(['provisioning_step' => $step]);
            if ($olderThanBound) {
                DB::table('instances')->where('id', $instance->id)
                    ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckProvisioningMinutes + 1)]);
            }
        }
    }
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(private int &$calls) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->calls++;

            return new InstanceInspectionData(true, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)->toBe(2)
        ->and($report->issues)->toBeEmpty()
        ->and($calls)->toBe(2);
});

it('reports a settled task workspace in a later lifecycle state as lifecycle drift', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_task_workspace(instance_probe_orbit_app(), $node, InstanceState::Active, false);
    $instance->update(['provisioning_step' => null]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('instance.lifecycle_not_active')
        ->and($report->issues[0]->expected)->toBe(InstanceState::SourceResolved->value)
        ->and($report->issues[0]->observed)->toBe(InstanceState::Active->value);
});

it('does not report drift for a task workspace being removed', function (): void {
    $instance = instance_probe_task_workspace_for_removal();
    $node = $instance->node;
    instance_probe_mark_removing($instance);
    $instance->update(['updated_at' => now()]);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(private int &$calls) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->calls++;

            return new InstanceInspectionData(false, false, false, false);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toBeEmpty()
        ->and($calls)
        ->toBe(0);
});

it('drops projection issues when removal started during inspection', function (): void {
    $instance = instance_probe_task_workspace_for_removal();
    $instance->update(['status' => InstanceState::Active, 'provisioning_step' => null]);
    $node = $instance->node;

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            expect($instance->status)->toBe(InstanceState::Active);
            $instance->update(['status' => InstanceState::Active]);
            instance_probe_mark_removing($instance);

            return new InstanceInspectionData(false, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($instance->fresh()->status)
        ->toBe(InstanceState::Removing)
        ->and($report->issues)
        ->toBeEmpty();
});

it('reports only a stuck removal for a task workspace being removed beyond the bound', function (): void {
    $instance = instance_probe_task_workspace_for_removal();
    $node = $instance->node;
    instance_probe_mark_removing($instance);
    DB::table('instances')
        ->where('id', $instance->id)
        ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckRemovalMinutes + 1)]);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(private int &$calls) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->calls++;

            return new InstanceInspectionData(false, false, false, false);
        }
    })->inspect(instance_probe_context($node));

    expect($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('instance.removal_stuck')
        ->and($report->issues[0]->resourceId)
        ->toBe($instance->id)
        ->and($report->issues[0]->kind->value)
        ->toBe('drift')
        ->and($calls)
        ->toBe(0);
});

it('short-circuits instance inspection when the node is unreachable', function (): void {
    $node = instance_probe_node();
    instance_probe_instance(instance_probe_app(), $node);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(instance_probe_context($node, reachable: false));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('instance.node_unreachable')
        ->and($report->issues[0]->resourceId)
        ->toBeNull()
        ->and($calls)
        ->toBe(0);
});

it('reports only stuck provisioning instead of inspecting an unsettled Instance', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(
        instance_probe_app(),
        $node,
        InstanceState::Reserved,
    );

    DB::table('instances')->where('id', $instance->id)
        ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckProvisioningMinutes + 1)]);

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            throw new RuntimeException('Unsettled Instances are not inspected.');
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)->toBe(1)
        ->and($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('instance.provisioning_stuck')
        ->and($report->issues[0]->resourceId)->toBe($instance->id);
});

it('accepts an active monorepo default without a Route or PHP runtime', function (): void {
    $node = instance_probe_node();
    $project = instance_probe_orbit_app();
    $project->update(['type' => ProjectType::Monorepo]);
    $instance = instance_probe_instance($project, $node);
    $instance->routes()->detach();
    $instance->update([
        'name' => 'default',
        'selected_php_version' => null,
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($instance->requiresRoute())->toBeFalse()
        ->and($instance->servesPhp())->toBeFalse()
        ->and($report->checked)->toBe(1)
        ->and($report->issues)->toBe([]);
});

it('accepts source_resolved for a task workspace that is not visitable', function (): void {
    $node = instance_probe_node();
    instance_probe_task_workspace(instance_probe_orbit_app(), $node, InstanceState::SourceResolved, false);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($report->checked)->toBe(1)->and($report->issues)->toBe([]);
});

it('reports a task workspace that is not visitable and stuck before source resolution', function (InstanceState $status): void {
    $node = instance_probe_node();
    $instance = instance_probe_task_workspace(instance_probe_orbit_app(), $node, $status, false);
    DB::table('instances')->where('id', $instance->id)
        ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckProvisioningMinutes + 1)]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('instance.provisioning_stuck')
        ->and($report->issues[0]->resourceId)->toBe($instance->id)
        ->and($report->issues[0]->expected)->toBe('source_resolved')
        ->and($report->issues[0]->observed)->toBe($status->value);
})->with([
    'reserved' => [InstanceState::Reserved],
    'checkout_prepared' => [InstanceState::CheckoutPrepared],
]);

it('expects active for a visitable task workspace and for an Instance outside a task', function (Project $project, bool $taskWorkspace): void {
    $node = instance_probe_node();
    $instance = $taskWorkspace
        ? instance_probe_task_workspace($project, $node, InstanceState::SourceResolved, true)
        : instance_probe_instance($project, $node, InstanceState::SourceResolved);
    DB::table('instances')->where('id', $instance->id)
        ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckProvisioningMinutes + 1)]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('instance.provisioning_stuck')
        ->and($report->issues[0]->resourceId)->toBe($instance->id)
        ->and($report->issues[0]->expected)->toBe('active')
        ->and($report->issues[0]->observed)->toBe('source_resolved');
})->with([
    'visitable task workspace' => [fn (): Project => instance_probe_app(), true],
    'Orbit Instance outside a task' => [fn (): Project => instance_probe_orbit_app(), false],
]);

it('keeps the recorded workspace mode healthy after a rename and a routing change', function (string $slug, bool $recorded, bool $setting): void {
    $node = instance_probe_node();
    $project = Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "https://github.com/acme/{$slug}.git",
        'default_branch' => 'main',
        'root' => 'public',
        'task_workspace_routed' => $setting,
    ]);
    $instance = instance_probe_task_workspace(
        $project,
        $node,
        $recorded ? InstanceState::Active : InstanceState::SourceResolved,
        $recorded,
    );
    if ($recorded) {
        $instance->update(['provisioning_step' => null]);
    }
    $project->update([
        'slug' => $slug === 'orbit' ? 'renamed-shop' : 'orbit',
        'task_workspace_routed' => ! $setting,
    ]);
    DB::table('instances')->where('id', $instance->id)
        ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckProvisioningMinutes + 1)]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($report->issues)->toBe([])
        ->and($instance->fresh()->task_workspace_routed)->toBe($recorded)
        ->and($project->fresh()->slug)->not->toBe($slug);
})->with([
    'orbit recorded unrouted while the setting is routed' => ['orbit', false, true],
    'shop recorded routed while the setting is unrouted' => ['shop', true, false],
    'orbit recorded routed while the setting is unrouted' => ['orbit', true, false],
    'shop recorded unrouted while the setting is routed' => ['shop', false, true],
]);

it('keeps an ordinary Instance on the active lifecycle when the Project is unrouted', function (): void {
    $node = instance_probe_node();
    $project = instance_probe_app();
    $project->update(['slug' => 'orbit', 'task_workspace_routed' => false]);
    $instance = instance_probe_instance($project, $node, InstanceState::SourceResolved);
    DB::table('instances')->where('id', $instance->id)
        ->update(['updated_at' => now()->subMinutes(InstanceDoctorProbe::StuckProvisioningMinutes + 1)]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('instance.provisioning_stuck')
        ->and($report->issues[0]->expected)->toBe('active')
        ->and($instance->fresh()->task_workspace_routed)->toBeNull();
});

it('keeps an annotated active Orbit Instance healthy after the annotation resolves', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(instance_probe_orbit_app(), $node);
    $store = app(AnnotationStoreAction::class);
    $annotation = $store->create($instance, new AnnotationInput(['id' => 'doctor-annotation', 'comment' => 'Adjust heading', 'threadId' => 'annotation-thread']));
    $store->transition($annotation, 'in_progress', null);
    $store->transition($annotation, 'resolved', 'Done');

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector())->inspect(instance_probe_context($node));

    expect($instance->tasks()->count())->toBe(1)
        ->and($report->issues)->toBe([]);
});

it('continues after a typed instance inspection failure', function (): void {
    $node = instance_probe_node();
    $failed = instance_probe_instance(instance_probe_app(), $node);
    $healthy = instance_probe_instance(instance_probe_app(), $node);

    $report = new InstanceDoctorProbe(new class($failed) implements InstanceStateInspector
    {
        public function __construct(
            private Instance $failed,
        ) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            if ($instance->is($this->failed)) {
                throw new DoctorInspectionException;
            }

            return new InstanceInspectionData(true, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('instance.inspection_failed')
        ->and($report->issues[0]->resourceId)
        ->toBe($failed->id)
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable')
        ->and($healthy->id)
        ->toBeGreaterThan($failed->id);
});

it('inspects the supported worktree source layout', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(instance_probe_app(), $node);
    $instance->update(['source_layout' => 'worktree']);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->calls++;

            return new InstanceInspectionData(true, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($report->issues)
        ->toBe([])
        ->and($calls)
        ->toBe(1);
});

it('reports every production projection field in stable order without exposing paths', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_production_instance(instance_probe_app(), $node);

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                true,
                true,
                true,
                true,
                productionHomeMatches: false,
                releaseSelectionMatches: false,
                selectedReleaseRootMatches: false,
                environmentProjectionMatches: false,
                phpFpmProjectionMatches: false,
                caddyProjectionMatches: false,
            );
        }
    })->inspect(instance_probe_context($node));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.production_home_mismatch',
            'instance.release_selection_mismatch',
            'instance.selected_release_root_mismatch',
            'instance.environment_projection_mismatch',
            'instance.php_fpm_projection_mismatch',
            'instance.caddy_projection_mismatch',
        ])
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($instance->production_home, $instance->production_php_socket);
});

it('reports missing and shared production PHP associations before native projection drift', function (): void {
    $node = instance_probe_node();
    $missing = instance_probe_production_instance(instance_probe_app(), $node);
    $missing->update(['production_php_socket' => null]);
    $sharedFirst = instance_probe_production_instance(instance_probe_app(), $node);
    $sharedSecond = instance_probe_production_instance(instance_probe_app(), $node);
    $sharedSecond->update([
        'production_php_service' => $sharedFirst->production_php_service,
        'production_php_pool' => $sharedFirst->production_php_pool,
        'production_php_socket' => $sharedFirst->production_php_socket,
    ]);

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                true,
                true,
                true,
                true,
                productionHomeMatches: true,
                releaseSelectionMatches: true,
                selectedReleaseRootMatches: true,
                environmentProjectionMatches: true,
                phpFpmProjectionMatches: true,
                caddyProjectionMatches: true,
            );
        }
    })->inspect(instance_probe_context($node));

    expect(collect($report->issues)->map(fn ($issue): array => [$issue->resourceId, $issue->code])->all())
        ->toBe([
            [$missing->id, 'instance.php_fpm_association_missing'],
            [$sharedFirst->id, 'instance.php_fpm_association_shared'],
            [$sharedSecond->id, 'instance.php_fpm_association_shared'],
        ])
        ->and($report->checked)
        ->toBe(3);
});

it('reports unavailable production observations without hiding established drift', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_production_instance(instance_probe_app(), $node);

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                true,
                true,
                true,
                true,
                productionHomeMatches: false,
                releaseSelectionMatches: true,
                selectedReleaseRootMatches: true,
                environmentProjectionMatches: false,
                phpFpmProjectionMatches: null,
                caddyProjectionMatches: null,
            );
        }
    })->inspect(instance_probe_context($node));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.production_home_mismatch',
            'instance.environment_projection_mismatch',
            'instance.inspection_failed',
        ])
        ->and($report->status->value)
        ->toBe('unverifiable')
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id]);
});

it('reports public-route drift without placement and skips unselected related nodes', function (): void {
    [$workload, $ingress, $router, $instance] = instance_probe_public_route();
    $inspector = new InstanceProbePublicEdgeInspector;
    $healthy = new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                checkoutExists: true,
                repositoryLayoutMatches: true,
                originMatches: true,
                sourceIdentityMatches: true,
                productionHomeMatches: true,
                releaseSelectionMatches: true,
                selectedReleaseRootMatches: true,
                environmentProjectionMatches: true,
                phpFpmProjectionMatches: true,
                caddyProjectionMatches: true,
            );
        }
    };

    $unselected = new InstanceDoctorProbe($healthy, $inspector)->inspect(
        instance_probe_context($workload)->withScope(new DoctorInspectionScope([
            $workload->id => instance_probe_context($workload),
        ])),
    );

    expect(array_map(static fn ($issue): string => $issue->code, $unselected->issues))
        ->toBe(['instance.related_node_unverifiable'])
        ->and($inspector->nodes)
        ->toBe([])
        ->and(json_encode($unselected, JSON_THROW_ON_ERROR))
        ->not->toContain('10.10.0')
        ->not->toContain((string) $ingress->id)
        ->not->toContain((string) $router->id);

    $inspector->observation = new PublicRouteEdgeObservation(false, false, false, false);
    $selected = new InstanceDoctorProbe($healthy, $inspector)->inspect(
        instance_probe_context($workload)->withScope(new DoctorInspectionScope([
            $workload->id => instance_probe_context($workload),
            $ingress->id => instance_probe_context($ingress),
            $router->id => instance_probe_context($router),
        ])),
    );

    expect(array_map(static fn ($issue): string => $issue->code, $selected->issues))
        ->toBe([
            'instance.public_ingress_mismatch',
            'instance.private_forwarding_mismatch',
            'instance.public_tls_mismatch',
            'instance.public_firewall_mismatch',
        ])
        ->and($inspector->nodes)
        ->toBe([$ingress->id])
        ->and(collect($selected->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id]);
});

it('reports private Route drift in stable field order without exposing projections', function (): void {
    [$workload, $router, $instance, $route] = instance_probe_private_cluster_route();
    $inspector = new InstanceProbePrivateProjectionInspector;
    $inspector->observation = new PrivateRouteProjectionObservation(
        false,
        false,
        false,
        false,
        false,
        false,
        false,
    );
    $healthy = instance_probe_healthy_inspector();

    $report = new InstanceDoctorProbe($healthy, null, $inspector)->inspect(
        instance_probe_context($workload)->withScope(new DoctorInspectionScope([
            $workload->id => instance_probe_context($workload),
            $router->id => instance_probe_context($router),
        ])),
    );

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.private_routing_scope_mismatch',
            'instance.router_caddy_mismatch',
            'instance.workload_caddy_mismatch',
            'instance.private_certificate_mismatch',
            'instance.private_dns_mismatch',
            'instance.private_firewall_mismatch',
            'instance.laravel_url_mismatch',
        ])
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id])
        ->and(collect($report->issues)->pluck('expected')->unique()->all())
        ->toBe(['matching'])
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($route->domain)
        ->not->toContain((string) $instance->checkout_path)
        ->not->toContain('10.10.0')
        ->not->toContain('APP_URL')
        ->not->toContain('/etc/caddy')
        ->not->toContain('ufw');
});

it('reports target-set and association drift without treating application HTTP errors as provisioning drift', function (): void {
    [$workload, $router, $instance] = instance_probe_private_cluster_route();
    $healthy = instance_probe_healthy_inspector();
    $scope = instance_probe_context($workload)->withScope(new DoctorInspectionScope([
        $workload->id => instance_probe_context($workload),
        $router->id => instance_probe_context($router),
    ]));

    $drift = new InstanceDoctorProbe($healthy, null, new InstanceProbePrivateProjectionInspector(
        new PrivateRouteProjectionObservation(true, true, true, true, true, true, true, false, false),
    ))->inspect($scope);
    $healthyPool = new InstanceDoctorProbe($healthy, null, new InstanceProbePrivateProjectionInspector(
        new PrivateRouteProjectionObservation(true, true, true, true, true, true, true),
    ))->inspect($scope);

    expect(array_map(static fn ($issue): string => $issue->code, $drift->issues))
        ->toBe([
            'instance.target_set_mismatch',
            'instance.route_association_mismatch',
        ])
        ->and($healthyPool->issues)
        ->toBe([])
        ->and(json_encode($drift, JSON_THROW_ON_ERROR))
        ->not->toContain('500')
        ->not->toContain('10.10.0');
});

it('maps missing stale malformed and unreachable private observations to bounded findings', function (): void {
    [$workload, $router, $instance] = instance_probe_private_cluster_route();
    $healthy = instance_probe_healthy_inspector();
    $scope = instance_probe_context($workload)->withScope(new DoctorInspectionScope([
        $workload->id => instance_probe_context($workload),
        $router->id => instance_probe_context($router),
    ]));

    $missing = new InstanceDoctorProbe($healthy, null, new InstanceProbePrivateProjectionInspector(
        new PrivateRouteProjectionObservation(false, true, true, true, true, true, true),
    ))->inspect($scope);
    $malformed = new InstanceDoctorProbe($healthy, null, new InstanceProbePrivateProjectionInspector(
        new PrivateRouteProjectionObservation(true, true, null, true, true, true, true),
    ))->inspect($scope);
    $unreachable = new InstanceDoctorProbe($healthy, null, new class implements PrivateRouteProjectionInspector
    {
        public function inspect(Instance $instance, Route $route): PrivateRouteProjectionObservation
        {
            throw new DoctorInspectionException;
        }
    })->inspect($scope);

    expect(array_map(static fn ($issue): string => $issue->code, $missing->issues))
        ->toBe(['instance.private_routing_scope_mismatch'])
        ->and(array_map(static fn ($issue): string => $issue->code, $malformed->issues))
        ->toBe(['instance.inspection_failed'])
        ->and($malformed->status->value)
        ->toBe('unverifiable')
        ->and(array_map(static fn ($issue): string => $issue->code, $unreachable->issues))
        ->toBe(['instance.inspection_failed'])
        ->and(json_encode([$missing, $malformed, $unreachable], JSON_THROW_ON_ERROR))
        ->not->toContain('10.10.0')
        ->not->toContain('ssh timeout')
        ->not->toContain((string) $instance->checkout_path);
});

it('inspects private Routes without mutating managed state', function (): void {
    [$workload, $router, $instance, $route] = instance_probe_private_cluster_route();
    $before = [
        $route->fresh()->getAttributes(),
        $instance->fresh()->getAttributes(),
        $workload->fresh()->getAttributes(),
        $router->fresh()->getAttributes(),
    ];
    $probe = new InstanceDoctorProbe(
        instance_probe_healthy_inspector(),
        null,
        new InstanceProbePrivateProjectionInspector,
    );

    $report = $probe->inspect(instance_probe_context($workload)->withScope(new DoctorInspectionScope([
        $workload->id => instance_probe_context($workload),
        $router->id => instance_probe_context($router),
    ])));

    expect($report->issues)
        ->toBeEmpty()
        ->and($route->fresh()->getAttributes())
        ->toBe($before[0])
        ->and($instance->fresh()->getAttributes())
        ->toBe($before[1])
        ->and($workload->fresh()->getAttributes())
        ->toBe($before[2])
        ->and($router->fresh()->getAttributes())
        ->toBe($before[3])
        ->and($instance->fresh()->status)
        ->toBe(InstanceState::Active)
        ->and($route->fresh()->status)
        ->toBe(RouteStatus::Active);
});

it('keeps a valid private Route healthy when the application returns HTTP 500', function (): void {
    [$workload, $router] = instance_probe_private_cluster_route();
    $inspector = new InstanceProbePrivateProjectionInspector;
    $probe = new InstanceDoctorProbe(instance_probe_healthy_inspector(), null, $inspector);

    $report = $probe->inspect(instance_probe_context($workload)->withScope(new DoctorInspectionScope([
        $workload->id => instance_probe_context($workload),
        $router->id => instance_probe_context($router),
    ])));

    expect($report->issues)
        ->toBeEmpty()
        ->and($report->status->value)
        ->toBe('healthy')
        ->and($inspector->routes)
        ->toHaveCount(1);
});

it('skips unselected private Routers and keeps Node resource field and issue order', function (): void {
    [$firstWorkload, $firstRouter, $first] = instance_probe_private_cluster_route();
    [$secondWorkload, $secondRouter, $second] = instance_probe_private_cluster_route();
    $observation = new PrivateRouteProjectionObservation(false, true, false, true, true, true, true);
    $unselectedInspector = new InstanceProbePrivateProjectionInspector($observation);
    $inspector = new InstanceProbePrivateProjectionInspector($observation);
    $healthy = instance_probe_healthy_inspector();

    $unselected = new InstanceDoctorProbe($healthy, null, $unselectedInspector)->inspect(
        instance_probe_context($firstWorkload)->withScope(new DoctorInspectionScope([
            $firstWorkload->id => instance_probe_context($firstWorkload),
        ])),
    );
    $ordered = new InstanceDoctorProbe($healthy, null, $inspector)->inspect(
        instance_probe_context($firstWorkload)->withScope(new DoctorInspectionScope([
            $firstWorkload->id => instance_probe_context($firstWorkload),
            $firstRouter->id => instance_probe_context($firstRouter),
        ])),
    );
    $secondReport = new InstanceDoctorProbe($healthy, null, $inspector)->inspect(
        instance_probe_context($secondWorkload)->withScope(new DoctorInspectionScope([
            $secondWorkload->id => instance_probe_context($secondWorkload),
            $secondRouter->id => instance_probe_context($secondRouter),
        ])),
    );

    expect(array_map(static fn ($issue): string => $issue->code, $unselected->issues))
        ->toBe(['instance.related_node_unverifiable'])
        ->and($unselectedInspector->nodes)
        ->toBe([])
        ->and(array_map(static fn ($issue): string => $issue->code, $ordered->issues))
        ->toBe([
            'instance.private_routing_scope_mismatch',
            'instance.workload_caddy_mismatch',
        ])
        ->and(collect($ordered->issues)->pluck('resourceId')->all())
        ->toBe([$first->id, $first->id])
        ->and($second->id)
        ->toBeGreaterThan($first->id)
        ->and(collect($secondReport->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$second->id])
        ->and(json_encode($unselected, JSON_THROW_ON_ERROR))
        ->not->toContain((string) $firstRouter->id);
});

it('checks APP_URL in every directory that a Route with a web root wins', function (): void {
    [$workload, $router, $instance, $own] = instance_probe_private_cluster_route();
    $instance->update(['selected_php_version' => '8.5']);
    $docs = instance_probe_web_root_route($instance, $own, 'apps/docs/public', 'docs');
    instance_probe_web_root_route($instance, $own, 'apps/docs/public', 'docs-later');
    $admin = instance_probe_web_root_route($instance, $own, 'apps/admin/public', 'admin', RouteStatus::Activating);
    $site = instance_probe_web_root_route($instance, $own, 'apps/site/public', 'site', publication: RoutePublication::Public);
    instance_probe_web_root_route($instance, $own, 'public', 'own-directory');
    instance_probe_web_root_route($instance, $own, 'apps/old/public', 'old', RouteStatus::Pending);
    $urls = new InstanceProbeApplicationUrlInspector(['apps/docs' => false, 'apps/admin' => true, 'apps/site' => false]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector(), applicationUrls: $urls)
        ->inspect(instance_probe_private_scope($workload, $router));

    expect($urls->expected)
        ->toBe([[
            'apps/docs' => "https://{$docs->domain}",
            'apps/admin' => "https://{$admin->domain}",
            'apps/site' => "https://{$site->domain}",
        ]])
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['instance.laravel_url_mismatch', 'instance.laravel_url_mismatch'])
        ->and(array_map(static fn ($issue): string => $issue->summary, $report->issues))
        ->toBe([
            'The Laravel URL in application directory [apps/docs] does not match the Route that serves it.',
            'The Laravel URL in application directory [apps/site] does not match the Route that serves it.',
        ])
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id])
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($docs->domain)
        ->not->toContain((string) $instance->checkout_path);
});

it('keeps Doctor output unchanged for an Instance whose Routes have no web root', function (): void {
    [$workload, $router, $instance] = instance_probe_private_cluster_route();
    $instance->update(['selected_php_version' => '8.5']);
    $scope = instance_probe_private_scope($workload, $router);
    $observation = new PrivateRouteProjectionObservation(true, true, true, true, true, true, false);
    $urls = new InstanceProbeApplicationUrlInspector([]);

    $before = new InstanceDoctorProbe(
        instance_probe_healthy_inspector(),
        null,
        new InstanceProbePrivateProjectionInspector($observation),
    )->inspect($scope);
    $after = new InstanceDoctorProbe(
        instance_probe_healthy_inspector(),
        null,
        new InstanceProbePrivateProjectionInspector($observation),
        applicationUrls: $urls,
    )->inspect($scope);

    expect($urls->expected)
        ->toBe([])
        ->and(json_encode($after, JSON_THROW_ON_ERROR))
        ->toBe(json_encode($before, JSON_THROW_ON_ERROR))
        ->and(array_map(static fn ($issue): string => $issue->code, $after->issues))
        ->toBe(['instance.laravel_url_mismatch']);
});

it('skips the directory check while the Instance has no PHP runtime', function (): void {
    [$workload, $router, $instance, $own] = instance_probe_private_cluster_route();
    instance_probe_web_root_route($instance, $own, 'apps/docs/public', 'docs');
    $urls = new InstanceProbeApplicationUrlInspector([]);

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector(), applicationUrls: $urls)
        ->inspect(instance_probe_private_scope($workload, $router));

    expect($urls->expected)->toBe([])
        ->and($report->issues)->toBe([]);
});

it('reports an unverifiable directory check as one inspection failure', function (InstanceProbeApplicationUrlInspector $urls): void {
    [$workload, $router, $instance, $own] = instance_probe_private_cluster_route();
    $instance->update(['selected_php_version' => '8.5']);
    instance_probe_web_root_route($instance, $own, 'apps/docs/public', 'docs');
    instance_probe_web_root_route($instance, $own, 'apps/admin/public', 'admin');

    $report = new InstanceDoctorProbe(instance_probe_healthy_inspector(), applicationUrls: $urls)
        ->inspect(instance_probe_private_scope($workload, $router));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe(['instance.inspection_failed'])
        ->and($report->status->value)
        ->toBe('unverifiable');
})->with([
    'unreachable' => fn (): InstanceProbeApplicationUrlInspector => new InstanceProbeApplicationUrlInspector(null),
    'missing directory' => fn (): InstanceProbeApplicationUrlInspector => new InstanceProbeApplicationUrlInspector(['apps/docs' => true]),
]);

function instance_probe_healthy_inspector(): InstanceStateInspector
{
    return new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                checkoutExists: true,
                repositoryLayoutMatches: true,
                originMatches: true,
                sourceIdentityMatches: true,
                productionHomeMatches: true,
                releaseSelectionMatches: true,
                selectedReleaseRootMatches: true,
                environmentProjectionMatches: true,
                phpFpmProjectionMatches: true,
                caddyProjectionMatches: true,
            );
        }
    };
}

function instance_probe_node(): Node
{
    static $number = 60;
    $number++;

    return Node::query()->create([
        'name' => "instance-probe-node-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => "10.44.0.{$number}",
    ]);
}

function instance_probe_app(): Project
{
    static $number = 0;
    $number++;

    return Project::query()->create([
        'name' => "Instance App {$number}",
        'slug' => "instance-app-{$number}",
        'repository_url' => "https://github.com/acme/private-instance-{$number}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
}

function instance_probe_instance(
    Project $project,
    Node $node,
    InstanceState $status = InstanceState::Active,
): Instance {
    NodeRole::query()->firstOrCreate(
        ['node_id' => $node->id, 'role' => RoleName::AppDev],
        ['status' => LifecycleStatus::Active],
    );
    $suffix = $project->instances()->count() + 1;

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => "development-{$suffix}",
        'environment' => 'development',
        'checkout_path' => "/private/instance/{$project->slug}/development-{$suffix}",
        'branch' => "development-{$suffix}",
        'starting_commit' => str_repeat((string) $suffix, 40),
        'status' => $status,
    ]);
}

function instance_probe_orbit_app(): Project
{
    return Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'https://github.com/acme/orbit.git',
        'default_branch' => 'main',
    ]);
}

function instance_probe_mark_removing(Instance $instance): void
{
    $instance->update(['root' => 'public']);
    $route = $instance->routes()->firstOrFail();
    $removal = InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => true,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => 'removing',
        'current_step' => 'source_preparation',
    ]);
    $removal->members()->create([
        'position' => 0,
        'instance_id' => $instance->id,
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'route_id' => $route->id,
        'name' => $instance->name,
        'environment' => $instance->defaultAppEnv(),
        'source_layout' => $instance->source_layout,
        'repository_identity' => $instance->project->repository_identity,
        'checkout_path' => $instance->checkout_path,
        'root' => $instance->effectiveRoot(),
        'branch' => $instance->branch,
        'starting_commit' => $instance->starting_commit,
        'source_commit' => $instance->starting_commit,
        'common_repository_path' => $instance->checkout_path,
        'source_identity' => '1:100',
        'linked_worktree_paths' => [],
        'source_digest' => str_repeat('d', 64),
    ]);
    $instance->update(['status' => InstanceState::Removing]);
}

function instance_probe_task_workspace_for_removal(): Instance
{
    [$node, , $instance] = instance_probe_private_cluster_route();
    $group = Task::topLevel()->create([
        'project_id' => $instance->project_id,
        'title' => 'Task workspace removal',
        'brief' => 'Build the feature.',
        'status' => 'running',
    ]);
    $name = TaskWorkspaceName::for($group);
    $instance->update(['name' => $name, 'branch_override' => $name]);
    $group->taskable()->associate($instance);
    $group->save();

    return $instance->fresh()->load(['project', 'node', 'tasks', 'routes.targets']);
}

function instance_probe_task_workspace(Project $project, Node $node, InstanceState $status, ?bool $routed = null): Instance
{
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Task workspace',
        'brief' => 'Build the feature.',
        'status' => 'running',
    ]);
    $instance = instance_probe_instance($project, $node, $status);
    $attributes = ['name' => TaskWorkspaceName::for($group), 'branch_override' => TaskWorkspaceName::for($group)];
    if (is_bool($routed)) {
        $attributes['task_workspace_routed'] = $routed;
    }
    $instance->update($attributes);
    $group->taskable()->associate($instance);
    $group->save();

    return $instance;
}

function instance_probe_production_instance(Project $project, Node $node): Instance
{
    NodeRole::query()->firstOrCreate(
        ['node_id' => $node->id, 'role' => RoleName::AppProd],
        ['status' => LifecycleStatus::Active],
    );
    $user = "orbit-app-{$project->id}";

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'selected_php_version' => '8.5',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);
}

function instance_probe_context(Node $node, bool $reachable = true): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true));
}

/** @return array{Node, Node, Node, Instance} */
function instance_probe_public_route(): array
{
    $cluster = Cluster::query()->create(['name' => 'doctor-public', 'state' => ClusterState::Active]);
    $workload = instance_probe_node();
    $ingress = instance_probe_node();
    $router = instance_probe_node();
    foreach ([$workload, $ingress, $router] as $node) {
        $node->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.'.($node->id % 200)]);
    }
    $ingress->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Ingress,
        'status' => LifecycleStatus::Active,
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $workload->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance = instance_probe_production_instance(instance_probe_app(), $workload);
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'cluster_id' => $cluster->id,
        'domain' => 'doctor.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update([
        'status' => RouteStatus::Active,
        'replacement_step' => RouteReplacementStep::IngressFirewall,
    ]);

    return [$workload, $ingress, $router, $instance];
}

/** @return array{Node, Node, Instance, Route} */
function instance_probe_private_cluster_route(): array
{
    static $number = 0;
    $number++;
    $cluster = Cluster::query()->create([
        'name' => "doctor-private-{$number}",
        'state' => ClusterState::Active,
    ]);
    $workload = instance_probe_node();
    $router = instance_probe_node();
    foreach ([$workload, $router] as $node) {
        $node->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.'.($node->id % 200)]);
    }
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $workload->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $instance = instance_probe_instance(instance_probe_app(), $workload);
    $instance->update(['source_is_laravel' => true]);
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'cluster_id' => $cluster->id,
        'domain' => "private-{$number}.doctor.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$workload, $router, $instance->fresh(), $route->fresh()];
}

final class InstanceProbePublicEdgeInspector implements PublicRouteEdgeInspector
{
    /** @var list<int> */
    public array $nodes = [];

    public PublicRouteEdgeObservation $observation;

    public function __construct()
    {
        $this->observation = new PublicRouteEdgeObservation(true, true, true, true);
    }

    public function inspect(Node $node, Route $route): PublicRouteEdgeObservation
    {
        $this->nodes[] = $node->id;

        return $this->observation;
    }
}

final class InstanceProbePrivateProjectionInspector implements PrivateRouteProjectionInspector
{
    /** @var list<int> */
    public array $nodes = [];

    /** @var list<int> */
    public array $routes = [];

    public PrivateRouteProjectionObservation $observation;

    public function __construct(?PrivateRouteProjectionObservation $observation = null)
    {
        $this->observation = $observation ?? new PrivateRouteProjectionObservation(
            true,
            true,
            true,
            true,
            true,
            true,
            true,
        );
    }

    public function inspect(Instance $instance, Route $route): PrivateRouteProjectionObservation
    {
        $this->nodes[] = $instance->node_id;
        $this->routes[] = $route->id;

        return $this->observation;
    }
}

function instance_probe_private_scope(Node $workload, Node $router): DoctorNodeContext
{
    return instance_probe_context($workload)->withScope(new DoctorInspectionScope([
        $workload->id => instance_probe_context($workload),
        $router->id => instance_probe_context($router),
    ]));
}

function instance_probe_web_root_route(
    Instance $instance,
    Route $own,
    string $webRoot,
    string $name,
    RouteStatus $status = RouteStatus::Active,
    RoutePublication $publication = RoutePublication::Private,
): Route {
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'cluster_id' => $own->cluster_id,
        'domain' => "{$name}-{$own->id}.doctor.test",
        'web_root' => $webRoot,
        'provenance' => RouteProvenance::Explicit,
        'publication' => $publication,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => $status]);

    return $route->fresh();
}

final class InstanceProbeApplicationUrlInspector implements RouteApplicationUrlInspector
{
    /** @var list<array<string, string>> */
    public array $expected = [];

    /** @param  array<string, bool>|null  $matches  Null fails the inspection. */
    public function __construct(private readonly ?array $matches) {}

    public function inspect(Instance $instance, array $expected): array
    {
        $this->expected[] = $expected;

        return $this->matches ?? throw new DoctorInspectionException;
    }
}
