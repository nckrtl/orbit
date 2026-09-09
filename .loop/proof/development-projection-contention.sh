#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
state_fixture=/var/lib/orbit-e2e/proof/development-projection-state.php
worker_fixture=/var/lib/orbit-e2e/proof/development-projection-worker.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
working_directory=''
first_pid=''
second_pid=''
second_cluster_created=0
app_prod_role_removed=0
app_dev_role_added=0
app_prod_2_id=''
second_cluster_id=''
standard_router=''

if [[ ! -f "$state_fixture" ]]; then
    state_fixture="$repository/.loop/proof/development-projection-state.php"
    worker_fixture="$repository/.loop/proof/development-projection-worker.php"
fi

gateway_fixture() {
    (
        cd "$gateway"
        /usr/bin/php8.5 "$state_fixture" "$@"
    )
}

node_address() {
    case "$1" in
        app-dev) printf '10.44.0.2\n' ;;
        app-prod) printf '10.44.0.3\n' ;;
        app-prod-2) printf '10.44.0.4\n' ;;
        *) return 64 ;;
    esac
}

remote() {
    local node=$1
    shift
    ssh \
        -i "$ssh_key" \
        -o BatchMode=yes \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes \
        -o "UserKnownHostsFile=$known_hosts" \
        "orbit@$(node_address "$node")" \
        "$@"
}

remote_script() {
    local node=$1
    shift
    remote "$node" bash -seuo pipefail -- "$@"
}

wait_for_file() {
    local path=$1
    local remaining=6000

    while [[ ! -f "$path" ]]; do
        if [[ "$remaining" -eq 0 ]]; then
            printf 'Timed out waiting for %s\n' "$path" >&2
            return 1
        fi

        sleep 0.01
        remaining=$((remaining - 1))
    done
}

prepare_sources() {
    local node=$1
    shift

    remote_script "$node" "$@" <<'BASH'
for name in "$@"; do
    case "$name" in orb173-same-[ab]|orb173-different-[ab]) ;; *) exit 64 ;; esac
    path="/home/orbit/apps/laravel-typed/$name"
    rm -rf -- "$path"
    mkdir -p -- "$path/public"
    cat > "$path/composer.json" <<'JSON'
{"require":{"php":"^8.5","laravel/framework":"^13.0"}}
JSON
    cat > "$path/artisan" <<'PHP'
#!/usr/bin/env php
<?php
PHP
    printf '<?php echo "ORB-173";\n' > "$path/public/index.php"
    chmod 0755 -- "$path/artisan"
done
BASH
}

remove_sources() {
    local node=$1
    shift

    remote_script "$node" "$@" <<'BASH'
for name in "$@"; do
    case "$name" in orb173-same-[ab]|orb173-different-[ab]) ;; *) exit 64 ;; esac
    rm -rf -- "/home/orbit/apps/laravel-typed/$name"
done
BASH
}

arm_caddy_pause() {
    local case_name=$1

    remote_script app-dev "$case_name" <<'BASH'
case_name=$1
root="/home/orbit/.orb173-$case_name"
authorized=/home/orbit/.ssh/authorized_keys
backup="$root/authorized_keys"
wrapper="$root/wrapper"
rm -rf -- "$root"
mkdir -m 0700 -- "$root"
cp -- "$authorized" "$backup"
cat > "$wrapper" <<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail
root=${ORB173_PAUSE_ROOT:?}
if [[ "$SSH_ORIGINAL_COMMAND" == *'/run/lock/orbit/caddy.lock'* ]] && mkdir "$root/claimed" 2>/dev/null; then
    : > "$root/ready"
    deadline=$((SECONDS + 60))
    while [[ ! -e "$root/release" ]]; do
        if (( SECONDS >= deadline )); then
            exit 124
        fi
        sleep 0.01
    done
fi
exec bash -c "$SSH_ORIGINAL_COMMAND"
WRAPPER
chmod 0700 -- "$wrapper"
sed "s|^|command=\"env ORB173_PAUSE_ROOT=$root $wrapper\" |" "$backup" > "$authorized"
chmod 0600 -- "$authorized"
BASH
}

release_caddy_pause() {
    local case_name=$1
    remote app-dev touch "/home/orbit/.orb173-$case_name/release"
}

restore_authorization() {
    local case_name=$1

    remote_script app-dev "$case_name" <<'BASH'
root="/home/orbit/.orb173-$1"
authorized=/home/orbit/.ssh/authorized_keys
if [[ -f "$root/authorized_keys" ]]; then
    cp -- "$root/authorized_keys" "$authorized"
    chmod 0600 -- "$authorized"
fi
rm -rf -- "$root"
BASH
}

live_caddy_fragment() {
    local node=$1
    remote_script "$node" <<'BASH'
sudo bash -ceu 'main=$(readlink -f /etc/caddy/Caddyfile); cat "$(dirname "$main")/fragments/app-dev.caddy"'
BASH
}

