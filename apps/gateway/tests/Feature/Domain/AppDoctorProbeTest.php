<?php

declare(strict_types=1);

use App\Actions\Doctor\AppDoctorProbe;
use App\Domain\Doctor\AppInspectionData;
use App\Domain\Doctor\AppStateInspector;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Instances\CertificateMode;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;

it('returns a healthy empty report when no app projects a checkout on the node', function (): void {
    $node = app_probe_node();
    $other = app_probe_node();
    app_probe_projection(app_probe_app(), $other);
    $calls = 0;

    $report = new AppDoctorProbe(new class($calls) implements AppStateInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(App $app, Node $node): AppInspectionData
        {
            $this->calls++;

            return new AppInspectionData(1, true);
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

    $report = new AppDoctorProbe(new class($seen, $second) implements AppStateInspector {
        public function __construct(
            private array &$seen,
            private App $mismatch,
        ) {}

        public function inspect(App $app, Node $node): AppInspectionData
        {
            $this->seen[] = $app->id;

            return new AppInspectionData(1, ! $app->is($this->mismatch));
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($seen)
        ->toBe([$first->id, $second->id])
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('app.repository_origin_mismatch')
        ->and($report->issues[0]->resourceId)
        ->toBe($second->id)
        ->and($report->issues[0]->expected)
        ->toBe('matching')
        ->and($report->issues[0]->observed)
        ->toBe('mismatch')
        ->and(json_encode($report))
        ->not->toContain('github.com', 'private-origin');
});

it('short-circuits app inspection when the node is unreachable', function (): void {
    $node = app_probe_node();
    app_probe_projection(app_probe_app(), $node);
    $calls = 0;

    $report = new AppDoctorProbe(new class($calls) implements AppStateInspector {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(App $app, Node $node): AppInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(app_probe_context($node, reachable: false));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('app.node_unreachable')
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

    $report = new AppDoctorProbe(new class($failed) implements AppStateInspector {
        public function __construct(
            private App $failed,
        ) {}

        public function inspect(App $app, Node $node): AppInspectionData
        {
            if ($app->is($this->failed)) {
                throw new DoctorInspectionException;
            }

            return new AppInspectionData(1, true);
        }
    })->inspect(app_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('app.inspection_failed')
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
        'wireguard_address' => "10.44.0.{$number}",
    ]);
}

function app_probe_app(): App
{
    static $number = 0;
    $number++;

    return App::query()->create([
        'name' => "App {$number}",
        'slug' => "app-{$number}",
        'repository_url' => 'https://github.com/acme/private-origin.git',
    ]);
}

function app_probe_projection(App $app, Node $node): Instance
{
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

function app_probe_context(Node $node, bool $reachable = true): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true));
}
