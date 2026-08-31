<?php

declare(strict_types=1);

use App\Actions\Doctor\InstanceDoctorProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Instances\CertificateMode;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;

it('returns a healthy empty instance report and excludes other nodes', function (): void {
    $node = instance_probe_node();
    $other = instance_probe_node();
    instance_probe_instance(instance_probe_app(), $other, CertificateMode::OrbitCa);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector {
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

it('checks healthy development and production instances in id order with nullable fields', function (): void {
    $node = instance_probe_node();
    $development = instance_probe_instance(instance_probe_app(), $node, CertificateMode::OrbitCa);
    $production = instance_probe_instance(instance_probe_app(), $node, CertificateMode::Acme);
    $seen = [];

    $report = new InstanceDoctorProbe(new class($seen) implements InstanceStateInspector {
        public function __construct(
            private array &$seen,
        ) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            $this->seen[] = $instance->id;
            $appDevelopment = $instance->certificate_mode === CertificateMode::OrbitCa;

            return new InstanceInspectionData(
                true,
                true,
                true,
                true,
                $appDevelopment ? true : null,
                $appDevelopment ? true : null,
            );
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($seen)
        ->toBe([$development->id, $production->id])
        ->and($report->issues)
        ->toBeEmpty();
});

it('short-circuits instance inspection when the node is unreachable', function (): void {
    $node = instance_probe_node();
    instance_probe_instance(instance_probe_app(), $node, CertificateMode::OrbitCa);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector {
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

it('reports lifecycle and every false instance field in stable order', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(
        instance_probe_app(),
        $node,
        CertificateMode::OrbitCa,
        LifecycleStatus::Failed,
    );

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(false, false, false, false, false, false);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.lifecycle_not_active',
            'instance.checkout_missing',
            'instance.document_root_missing',
            'instance.caddy_projection_mismatch',
            'instance.php_fpm_projection_mismatch',
            'instance.certificate_projection_mismatch',
            'instance.dns_projection_mismatch',
        ])
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id])
        ->and(collect($report->issues)->pluck('expected')->all())
        ->toBe(['active', 'matching', 'matching', 'matching', 'matching', 'matching', 'matching'])
        ->and(json_encode($report))
        ->not->toContain($instance->checkout_path, $instance->error_code ?? 'private-error');
});

it('continues after a typed instance inspection failure', function (): void {
    $node = instance_probe_node();
    $failed = instance_probe_instance(instance_probe_app(), $node, CertificateMode::OrbitCa);
    $healthy = instance_probe_instance(instance_probe_app(), $node, CertificateMode::Acme);

    $report = new InstanceDoctorProbe(new class($failed) implements InstanceStateInspector {
        public function __construct(
            private Instance $failed,
        ) {}

        public function inspect(Instance $instance): InstanceInspectionData
        {
            if ($instance->is($this->failed)) {
                throw new DoctorInspectionException;
            }

            return new InstanceInspectionData(true, true, true, true, null, null);
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
    ]);
}

function instance_probe_instance(
    App $app,
    Node $node,
    CertificateMode $mode,
    LifecycleStatus $status = LifecycleStatus::Active,
): Instance {
    $name = $mode === CertificateMode::Acme ? 'production' : 'development';
    $suffix = $app->instances()->count() + 1;

    return Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => "{$name}-{$suffix}",
        'environment' => $name,
        'checkout_path' => "/private/instance/{$app->id}/{$suffix}",
        'hostname' => "instance-{$app->id}-{$node->id}-{$suffix}.test",
        'certificate_mode' => $mode,
        'status' => $status,
        'error_code' => 'private-error',
    ]);
}

function instance_probe_context(Node $node, bool $reachable = true): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true));
}