assert_worker() {
    local result=$1
    local expected_name=$2

    /usr/bin/php8.5 -r '
        $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        if (
            ($value["name"] ?? null) !== $argv[2]
            || ($value["instance_status"] ?? null) !== "active"
            || ($value["route_status"] ?? null) !== "active"
            || ! is_float($value["duration_seconds"] ?? null)
            || $value["duration_seconds"] <= 0.25
            || $value["duration_seconds"] >= 120.0
        ) {
            exit(65);
        }
    ' "$result" "$expected_name"
}

assert_live_projection() {
    local case_name=$1
    local first_node=$2
    local second_node=$3
    local first_router=$4
    local second_router=$5
    local evidence dns caddy

    evidence=$(gateway_fixture evidence "$case_name")
    /usr/bin/php8.5 -r '
        $value = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
        foreach ($value["instances"] ?? [] as $position => $instance) {
            $expectedRouter = $position === 0 ? $argv[2] : $argv[3];
            if (
                ($instance["instance_status"] ?? null) !== "active"
                || ($instance["route_status"] ?? null) !== "active"
                || ($instance["router"] ?? null) !== $expectedRouter
            ) {
                exit(65);
            }
        }
    ' "$evidence" "$first_router" "$second_router"

    caddy=$(live_caddy_fragment "$first_node")
    grep -Fq -- "https://orb173-$case_name-a.orbit" <<< "$caddy"
    caddy=$(live_caddy_fragment "$second_node")
    grep -Fq -- "https://orb173-$case_name-b.orbit" <<< "$caddy"

    caddy=$(live_caddy_fragment "$first_router")
    grep -Fq -- "https://orb173-$case_name-a.orbit" <<< "$caddy"
    caddy=$(live_caddy_fragment "$second_router")
    grep -Fq -- "https://orb173-$case_name-b.orbit" <<< "$caddy"

    dns=$(cat /etc/dnsmasq.d/orbit-records.conf)
    grep -Fq -- "host-record=orb173-$case_name-a.orbit,$(node_address "$first_router")" <<< "$dns"
    grep -Fq -- "host-record=orb173-$case_name-b.orbit,$(node_address "$second_router")" <<< "$dns"
}

run_contention_case() {
    local case_name=$1
    local first_node=$2
    local second_node=$3
    local first_router=$4
    local second_router=$5
    local first_name="orb173-$case_name-a"
    local second_name="orb173-$case_name-b"
    local started ready_at released_at completed_at

    prepare_sources "$first_node" "$first_name"
    prepare_sources "$second_node" "$second_name"
    gateway_fixture seed "$case_name" "$first_node" "$second_node" >/dev/null
    arm_caddy_pause "$case_name"

    started=$(/usr/bin/date +%s%N)
    (
        cd "$gateway"
        /usr/bin/php8.5 "$worker_fixture" "$first_name"
    ) > "$working_directory/$case_name-a.json" 2> "$working_directory/$case_name-a.err" &
    first_pid=$!

    while ! remote app-dev test -f "/home/orbit/.orb173-$case_name/ready"; do
        sleep 0.02
    done
    ready_at=$(/usr/bin/date +%s%N)

    (
        cd "$gateway"
        /usr/bin/php8.5 "$worker_fixture" "$second_name"
    ) > "$working_directory/$case_name-b.json" 2> "$working_directory/$case_name-b.err" &
    second_pid=$!
    sleep 0.5
    kill -0 "$second_pid"
    gateway_fixture pending "$second_name" >/dev/null

    release_caddy_pause "$case_name"
    released_at=$(/usr/bin/date +%s%N)
    wait "$first_pid"
    first_pid=''
    wait "$second_pid"
    second_pid=''
    completed_at=$(/usr/bin/date +%s%N)
    restore_authorization "$case_name"

    assert_worker "$working_directory/$case_name-a.json" "$first_name"
    assert_worker "$working_directory/$case_name-b.json" "$second_name"
    assert_live_projection "$case_name" "$first_node" "$second_node" "$first_router" "$second_router"

    /usr/bin/php8.5 -r '
        $ready = ((int) $argv[2] - (int) $argv[1]) / 1_000_000_000;
        $held = ((int) $argv[3] - (int) $argv[2]) / 1_000_000_000;
        $finish = ((int) $argv[4] - (int) $argv[3]) / 1_000_000_000;
        if ($ready <= 0.0 || $ready >= 30.0 || $held < 0.45 || $held >= 30.0 || $finish <= 0.0 || $finish >= 120.0) {
            exit(65);
        }
        echo json_encode([
            "case" => $argv[5],
            "owner_ready_seconds" => round($ready, 6),
            "owner_held_seconds" => round($held, 6),
            "waiters_finished_after_release_seconds" => round($finish, 6),
        ], JSON_THROW_ON_ERROR), PHP_EOL;
    ' "$started" "$ready_at" "$released_at" "$completed_at" "$case_name"

    gateway_fixture cleanup "$case_name" >/dev/null
    remove_sources "$first_node" "$first_name"
    remove_sources "$second_node" "$second_name"
}

