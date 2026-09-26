<?php

declare(strict_types=1);

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\NativeAppDevTldConverger;
use App\Models\Node;

it('republishes AppInstance projections when the app-dev TLD converges', function (): void {
    $node = tld_converger_node('dev', 'new.test', LifecycleStatus::Provisioning);
    $unrelated = tld_converger_node('other-dev', 'other.test');
    $events = [];

    tld_converger_runtime($events)->converge($node);

    expect($events)
        ->toBe([
            "caddy:{$node->id}",
            "dns:{$node->id}",
            "route:{$node->id}",
        ])
        ->not
        ->toContain("caddy:{$unrelated->id}", "dns:{$unrelated->id}", "route:{$unrelated->id}");
});

it('repeats AppInstance publication without rewriting leftover hostnames', function (): void {
    $node = tld_converger_node('dev', 'new.test', LifecycleStatus::Provisioning);
    $events = [];
    $converger = tld_converger_runtime($events);

    $converger->converge($node);
    $converger->converge($node);

    expect($events)
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

/** @param list<string> $events */
function tld_converger_runtime(array &$events): NativeAppDevTldConverger
{
    $caddy = new class($events) implements AppDevCaddyManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node, RoleName $role = RoleName::AppDev): void
        {
            $this->events[] = "caddy:{$node->id}";
        }

        public function remove(Node $node, RoleName $role = RoleName::AppDev): void {}
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
