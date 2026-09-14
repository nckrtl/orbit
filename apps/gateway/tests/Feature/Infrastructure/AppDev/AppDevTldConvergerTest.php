<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\NativeAppDevTldConverger;
use App\Models\App as OrbitApp;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;

it('leaves leftover Instance and Workspace hostnames untouched while republishing AppInstance projections', function (): void {
    $node = tld_converger_node('dev', 'new.test', LifecycleStatus::Provisioning);
    $active = tld_converger_instance($node, 'active-app', LifecycleStatus::Active);
    $provisioning = tld_converger_instance($node, 'provisioning-app', LifecycleStatus::Provisioning);
    $failed = tld_converger_instance($node, 'failed-app', LifecycleStatus::Failed);
    $activeWorkspace = tld_converger_workspace($active, 'active', LifecycleStatus::Active);
    $provisioningWorkspace = tld_converger_workspace($active, 'provisioning', LifecycleStatus::Provisioning);
    $failedWorkspace = tld_converger_workspace($active, 'failed', LifecycleStatus::Failed);
    $failedParentWorkspace = tld_converger_workspace($failed, 'active-child', LifecycleStatus::Active);
    $unrelatedNode = tld_converger_node('other-dev', 'other.test');
    $unrelated = tld_converger_instance($unrelatedNode, 'unrelated-app', LifecycleStatus::Active);
    $unrelatedWorkspace = tld_converger_workspace($unrelated, 'unrelated', LifecycleStatus::Active);
    $productionNode = tld_converger_node('production', null);
    $production = tld_converger_instance(
        $productionNode,
        'production-app',
        LifecycleStatus::Active,
        hostname: 'production.example.com',
        certificateMode: 'acme',
    );
    $events = [];

    tld_converger_runtime($events)->converge($node);

    expect($active->refresh()->hostname)
        ->toBe('active-app.old.test')
        ->and($provisioning->refresh()->hostname)
        ->toBe('provisioning-app.old.test')
        ->and($failed->refresh()->hostname)
        ->toBe('failed-app.old.test')
        ->and($activeWorkspace->refresh()->hostname)
        ->toBe('active.active-app.old.test')
        ->and($provisioningWorkspace->refresh()->hostname)
        ->toBe('provisioning.active-app.old.test')
        ->and($failedWorkspace->refresh()->hostname)
        ->toBe('failed.active-app.old.test')
        ->and($failedParentWorkspace->refresh()->hostname)
        ->toBe('active-child.failed-app.old.test')
        ->and($unrelated->refresh()->hostname)
        ->toBe('unrelated-app.old.test')
        ->and($unrelatedWorkspace->refresh()->hostname)
        ->toBe('unrelated.unrelated-app.old.test')
        ->and($production->refresh()->hostname)
        ->toBe('production.example.com')
        ->and($events)
        ->toBe([
            "caddy:{$node->id}",
            "dns:{$node->id}",
            "route:{$node->id}",
        ]);
});

it('repeats AppInstance publication without rewriting leftover hostnames', function (): void {
    $node = tld_converger_node('dev', 'new.test', LifecycleStatus::Provisioning);
    $instance = tld_converger_instance($node, 'shop', LifecycleStatus::Active);
    $workspace = tld_converger_workspace($instance, 'preview', LifecycleStatus::Active);
    $events = [];
    $converger = tld_converger_runtime($events);

    $converger->converge($node);
    $converger->converge($node);

    expect($instance->refresh()->hostname)
        ->toBe('shop.old.test')
        ->and($workspace->refresh()->hostname)
        ->toBe('preview.shop.old.test')
        ->and($events)
        ->toBe([
            "caddy:{$node->id}",
            "dns:{$node->id}",
            "route:{$node->id}",
            "caddy:{$node->id}",
            "dns:{$node->id}",
            "route:{$node->id}",
        ]);
});

function tld_converger_node(
    string $name,
    ?string $tld,
    LifecycleStatus $status = LifecycleStatus::Active,
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => $tld,
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 10),
    ]);
}

function tld_converger_instance(
    Node $node,
    string $slug,
    LifecycleStatus $status,
    ?string $hostname = null,
    string $certificateMode = 'orbit-ca',
): Instance {
    $app = OrbitApp::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "https://example.test/{$slug}.git",
    ]);

    return Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => $certificateMode === 'acme' ? 'production' : 'development',
        'checkout_path' => "/srv/{$slug}",
        'hostname' => $hostname ?? "{$slug}.old.test",
        'certificate_mode' => $certificateMode,
        'status' => $status,
    ]);
}

function tld_converger_workspace(
    Instance $instance,
    string $name,
    LifecycleStatus $status,
    ?string $hostname = null,
): Workspace {
    return Workspace::query()->create([
        'instance_id' => $instance->id,
        'name' => $name,
        'branch' => $name,
        'checkout_path' => "/srv/workspaces/{$instance->id}/{$name}",
        'hostname' => $hostname ?? "{$name}.{$instance->hostname}",
        'status' => $status,
    ]);
}

/** @param list<string> $events */
function tld_converger_runtime(array &$events): NativeAppDevTldConverger
{
    $caddy = new class($events) implements AppDevCaddyManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events[] = "caddy:{$node->id}";
        }

        public function remove(Node $node): void {}
    };
    $dns = new class($events) implements PrivateDnsManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(?Node $pendingNode = null): void
        {
            $this->events[] = "dns:{$pendingNode?->id}";
        }
    };
    $routes = new class($events) implements AppDevTldRouteManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events[] = "route:{$node->id}";
        }
    };

    return new NativeAppDevTldConverger($caddy, $dns, $routes);
}