restore_extended_node() {
    if [[ -z "$app_prod_2_id" ]]; then
        app_prod_2_id=$(orbit node:list --json | /usr/bin/php8.5 -r '
            $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            foreach ($v["nodes"] ?? [] as $node) if (($node["name"] ?? null) === "app-prod-2") { echo $node["id"]; exit; }
            exit(65);
        ')
    fi
    if [[ -z "$second_cluster_id" ]]; then
        second_cluster_id=$(orbit cluster:list --json | /usr/bin/php8.5 -r '
            $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            foreach ($v["clusters"] ?? [] as $cluster) if (($cluster["name"] ?? null) === "orb173-second") { echo $cluster["id"]; exit; }
        ')
    fi

    set +e
    if [[ -n "$second_cluster_id" ]]; then
        orbit cluster:update "$second_cluster_id" --state=inactive --json >/dev/null 2>&1
        orbit cluster:router:clear "$second_cluster_id" --force --json >/dev/null 2>&1
        orbit node:role:remove "$app_prod_2_id" app-dev --force --json >/dev/null 2>&1
        orbit cluster:node:detach "$second_cluster_id" "$app_prod_2_id" --force --json >/dev/null 2>&1
        orbit cluster:remove "$second_cluster_id" --force --json >/dev/null 2>&1
    fi
    orbit node:role:add "$app_prod_2_id" app-prod --converge --json >/dev/null 2>&1
    set -e
}

prepare_extended_node() {
    orbit node:role:remove "$app_prod_2_id" app-prod --force --json >/dev/null
    app_prod_role_removed=1
    second_cluster_id=$(orbit cluster:new orb173-second --tld=orb173 --json | /usr/bin/php8.5 -r '
        $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if (!is_int($v["id"] ?? null)) exit(65);
        echo $v["id"];
    ')
    second_cluster_created=1
    orbit cluster:node:attach "$second_cluster_id" "$app_prod_2_id" --json >/dev/null
    orbit node:role:add "$app_prod_2_id" app-dev --converge --json >/dev/null
    app_dev_role_added=1
    orbit cluster:router:set "$second_cluster_id" "$app_prod_2_id" --json >/dev/null
    orbit cluster:update "$second_cluster_id" --state=active --json >/dev/null
    printf 'extended app-dev Router topology ready\n'
}

cleanup() {
    local primary_status=$?

    trap - EXIT INT TERM
    set +e
    if [[ -n "$first_pid" ]]; then kill "$first_pid" 2>/dev/null; wait "$first_pid" 2>/dev/null; fi
    if [[ -n "$second_pid" ]]; then kill "$second_pid" 2>/dev/null; wait "$second_pid" 2>/dev/null; fi
    remote app-dev touch /home/orbit/.orb173-same/release /home/orbit/.orb173-different/release >/dev/null 2>&1
    restore_authorization same >/dev/null 2>&1
    restore_authorization different >/dev/null 2>&1
    gateway_fixture cleanup same >/dev/null 2>&1
    gateway_fixture cleanup different >/dev/null 2>&1
    remove_sources app-dev orb173-same-a orb173-same-b orb173-different-a >/dev/null 2>&1
    remove_sources app-prod-2 orb173-different-b >/dev/null 2>&1
    restore_extended_node >/dev/null 2>&1
    if [[ "$primary_status" -ne 0 && -n "$working_directory" && -d "$working_directory" ]]; then
        for diagnostic in "$working_directory"/*; do
            [[ -f "$diagnostic" ]] || continue
            printf '%s\n' "--- $(basename "$diagnostic")" >&2
            cat -- "$diagnostic" >&2
        done
    fi
    if [[ "$primary_status" -eq 0 && -n "$working_directory" ]]; then
        rm -rf -- "$working_directory"
    fi
    exit "$primary_status"
}

probe_surfaces() {
    gateway_fixture probe
    orbit node:list --json | /usr/bin/php8.5 -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (!is_array($v["nodes"] ?? null)) exit(65);'
    remote app-dev orbit node:list --json | /usr/bin/php8.5 -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (!is_array($v["nodes"] ?? null)) exit(65);'
}

case "$scenario" in
    probe)
        probe_surfaces
        printf 'development projection observation surfaces ready\n'
        ;;

    acceptance)
        trap cleanup EXIT INT TERM
        working_directory=$(mktemp -d /home/orbit/.orb173-results.XXXXXXXX)
        probe_surfaces >/dev/null

        read -r app_prod_2_id standard_router < <(
            gateway_fixture probe | /usr/bin/php8.5 -r '
                $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
                echo $v["app_prod_2"]["id"], " ", $v["router"]["name"], "\n";
            '
        )

        run_contention_case same app-dev app-dev "$standard_router" "$standard_router"

        prepare_extended_node

        run_contention_case different app-dev app-prod-2 "$standard_router" app-prod-2

        restore_extended_node
        printf 'extended app-prod topology restored\n'
        app_dev_role_added=0
        second_cluster_created=0
        app_prod_role_removed=0
        gateway_fixture restored
        probe_surfaces >/dev/null
        rm -rf -- "$working_directory"
        working_directory=''
        trap - EXIT INT TERM
        printf 'same-Router and different-Router development projection contention passed\n'
        ;;

    *)
        exit 64
        ;;
esac
