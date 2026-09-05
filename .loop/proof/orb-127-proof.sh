#!/usr/bin/env bash
set -euo pipefail

orbit=/home/orbit/orbit/apps/cli/orbit
gateway=/home/orbit/orbit/apps/gateway
instance_name=orb127-proof
hostname=orb127-proof.orbit

fail() {
    printf 'ORB-127 proof failed: %s\n' "$*" >&2
    exit 1
}

run_orbit() {
    sudo -u orbit -- env \
        HOME=/home/orbit \
        ORBIT_HOME=/home/orbit/.orbit \
        DB_DATABASE=/home/orbit/.orbit/gateway.sqlite \
        "$orbit" "$@"
}

run_gateway_tests() {
    (
        cd "$gateway"
        env -u DB_DATABASE -u ORBIT_HOME ./vendor/bin/pest "$@" --colors=never
    )
}

load_live_state() {
    [[ -x "$orbit" ]] || fail "Orbit CLI is unavailable"

    instance_json=$(run_orbit instance:list --json)
    route_json=$(run_orbit route:list --json)
    read -r instance_id route_id checkout_path < <(
        php -r '
            $instances = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
            $routes = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
            $matches = array_values(array_filter(
                $instances["app_instances"] ?? [],
                static fn (mixed $item): bool => is_array($item) && ($item["name"] ?? null) === $argv[3],
            ));
            if (count($matches) !== 1) exit(65);
            $instance = $matches[0];
            $route = $instance["route"] ?? null;
            if (
                !is_array($route)
                || ($instance["status"] ?? null) !== "active"
                || ($route["status"] ?? null) !== "active"
                || ($route["hostname"] ?? null) !== $argv[4]
                || ($instance["hostname"] ?? null) !== $argv[4]
                || ($instance["url"] ?? null) !== "https://" . $argv[4]
                || !is_int($instance["id"] ?? null)
                || !is_int($route["id"] ?? null)
                || !is_string($instance["checkout_path"] ?? null)
            ) exit(65);
            $routeMatches = array_values(array_filter(
                $routes["routes"] ?? [],
                static fn (mixed $item): bool => is_array($item) && ($item["id"] ?? null) === $route["id"],
            ));
            if (count($routeMatches) !== 1 || $routeMatches[0] !== array_diff_key($route, ["request_id" => true])) exit(65);
            printf("%d %d %s\n", $instance["id"], $route["id"], $instance["checkout_path"]);
        ' "$instance_json" "$route_json" "$instance_name" "$hostname"
    )
    [[ "$instance_id" =~ ^[1-9][0-9]*$ ]] || fail "invalid AppInstance identity"
    [[ "$route_id" =~ ^[1-9][0-9]*$ ]] || fail "invalid Route identity"
    [[ "$checkout_path" == /home/orbit/apps/* ]] || fail "invalid checkout path"
}

create_live_state() {
    local app_id node_id existing
    existing=$(run_orbit instance:list --json)
    if php -r '
        $instances = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
        foreach ($instances["app_instances"] ?? [] as $instance) {
            if (is_array($instance) && ($instance["name"] ?? null) === $argv[2]) exit(0);
        }
        exit(1);
    ' "$existing" "$instance_name"; then
        return
    fi

    app_id=$(run_orbit app:list --json | php -r '
        $apps = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter(
            $apps["apps"] ?? [],
            static fn (mixed $app): bool => is_array($app) && ($app["slug"] ?? null) === "laravel-typed",
        ));
        if (count($matches) !== 1 || !is_int($matches[0]["id"] ?? null)) exit(65);
        echo $matches[0]["id"];
    ')
    node_id=$(run_orbit node:list --json | php -r '
        $nodes = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter(
            $nodes["nodes"] ?? [],
            static fn (mixed $node): bool => is_array($node) && ($node["name"] ?? null) === "app-dev",
        ));
        if (count($matches) !== 1 || !is_int($matches[0]["id"] ?? null)) exit(65);
        echo $matches[0]["id"];
    ')
    run_orbit instance:new "$app_id" "$node_id" "$instance_name" --hostname="$hostname" --json >/dev/null
}

app_dev_configuration() {
    sudo bash -seu <<'BASH'
        while read -r import; do
            for file in $import; do
                [[ -f "$file" ]] && sed -n '1,260p' "$file"
            done
        done < <(awk '$1 == "import" { print $2 }' /etc/caddy/Caddyfile)
BASH
}

assert_live_https() {
    local certificate="/etc/caddy/orbit-certificates/app-instance-${instance_id}/current/cert.pem"
    local status
    sudo test -s "$certificate" || fail "workload certificate is missing"
    status=$(curl --silent --show-error \
        --connect-timeout 10 \
        --max-time 30 \
        --cacert /etc/ssl/certs/ca-certificates.crt \
        --resolve "${hostname}:443:127.0.0.1" \
        --output /dev/null \
        --write-out '%{http_code}' \
        "https://${hostname}/")
    [[ "$status" =~ ^[1-5][0-9][0-9]$ ]] || fail "HTTPS serving path returned no HTTP response"
}

case "${1-}" in
    composer-php-runtime-selection)
        create_live_state
        load_live_state
        [[ -S "/run/php/orbit-app-instance-${instance_id}.sock" ]] || fail "selected PHP-FPM socket is missing"
        systemctl is-active --quiet php8.5-fpm || fail "selected PHP 8.5 runtime is inactive"
        run_gateway_tests \
            tests/Feature/Infrastructure/AppInstances/PhpRuntimeSelectionTest.php \
            tests/Feature/Infrastructure/Nodes/RemotePhpPackageManagerTest.php
        printf 'active AppInstance %s uses the selected PHP 8.5 runtime\n' "$instance_id"
        ;;
    laravel-url-before-dependencies)
        load_live_state
        [[ -f "$checkout_path/artisan" && ! -L "$checkout_path/artisan" ]] || fail "Artisan marker is unsafe"
        php -r '
            $composer = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
            $declarations = 0;
            foreach (["require", "require-dev"] as $section) {
                if (array_key_exists("laravel/framework", $composer[$section] ?? [])) $declarations++;
            }
            if ($declarations !== 1) exit(65);
        ' "$checkout_path/composer.json"
        run_gateway_tests tests/Feature/Infrastructure/AppInstances/LaravelUrlConfigurationTest.php
        printf 'Laravel markers are safe and unambiguous before publication\n'
        ;;
    non-laravel-provisioning)
        load_live_state
        [[ -f "$checkout_path/.env" && ! -L "$checkout_path/.env" ]] || fail "Laravel environment file is unsafe"
        [[ "$(grep -c '^APP_URL=' "$checkout_path/.env")" -eq 1 ]] || fail "Laravel environment has a non-unique APP_URL"
        grep -Fx "APP_URL=https://${hostname}" "$checkout_path/.env" >/dev/null \
            || fail "Laravel APP_URL is not reconciled"
        run_gateway_tests tests/Feature/Infrastructure/AppInstances/LaravelUrlConfigurationTest.php \
            tests/Feature/Domain/ProvisionDevelopmentAppInstanceTest.php
        printf 'live Laravel APP_URL equals https://%s and non-Laravel behavior passed\n' "$hostname"
        ;;
    initial-node-route)
        load_live_state
        run_gateway_tests tests/Feature/Infrastructure/AppInstances/NativeDevelopmentRouteProjectorTest.php
        printf 'Node and Cluster projection paths passed for active Route %s\n' "$route_id"
        ;;
    initial-cluster-route)
        load_live_state
        php -r '
            $instances = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
            $matches = array_values(array_filter(
                $instances["app_instances"] ?? [],
                static fn (mixed $item): bool => is_array($item) && ($item["name"] ?? null) === $argv[2],
            ));
            $instance = $matches[0] ?? null;
            $route = is_array($instance) ? ($instance["route"] ?? null) : null;
            if (
                !is_array($route)
                || !is_int($route["cluster_id"] ?? null)
                || !array_key_exists("node_id", $route)
                || $route["node_id"] !== null
            ) exit(65);
        ' "$instance_json" "$instance_name"
        configuration=$(app_dev_configuration)
        [[ "$(grep -Fc "https://${hostname}" <<<"$configuration")" -eq 1 ]] \
            || fail "co-located Route does not have exactly one Caddy site"
        site=$(awk -v host="$hostname" '
            $0 ~ "^https://" host " \\{" { capture = 1 }
            capture { print }
            capture && /^}/ { exit }
        ' <<<"$configuration")
        grep -F "php_fastcgi unix//run/php/orbit-app-instance-${instance_id}.sock" <<<"$site" >/dev/null \
            || fail "co-located Route does not use local PHP-FPM"
        ! grep -F 'reverse_proxy' <<<"$site" >/dev/null || fail "co-located Route self-proxies"
        assert_live_https
        printf 'Cluster Route %s serves through one co-located local Caddy site\n' "$route_id"
        ;;
    initial-route-network-boundary)
        load_live_state
        firewall=$(sudo ufw status numbered)
        grep -F '# orbit:wireguard-members' <<<"$firewall" >/dev/null \
            || fail "WireGuard membership trust rule is missing"
        for retired in \
            orbit:vpn-ssh \
            orbit:app-dev-http \
            orbit:app-dev-https \
            orbit:app-dev-direct-http \
            orbit:app-dev-direct-https
        do
            ! grep -F "# $retired" <<<"$firewall" >/dev/null || fail "retired firewall rule remains: $retired"
        done
        ! grep -E '(^|[[:space:]])(80|443)/tcp([[:space:]]|$).*ALLOW IN.*Anywhere' <<<"$firewall" >/dev/null \
            || fail "public workload web listener remains"
        run_gateway_tests \
            tests/Feature/Infrastructure/Nodes/NativeNodeRoleFirewallManagerTest.php \
            tests/Feature/Infrastructure/Nodes/RoleCatalogContractsTest.php
        printf 'WireGuard trust is active and direct public app-dev listeners are absent\n'
        ;;
    initial-route-address-selection)
        load_live_state
        configuration=$(app_dev_configuration)
        ! grep -F 'reverse_proxy' <<<"$configuration" >/dev/null || fail "co-located topology unexpectedly proxies"
        run_gateway_tests tests/Feature/Infrastructure/AppInstances/NativeDevelopmentRouteProjectorTest.php
        printf 'LAN preference, no-fallback refusal, WireGuard fallback, and co-location passed\n'
        ;;
    initial-route-certificates)
        load_live_state
        certificate="/etc/caddy/orbit-certificates/app-instance-${instance_id}/current/cert.pem"
        key="/etc/caddy/orbit-certificates/app-instance-${instance_id}/current/key.pem"
        sudo test -s "$certificate" && sudo test -s "$key" \
            || fail "workload certificate pair is missing"
        sudo test ! -e "/etc/caddy/orbit-certificates/route-${route_id}-router/current" \
            || fail "co-located topology created a redundant Router certificate"
        sudo openssl x509 -in "$certificate" -noout -checkhost "$hostname" >/dev/null \
            || fail "workload leaf does not identify the Route hostname"
        certificate_key=$(sudo openssl x509 -in "$certificate" -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum)
        private_key=$(sudo openssl pkey -in "$key" -pubout -outform DER | sha256sum)
        [[ "$certificate_key" == "$private_key" ]] || fail "workload key does not match its certificate"
        run_gateway_tests tests/Feature/Infrastructure/AppInstances/NativeDevelopmentRouteProjectorTest.php
        printf 'workload certificate identity and dedicated Router certificate behavior passed\n'
        ;;
    development-provisioning-failures)
        load_live_state
        run_gateway_tests \
            tests/Feature/Domain/ProvisionDevelopmentAppInstanceTest.php \
            tests/Feature/Api/AppInstancesTest.php \
            tests/Feature/Api/ClusterRouterTest.php
        printf 'all named failure boundaries and identical retry behavior passed\n'
        ;;
    provisioned-route-mutation-guards)
        load_live_state
        before=$(php -r '
            $routes = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
            foreach ($routes["routes"] ?? [] as $route) {
                if (($route["id"] ?? null) === (int) $argv[2]) {
                    echo json_encode($route, JSON_THROW_ON_ERROR);
                    exit;
                }
            }
            exit(65);
        ' "$route_json" "$route_id")
        if refusal=$(run_orbit route:update "$route_id" --hostname=blocked.orbit --json 2>&1); then
            fail "active Route mutation unexpectedly succeeded"
        fi
        grep -F 'route.reconciliation_required' <<<"$refusal" >/dev/null \
            || fail "active Route mutation returned the wrong boundary: $refusal"
        after_json=$(run_orbit route:list --json)
        after=$(php -r '
            $routes = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
            foreach ($routes["routes"] ?? [] as $route) {
                if (($route["id"] ?? null) === (int) $argv[2]) {
                    echo json_encode($route, JSON_THROW_ON_ERROR);
                    exit;
                }
            }
            exit(65);
        ' "$after_json" "$route_id")
        [[ "$before" == "$after" ]] || fail "guarded Route changed after refusal"
        assert_live_https
        run_gateway_tests \
            tests/Feature/Domain/RouteMutationReconciliationTest.php \
            tests/Feature/Api/AppInstancesTest.php
        printf 'guard refused mutation and retained the live HTTPS serving path\n'
        ;;
    *)
        fail "unknown proof action: ${1-}"
        ;;
esac
