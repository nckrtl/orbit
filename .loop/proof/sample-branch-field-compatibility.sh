#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /

orbit=/home/orbit/orbit/apps/cli/orbit
converge=/usr/local/bin/converge-sample-app.sh

fail() {
  printf 'ORB-128 proof failed: %s\n' "$*" >&2
  exit 1
}

run_orbit() {
  sudo -u orbit -- env \
    HOME=/home/orbit \
    ORBIT_HOME=/home/orbit/.orbit \
    DB_DATABASE=/home/orbit/.orbit/gateway.sqlite \
    "$orbit" "$@"
}

sample_snapshot() {
  local apps instances routes
  apps=$(run_orbit app:list --json)
  instances=$(run_orbit instance:list --json)
  routes=$(run_orbit route:list --json)

  php -r '
    $apps = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    $instances = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $routes = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
    foreach ([[$apps, "apps"], [$instances, "app_instances"], [$routes, "routes"]] as [$envelope, $key]) {
        if (!is_array($envelope) || array_is_list($envelope) || !is_array($envelope[$key] ?? null) || !array_is_list($envelope[$key])) exit(65);
        foreach ($envelope[$key] as $item) if (!is_array($item) || array_is_list($item)) exit(65);
    }

    $appMatches = array_values(array_filter($apps["apps"], static fn (array $app): bool => ($app["slug"] ?? null) === "laravel-typed"));
    if (count($appMatches) !== 1) exit(65);
    $app = $appMatches[0];
    $branchField = array_key_exists("default_branch", $app) ? "default_branch" : "main_branch";
    $branch = $app[$branchField] ?? null;
    if (
        !is_int($app["id"] ?? null) || $app["id"] < 1
        || ($app["repository_url"] ?? null) !== "https://github.com/laravel/laravel.git"
        || ($app["name"] ?? null) !== "Laravel"
        || ($app["root"] ?? null) !== "public"
        || !is_string($branch) || $branch === ""
    ) exit(65);

    $instanceMatches = array_values(array_filter($instances["app_instances"], static fn (array $instance): bool => ($instance["name"] ?? null) === "e2e-dev"));
    if (count($instanceMatches) !== 1) exit(65);
    $instance = $instanceMatches[0];
    if (
        !is_int($instance["id"] ?? null) || $instance["id"] < 1
        || ($instance["app_id"] ?? null) !== $app["id"]
        || !is_int($instance["node_id"] ?? null) || $instance["node_id"] < 1
        || ($instance["status"] ?? null) !== "active"
        || !is_string($instance["checkout_path"] ?? null)
        || !str_starts_with($instance["checkout_path"], "/")
        || !str_ends_with($instance["checkout_path"], "/laravel-typed/e2e-dev")
        || !is_string($instance["selected_branch"] ?? null) || $instance["selected_branch"] === ""
        || !is_string($instance["starting_commit"] ?? null)
        || preg_match("/\\A[0-9a-f]{40}\\z/D", $instance["starting_commit"]) !== 1
        || ($instance["effective_root"] ?? null) !== "public"
    ) exit(65);

    $routeMatches = [];
    foreach ($routes["routes"] as $route) {
        $target = $route["target"] ?? null;
        if (($route["hostname"] ?? null) === "e2e-dev.orbit" || (is_array($target) && ($target["app_instance_id"] ?? null) === $instance["id"])) {
            $routeMatches[] = $route;
        }
    }
    if (count($routeMatches) !== 1) exit(65);
    $route = $routeMatches[0];
    $target = $route["target"] ?? null;
    foreach (["node_id", "cluster_id", "generation_basis_node_id", "status", "failed_step", "error_code"] as $key) {
        if (!array_key_exists($key, $route)) exit(65);
    }
    if (
        !is_int($route["id"] ?? null) || $route["id"] < 1
        || ($route["app_id"] ?? null) !== $app["id"]
        || $route["node_id"] !== null
        || !is_int($route["cluster_id"]) || $route["cluster_id"] < 1
        || $route["generation_basis_node_id"] !== null
        || ($route["hostname"] ?? null) !== "e2e-dev.orbit"
        || ($route["provenance"] ?? null) !== "explicit"
        || ($route["publication"] ?? null) !== "private"
        || !is_string($route["status"]) || $route["status"] === ""
        || (!is_string($route["failed_step"]) && $route["failed_step"] !== null)
        || (!is_string($route["error_code"]) && $route["error_code"] !== null)
        || !is_array($target) || array_is_list($target)
        || !is_int($target["id"] ?? null) || $target["id"] < 1
        || ($target["app_instance_id"] ?? null) !== $instance["id"]
        || ($target["position"] ?? null) !== 0
    ) exit(65);

    echo json_encode([
        "app" => [
            "id" => $app["id"],
            "repository_url" => $app["repository_url"],
            "slug" => $app["slug"],
            "name" => $app["name"],
            "root" => $app["root"],
            "branch_field" => $branchField,
            "branch" => $branch,
        ],
        "app_instance" => [
            "id" => $instance["id"],
            "app_id" => $instance["app_id"],
            "node_id" => $instance["node_id"],
            "checkout_path" => $instance["checkout_path"],
            "selected_branch" => $instance["selected_branch"],
            "starting_commit" => $instance["starting_commit"],
            "effective_root" => $instance["effective_root"],
            "status" => $instance["status"],
        ],
        "route" => [
            "id" => $route["id"],
            "app_id" => $route["app_id"],
            "node_id" => $route["node_id"],
            "cluster_id" => $route["cluster_id"],
            "generation_basis_node_id" => $route["generation_basis_node_id"],
            "hostname" => $route["hostname"],
            "provenance" => $route["provenance"],
            "publication" => $route["publication"],
            "status" => $route["status"],
            "failed_step" => $route["failed_step"],
            "error_code" => $route["error_code"],
            "target" => [
                "id" => $target["id"],
                "app_instance_id" => $target["app_instance_id"],
                "position" => $target["position"],
            ],
        ],
    ], JSON_THROW_ON_ERROR), "\n";
  ' "$apps" "$instances" "$routes"
}

[[ -x "$orbit" ]] || fail "Orbit CLI is unavailable"
[[ -x "$converge" ]] || fail "sample convergence script is unavailable"

before=$(sample_snapshot)
"$converge" create-resources app-dev app-prod 0000000000000000000000000000000000000000 >/dev/null
after=$(sample_snapshot)
[[ "$after" == "$before" ]] || fail "sample identities changed during repeated convergence"

php -r '$snapshot=json_decode($argv[1], true, 16, JSON_THROW_ON_ERROR); printf("App %d, AppInstance %d, source %s@%s, and Route %d were reused.\n", $snapshot["app"]["id"], $snapshot["app_instance"]["id"], $snapshot["app_instance"]["selected_branch"], $snapshot["app_instance"]["starting_commit"], $snapshot["route"]["id"]);' "$after"
