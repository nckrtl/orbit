<?php

declare(strict_types=1);

use App\Actions\Doctor\InstanceDoctorProbe;
use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\RegisteredWorktreeInspector;
use App\Domain\AppInstances\RegisteredWorktreeObservation;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;

it('returns a healthy empty instance report and excludes other nodes', function (): void {
    $node = instance_probe_node();
    $other = instance_probe_node();
    instance_probe_instance(instance_probe_app(), $other);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty()->and($calls)->toBe(0);
});

it('checks healthy AppInstances in id order', function (): void {
    $node = instance_probe_node();
    $first = instance_probe_instance(instance_probe_app(), $node);
    $second = instance_probe_instance(instance_probe_app(), $node);
    $seen = [];

    $report = new InstanceDoctorProbe(new class($seen) implements InstanceStateInspector {
        public function __construct(
            private array &$seen,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            $this->seen[] = $appInstance->id;

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

it('short-circuits instance inspection when the node is unreachable', function (): void {
    $node = instance_probe_node();
    instance_probe_instance(instance_probe_app(), $node);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
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

it('reports lifecycle and every false instance field in stable order', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(
        instance_probe_app(),
        $node,
        AppInstanceState::Reserved,
    );

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            return new InstanceInspectionData(false, false, false, false);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.lifecycle_not_active',
            'instance.checkout_missing',
            'instance.repository_not_independent',
            'instance.origin_mismatch',
            'instance.source_identity_mismatch',
        ])
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id])
        ->and(collect($report->issues)->pluck('expected')->all())
        ->toBe(['active', 'matching', 'matching', 'matching', 'matching'])
        ->and(json_encode($report))
        ->not->toContain($instance->checkout_path);
});

it('continues after a typed instance inspection failure', function (): void {
    $node = instance_probe_node();
    $failed = instance_probe_instance(instance_probe_app(), $node);
    $healthy = instance_probe_instance(instance_probe_app(), $node);

    $report = new InstanceDoctorProbe(new class($failed) implements InstanceStateInspector {
        public function __construct(
            private AppInstance $failed,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            if ($appInstance->is($this->failed)) {
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

it('verifies registered worktree identity while accepting later branch and HEAD movement', function (): void {
    $node = instance_probe_node();
    $app = instance_probe_app();
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'dfb5',
        'environment' => 'development',
        'source_kind' => AppInstanceSourceKind::RegisteredWorktree->value,
        'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
        'branch' => null,
        'starting_commit' => str_repeat('a', 40),
        'source_identity' => str_repeat('b', 64),
        'status' => AppInstanceState::Active,
    ]);
    $managed = new class implements InstanceStateInspector {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            throw new DoctorInspectionException;
        }
    };
    $registered = new class implements RegisteredWorktreeInspector {
        public function inspect(
            Node $node,
            App $app,
            string $checkoutPath,
            string $effectiveRoot,
        ): RegisteredWorktreeObservation {
            return new RegisteredWorktreeObservation(
                checkoutPath: $checkoutPath,
                branch: 'later-branch',
                startingCommit: str_repeat('c', 40),
                sourceIdentity: str_repeat('b', 64),
            );
        }
    };

    $report = new InstanceDoctorProbe($managed, $registered)->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toBeEmpty()
        ->and($instance->fresh()?->branch)
        ->toBeNull()
        ->and($instance->fresh()?->starting_commit)
        ->toBe(str_repeat('a', 40));
});

it('reports registered worktree disappearance as bounded drift without deleting registration', function (): void {
    $node = instance_probe_node();
    $app = instance_probe_app();
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'dfb5',
        'source_kind' => AppInstanceSourceKind::RegisteredWorktree->value,
        'checkout_path' => '/home/orbit/.codex/worktrees/dfb5/example',
        'source_identity' => str_repeat('b', 64),
        'status' => AppInstanceState::Active,
    ]);
    $managed = new class implements InstanceStateInspector {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            throw new DoctorInspectionException;
        }
    };
    $registered = new class implements RegisteredWorktreeInspector {
        public function inspect(
            Node $node,
            App $app,
            string $checkoutPath,
            string $effectiveRoot,
        ): RegisteredWorktreeObservation {
            throw new DoctorInspectionException;
        }
    };

    $report = new InstanceDoctorProbe($managed, $registered)->inspect(instance_probe_context($node));

    expect($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('instance.registered_worktree_unavailable')
        ->and(json_encode($report))
        ->not->toContain($instance->checkout_path)->and($instance->fresh())
        ->not->toBeNull();
});

it('exposes and refuses an unexpected AppInstance source kind without remote inspection', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(instance_probe_app(), $node);
    $instance->update(['source_kind' => 'unexpected_source']);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            $this->calls++;

            return new InstanceInspectionData(true, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('instance.source_kind_mismatch')
        ->and($report->issues[0]->expected)
        ->toBe('managed_clone or registered_worktree')
        ->and($report->issues[0]->observed)
        ->toBe('unexpected_source')
        ->and($calls)
        ->toBe(0);
});

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

function instance_probe_app(): App
{
    static $number = 0;
    $number++;

    return App::query()->create([
        'name' => "Instance App {$number}",
        'slug' => "instance-app-{$number}",
        'repository_url' => 'https://github.com/acme/private-instance.git',
        'main_branch' => 'main',
        'root' => 'public',
    ]);
}

function instance_probe_instance(
    App $app,
    Node $node,
    AppInstanceState $status = AppInstanceState::Active,
): AppInstance {
    $suffix = $app->appInstances()->count() + 1;

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => "development-{$suffix}",
        'environment' => 'development',
        'checkout_path' => "/private/instance/{$app->slug}/development-{$suffix}",
        'branch' => "development-{$suffix}",
        'starting_commit' => str_repeat((string) $suffix, 40),
        'status' => $status,
    ]);
}

function instance_probe_context(Node $node, bool $reachable = true): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true));
}
