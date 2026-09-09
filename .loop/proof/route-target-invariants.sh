#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/route-target-invariants.php
api_before=/home/orbit/.orbit/orb187-route-api-before.json

if [[ ! -f "$fixture" ]]; then
    fixture="$repository/.loop/proof/route-target-invariants.php"
fi

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

fixture_api_snapshot() {
    orbit route:list --json | php -r '
        $value = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $routes = array_values(array_filter(
            $value["routes"] ?? [],
            static fn (mixed $route): bool => is_array($route)
                && is_string($route["hostname"] ?? null)
                && str_starts_with($route["hostname"], "orb187-"),
        ));
        usort($routes, static fn (array $left, array $right): int => $left["id"] <=> $right["id"]);
        echo json_encode($routes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    '
}

assert_unchanged() {
    gateway_fixture assert-unchanged >/dev/null
    fixture_api_snapshot | cmp --silent -- "$api_before" -
}

expect_conflict() {
    local expected_message=$1
    shift
    local output status
    set +e
    output=$(orbit "$@" --json 2>&1)
    status=$?
    set -e
    [[ "$status" -ne 0 ]]
    php -r '
        $value = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
        $error = $value["error"] ?? null;
        if (
            ! is_array($error)
            || ($error["code"] ?? null) !== "route.target_conflict"
            || ($error["message"] ?? null) !== $argv[2]
            || ! is_string($error["request_id"] ?? null)
            || $error["request_id"] === ""
        ) {
            exit(65);
        }
    ' "$output" "$expected_message"
    assert_unchanged
}

expect_route() {
    local expected_route=$1
    local expected_target=$2
    shift 2
    local output
    output=$(orbit "$@" --json)
    php -r '
        $value = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
        $target = $value["target"]["app_instance_id"] ?? null;
        $expectedTarget = $argv[3] === "null" ? null : (int) $argv[3];
        if (
            ($value["id"] ?? null) !== (int) $argv[2]
            || $target !== $expectedTarget
            || ! is_string($value["request_id"] ?? null)
            || $value["request_id"] === ""
        ) {
            exit(65);
        }
    ' "$output" "$expected_route" "$expected_target"
}

case "$scenario" in
    setup)
        test "$(id -u)" -eq 1000
        (
            cd "$gateway"
            php artisan migrate --force --no-interaction >/dev/null
        )
        gateway_fixture setup >/dev/null
        fixture_api_snapshot > "$api_before"
        test "$(php -r '$v=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo count($v);' "$api_before")" -eq 4
        assert_unchanged
        ;;

    acceptance)
        test "$(id -u)" -eq 1000
        identity=$(gateway_fixture identity)
        read -r primary replacement owned primary_route owned_route requested_route empty_route < <(
            php -r '
                $v = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
                foreach ([
                    "primary_instance_id",
                    "replacement_instance_id",
                    "owned_instance_id",
                    "primary_route_id",
                    "owned_route_id",
                    "requested_route_id",
                    "empty_route_id",
                ] as $key) {
                    if (! is_int($v[$key] ?? null)) exit(65);
                    echo $v[$key], " ";
                }
                echo "\n";
            ' "$identity"
        )

        association_message="Active AppInstance [$primary] must remain associated with Route [$primary_route]."
        expect_conflict "$association_message" route:target:set "$primary_route" "$replacement"
        expect_conflict "$association_message" route:target:clear "$primary_route"
        expect_conflict "$association_message" route:remove "$primary_route"

        ownership_message="AppInstance [$owned] is already associated with Route [$owned_route] and cannot be assigned to Route [$requested_route]."
        expect_conflict "$ownership_message" route:target:set "$requested_route" "$owned"

        expect_route "$primary_route" "$primary" route:target:set "$primary_route" "$primary"
        assert_unchanged
        expect_route "$empty_route" null route:target:clear "$empty_route"
        assert_unchanged

        gateway_fixture prepare-cleanup >/dev/null
        expect_route "$primary_route" null route:target:clear "$primary_route"
        expect_route "$primary_route" null route:remove "$primary_route"
        expect_route "$owned_route" null route:target:clear "$owned_route"
        expect_route "$owned_route" null route:remove "$owned_route"
        expect_route "$requested_route" null route:remove "$requested_route"
        expect_route "$empty_route" null route:remove "$empty_route"
        gateway_fixture cleanup >/dev/null
        fixture_api_snapshot | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if ($v !== []) exit(65);'
        rm -f -- "$api_before"
        printf 'route target invariant refusals and recovery passed\n'
        ;;

    *)
        exit 64
        ;;
esac
