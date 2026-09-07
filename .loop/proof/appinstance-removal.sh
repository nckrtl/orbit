#!/usr/bin/env bash
set -euo pipefail

scenario="${1:-}"
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
state=/tmp/orb-124-normal-removal.json

probe_gateway() {
    orbit node:list --json | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if not isinstance(value.get("nodes"), list) or not isinstance(value.get("request_id"), str):
    raise SystemExit(65)
'
}

gateway_test() {
    local file=$1
    shift
    (
        cd "$gateway"
        env \
            APP_CONFIG_CACHE=/tmp/orb-124-no-config-cache.php \
            APP_ENV=testing \
            DB_CONNECTION=sqlite \
            DB_DATABASE=:memory: \
            php artisan test --no-tia "$file" "$@"
    )
}

assert_normal_removal() {
    test -f "$state"
    local id checkout hostname result
    read -r id checkout hostname < <(python3 -c '
import json
import sys

with open(sys.argv[1], encoding="utf-8") as source:
    value = json.load(source)
if type(value.get("id")) is not int:
    raise SystemExit(65)
if not isinstance(value.get("checkout_path"), str) or not isinstance(value.get("hostname"), str):
    raise SystemExit(65)
print(value["id"], value["checkout_path"], value["hostname"])
' "$state")
    test -d "$checkout"
    result=$(orbit instance:remove "$id" --json)
    python3 -c '
import json
import sys

value = json.loads(sys.argv[1])
expected_id = int(sys.argv[2])
valid = (
    value.get("id") == expected_id
    and value.get("status") == "completed"
    and value.get("force") is False
    and "current_step" in value
    and value["current_step"] is None
    and value.get("total") == 1
    and value.get("completed") == 1
    and value.get("remaining") == 0
    and "failed_step" in value
    and value["failed_step"] is None
    and "error_code" in value
    and value["error_code"] is None
)
if not valid:
    raise SystemExit(65)
' "$result" "$id"
    test ! -e "$checkout"
    orbit instance:list --json | python3 -c '
import json
import sys

value = json.load(sys.stdin)
expected_id = int(sys.argv[1])
if any(instance.get("id") == expected_id for instance in value.get("app_instances", [])):
    raise SystemExit(65)
' "$id"
    orbit route:list --json | python3 -c '
import json
import sys

value = json.load(sys.stdin)
if any(route.get("hostname") == sys.argv[1] for route in value.get("routes", [])):
    raise SystemExit(65)
' "$hostname"
}

assert_shared_production_removal() {
    (
        cd "$gateway"
        php <<'PHP'
<?php

declare(strict_types=1);

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$laravel = require 'bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$cluster = Cluster::query()->where('state', 'active')->sole();
$router = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', 'router')->where('status', 'active'))->sole();
$nodes = Node::query()->whereIn('name', ['app-prod', 'app-prod-2'])->orderBy('name')->get();
if ($nodes->count() !== 2 || $nodes->contains(static fn (Node $node): bool => $node->cluster_id !== null)) {
    exit(65);
}
$nodes->each(static fn (Node $node) => $node->update(['cluster_id' => $cluster->id]));
$app = App::query()->sole();
$instances = $nodes->values()->map(static function (Node $node, int $position) use ($app): AppInstance {
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'orb124-prod-'.($position + 1),
        'environment' => 'production',
        'source_layout' => 'checkout',
        'checkout_path' => '/srv/orbit/releases/orb124-prod-'.($position + 1),
        'root' => 'public',
        'branch' => '13.x',
        'starting_commit' => str_repeat((string) ($position + 1), 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::SourceResolved,
    ]);
});
$route = Route::query()->create([
    'app_id' => $app->id,
    'cluster_id' => $cluster->id,
    'hostname' => 'orb124-shared.orbit',
    'provenance' => RouteProvenance::Explicit,
    'publication' => RoutePublication::Private,
    'status' => RouteStatus::Pending,
]);
foreach ($instances as $position => $instance) {
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => $position]);
}
$route->update(['status' => RouteStatus::Active]);
$instances->each(static fn (AppInstance $instance) => $instance->update(['status' => AppInstanceState::Active]));
app(RemoteAppDevCertificateManager::class)->convergeRouteRouter($route, $router);
app(RemoteAppDevCaddyManager::class)->converge($router);
app(DnsmasqPrivateDnsManager::class)->converge();

$departing = $instances->firstOrFail()->refresh();
$remaining = $instances->last()->refresh();
$first = app(RemoveAppInstanceAction::class)->execute($departing, false);
$retained = $route->refresh()->targets()->orderBy('position')->pluck('app_instance_id')->all();
if ($first->status->value !== 'completed' || $retained !== [$remaining->id]) {
    exit(65);
}
if (AppInstance::query()->find($departing->id) !== null || Route::query()->find($route->id) === null) {
    exit(65);
}

$second = app(RemoveAppInstanceAction::class)->execute($remaining->refresh(), false);
if ($second->status->value !== 'completed' || Route::query()->find($route->id) !== null) {
    exit(65);
}
if (AppInstance::query()->whereIn('id', $instances->pluck('id'))->exists()) {
    exit(65);
}
$nodes->each(static fn (Node $node) => $node->update(['cluster_id' => null]));
PHP
    )
}

case "${scenario}" in
    setup)
        probe_gateway
        app_id=$(orbit app:list --json | python3 -c 'import json, sys; value = json.load(sys.stdin); apps = value.get("apps", []); sys.exit(65) if len(apps) != 1 or type(apps[0].get("id")) is not int else None; print(apps[0]["id"])')
        node_id=$(orbit node:list --json | python3 -c 'import json, sys; value = json.load(sys.stdin); nodes = [node for node in value.get("nodes", []) if node.get("name") == "app-dev"]; sys.exit(65) if len(nodes) != 1 or type(nodes[0].get("id")) is not int else None; print(nodes[0]["id"])')
        test ! -e /home/orbit/apps/laravel-typed/orb124-normal
        orbit instance:new "$app_id" "$node_id" orb124-normal --branch=13.x --hostname=orb124-normal.orbit --json > "$state"
        python3 -c 'import json, sys; value = json.load(open(sys.argv[1], encoding="utf-8")); sys.exit(65) if value.get("status") != "active" or value.get("name") != "orb124-normal" else None' "$state"
        ;;
    setup-gateway)
        cd "$gateway"
        php artisan migrate:status | grep -F '2026_09_07_200000_make_app_instance_removal_retry_safe' >/dev/null
        php artisan route:list --name=instance:remove --json | python3 -c 'import json, sys; sys.exit(65) if len(json.load(sys.stdin)) != 1 else None'
        ;;
    removal-preflight-refusals)
        probe_gateway
        gateway_test tests/Feature/Api/AppInstancesTest.php --filter='keeps preflight refusals'
        gateway_test tests/Feature/Infrastructure/AppInstances/RemoteDevelopmentAppInstanceSourceLifecycleTest.php --filter='does not let force waive origin or symlink'
        ;;
    normal-layout-removal)
        probe_gateway
        assert_normal_removal
        gateway_test tests/Feature/Infrastructure/AppInstances/RemoteDevelopmentAppInstanceSourceLifecycleTest.php --filter='refuses dirty and unpublished source'
        ;;
    forced-worktree-cascade)
        probe_gateway
        gateway_test tests/Feature/Domain/AppInstanceRemovalCoordinatorTest.php --filter='fixed worktree-first set'
        ;;
    forced-removal-boundaries)
        probe_gateway
        gateway_test tests/Feature/Infrastructure/AppInstances/RemoteDevelopmentAppInstanceSourceLifecycleTest.php
        ;;
    worktree-removal-preserves-common-git)
        probe_gateway
        gateway_test tests/Feature/Infrastructure/AppInstances/RemoteDevelopmentAppInstanceSourceLifecycleTest.php --filter='retaining its branch common repository'
        ;;
    checkout-worktree-removal-boundary)
        probe_gateway
        gateway_test tests/Feature/Domain/AppInstanceRemovalCoordinatorTest.php --filter='normal checkout cascade'
        ;;
    shared-route-target-removal)
        assert_shared_production_removal
        gateway_test tests/Feature/Database/AppInstanceRouteConstraintTest.php
        gateway_test tests/Feature/Infrastructure/AppInstances/NativeAppInstanceRemovalProjectorTest.php --filter='shared production target'
        ;;
    final-target-route-removal)
        probe_gateway
        gateway_test tests/Feature/Infrastructure/AppInstances/NativeAppInstanceRemovalProjectorTest.php --filter='final Route|final production Route'
        ;;
    removal-route-before-source)
        probe_gateway
        gateway_test tests/Feature/Api/AppInstancesTest.php --filter='durable checkpoint'
        ;;
    interrupted-removal-state)
        probe_gateway
        gateway_test tests/Feature/Domain/AppInstanceRemovalCoordinatorTest.php --filter='lost Route-deletion response'
        gateway_test tests/Feature/Api/AppInstancesTest.php --filter='resumes without recreating'
        ;;
    removal-retry-revalidation)
        probe_gateway
        gateway_test tests/Feature/Domain/AppInstanceRemovalCoordinatorTest.php --filter='revalidates the unfinished fixed inventory'
        gateway_test tests/Feature/Infrastructure/AppInstances/RemoteDevelopmentAppInstanceSourceLifecycleTest.php --filter='matching durable completion receipt'
        ;;
    coordinated-development-removal)
        probe_gateway
        gateway_test tests/Feature/Infrastructure/AppInstances/NativeAppInstanceRemovalProjectorTest.php --filter='exact transient development 503'
        ;;
    removal-retains-branches)
        probe_gateway
        gateway_test tests/Feature/Infrastructure/AppInstances/RemoteDevelopmentAppInstanceSourceLifecycleTest.php --filter='retaining its branch common repository|starting commit ancestry'
        ;;
    *)
        printf 'Unknown ORB-124 proof scenario: %s\n' "${scenario}" >&2
        exit 64
        ;;
esac

printf 'ORB-124 %s: ok\n' "$scenario"
