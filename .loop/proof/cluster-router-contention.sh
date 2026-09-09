#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
state_fixture=/var/lib/orbit-e2e/proof/cluster-router-state.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
working_directory=''
first_pid=''
second_pid=''
app_prod_id=''
app_prod_2_id=''
orbit_home=''
declare -a cluster_ids=()

if [[ ! -f "$state_fixture" ]]; then
    state_fixture="$repository/.loop/proof/cluster-router-state.php"
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

probe_surfaces() {
    gateway_fixture probe
    orbit node:list --json | /usr/bin/php8.5 -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (!is_array($v["nodes"] ?? null)) exit(65);'
    remote app-dev orbit node:list --json | /usr/bin/php8.5 -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (!is_array($v["nodes"] ?? null)) exit(65);'
}

new_cluster() {
    local name=$1
    orbit cluster:new "$name" --json | /usr/bin/php8.5 -r '
        $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if (!is_int($v["id"] ?? null)) exit(65);
        echo $v["id"];
    '
}

assert_cluster_response() {
    local path=$1
    local expected_id=$2
    /usr/bin/php8.5 -r '
        $v=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        if (($v["id"] ?? null) !== (int) $argv[2]) exit(65);
    ' "$path" "$expected_id"
}

assert_busy_response() {
    local path=$1
    /usr/bin/php8.5 -r '
        $v=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        if (
            ($v["error"]["code"] ?? null) !== "cluster.router_busy"
            || ($v["error"]["message"] ?? null) !== "Another Cluster Router operation is active. Retry the request."
            || !is_string($v["error"]["request_id"] ?? null)
        ) exit(65);
    ' "$path"
}

arm_router_pause() {
    local node=$1
    local case_name=$2

    remote_script "$node" "$case_name" <<'BASH'
case_name=$1
root="/home/orbit/.orb172-$case_name"
authorized=/home/orbit/.ssh/authorized_keys
backup="$root/authorized_keys"
wrapper="$root/wrapper"
rm -rf -- "$root"
mkdir -m 0700 -- "$root"
cp -- "$authorized" "$backup"
cat > "$wrapper" <<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail
root=${ORB172_PAUSE_ROOT:?}
if [[ "$SSH_ORIGINAL_COMMAND" == *"'--' 'router'"* ]] && mkdir "$root/claimed" 2>/dev/null; then
    : > "$root/ready"
    deadline=$((SECONDS + 240))
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
sed "s|^|command=\"env ORB172_PAUSE_ROOT=$root $wrapper\" |" "$backup" > "$authorized"
chmod 0600 -- "$authorized"
BASH
}

wait_for_pause() {
    local node=$1
    local case_name=$2
    local remaining=3000

    while ! remote "$node" test -f "/home/orbit/.orb172-$case_name/ready"; do
        if [[ "$remaining" -eq 0 ]]; then
            printf 'Timed out waiting for Router pause [%s].\n' "$case_name" >&2
            return 1
        fi
        sleep 0.02
        remaining=$((remaining - 1))
    done
}

release_router_pause() {
    local node=$1
    local case_name=$2
    remote "$node" touch "/home/orbit/.orb172-$case_name/release"
}

restore_authorization() {
    local node=$1
    local case_name=$2

    remote_script "$node" "$case_name" <<'BASH'
root="/home/orbit/.orb172-$1"
authorized=/home/orbit/.ssh/authorized_keys
if [[ -f "$root/authorized_keys" ]]; then
    cp -- "$root/authorized_keys" "$authorized"
    chmod 0600 -- "$authorized"
fi
rm -rf -- "$root"
BASH
}

start_router_set() {
    local cluster_id=$1
    local node_id=$2
    local label=$3

    (
        orbit cluster:router:set "$cluster_id" "$node_id" --json
    ) > "$working_directory/$label.json" 2> "$working_directory/$label.err" &
    first_pid=$!
}

wait_first() {
    local label=$1
    local cluster_id=$2
    local status

    set +e
    wait "$first_pid"
    status=$?
    set -e
    first_pid=''

    if [[ "$status" -ne 0 ]]; then
        cat -- "$working_directory/$label.err" >&2
        return "$status"
    fi

    assert_cluster_response "$working_directory/$label.json" "$cluster_id"
}

wait_second() {
    local label=$1
    local cluster_id=$2
    local status

    set +e
    wait "$second_pid"
    status=$?
    set -e
    second_pid=''

    if [[ "$status" -ne 0 ]]; then
        cat -- "$working_directory/$label.err" >&2
        return "$status"
    fi

    assert_cluster_response "$working_directory/$label.json" "$cluster_id"
}

cleanup_cluster() {
    local cluster_id=$1
    shift

    orbit cluster:router:clear "$cluster_id" --force --json >/dev/null
    for node_id in "$@"; do
        orbit cluster:node:detach "$cluster_id" "$node_id" --force --json >/dev/null
    done
    orbit cluster:remove "$cluster_id" --force --json >/dev/null
}

emit_durations() {
    /usr/bin/php8.5 -r '
        $values=[];
        for ($i=1; $i<count($argv); $i+=2) {
            $name=$argv[$i];
            $duration=(float) $argv[$i+1];
            if ($duration <= 0.0 || $duration >= 240.0) exit(65);
            $values[$name]=round($duration, 6);
        }
        echo json_encode($values, JSON_THROW_ON_ERROR), PHP_EOL;
    ' "$@"
}

emergency_cleanup() {
    local primary_status=$?
    trap - EXIT INT TERM
    set +e

    for node in app-prod app-prod-2; do
        for case_name in independent set-set set-clear; do
            release_router_pause "$node" "$case_name" >/dev/null 2>&1
        done
    done

    if [[ -n "$first_pid" ]]; then wait "$first_pid" >/dev/null 2>&1; fi
    if [[ -n "$second_pid" ]]; then wait "$second_pid" >/dev/null 2>&1; fi

    for node in app-prod app-prod-2; do
        for case_name in independent set-set set-clear; do
            restore_authorization "$node" "$case_name" >/dev/null 2>&1
        done
    done

    for cluster_id in "${cluster_ids[@]}"; do
        orbit cluster:router:clear "$cluster_id" --force --json >/dev/null 2>&1
        orbit cluster:node:detach "$cluster_id" "$app_prod_id" --force --json >/dev/null 2>&1
        orbit cluster:node:detach "$cluster_id" "$app_prod_2_id" --force --json >/dev/null 2>&1
        orbit cluster:remove "$cluster_id" --force --json >/dev/null 2>&1
    done

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

run_independent_case() {
    local first_name=orb172-independent-a
    local second_name=orb172-independent-b
    local first_cluster second_cluster started ready_at second_started second_finished finished

    first_cluster=$(new_cluster "$first_name")
    second_cluster=$(new_cluster "$second_name")
    cluster_ids+=("$first_cluster" "$second_cluster")
    orbit cluster:node:attach "$first_cluster" "$app_prod_id" --json >/dev/null
    orbit cluster:node:attach "$second_cluster" "$app_prod_2_id" --json >/dev/null
    arm_router_pause app-prod independent

    started=$(/usr/bin/date +%s%N)
    start_router_set "$first_cluster" "$app_prod_id" independent-owner
    wait_for_pause app-prod independent
    ready_at=$(/usr/bin/date +%s%N)

    second_started=$(/usr/bin/date +%s%N)
    orbit cluster:router:set "$second_cluster" "$app_prod_2_id" --json > "$working_directory/independent-contender.json"
    second_finished=$(/usr/bin/date +%s%N)
    assert_cluster_response "$working_directory/independent-contender.json" "$second_cluster"
    kill -0 "$first_pid"
    gateway_fixture assert independent-held "$first_name" "$second_name"

    release_router_pause app-prod independent
    wait_first independent-owner "$first_cluster"
    finished=$(/usr/bin/date +%s%N)
    restore_authorization app-prod independent
    gateway_fixture assert independent-final "$first_name" "$second_name"

    emit_durations \
        owner_ready "$((ready_at - started))e-9" \
        independent_progress "$((second_finished - second_started))e-9" \
        owner_total "$((finished - started))e-9"

    cleanup_cluster "$first_cluster" "$app_prod_id"
    cleanup_cluster "$second_cluster" "$app_prod_2_id"
}

run_set_set_case() {
    local name=orb172-set-set
    local cluster_id first_started ready_at second_started finished

    cluster_id=$(new_cluster "$name")
    cluster_ids+=("$cluster_id")
    orbit cluster:node:attach "$cluster_id" "$app_prod_id" --json >/dev/null
    orbit cluster:node:attach "$cluster_id" "$app_prod_2_id" --json >/dev/null
    arm_router_pause app-prod set-set

    first_started=$(/usr/bin/date +%s%N)
    start_router_set "$cluster_id" "$app_prod_id" set-set-owner
    wait_for_pause app-prod set-set
    ready_at=$(/usr/bin/date +%s%N)

    second_started=$(/usr/bin/date +%s%N)
    (
        orbit cluster:router:set "$cluster_id" "$app_prod_2_id" --json
    ) > "$working_directory/set-set-waiter.json" 2> "$working_directory/set-set-waiter.err" &
    second_pid=$!
    sleep 1
    kill -0 "$second_pid"
    gateway_fixture assert set-set-waiting "$name"

    release_router_pause app-prod set-set
    wait_first set-set-owner "$cluster_id"
    wait_second set-set-waiter "$cluster_id"
    finished=$(/usr/bin/date +%s%N)
    restore_authorization app-prod set-set
    gateway_fixture assert set-set-final "$name"

    /usr/bin/php8.5 -r '
        $ready=((int) $argv[2]-(int) $argv[1])/1_000_000_000;
        $waiter=((int) $argv[3]-(int) $argv[2])/1_000_000_000;
        if ($ready <= 0.0 || $ready >= 120.0 || $waiter < 0.9 || $waiter >= 120.0) exit(65);
        echo json_encode(["case"=>"set-set","owner_ready_seconds"=>round($ready,6),"waiter_seconds"=>round($waiter,6)], JSON_THROW_ON_ERROR), PHP_EOL;
    ' "$first_started" "$second_started" "$finished"

    cleanup_cluster "$cluster_id" "$app_prod_id" "$app_prod_2_id"
}

run_set_clear_case() {
    local name=orb172-set-clear
    local cluster_id owner_started ready_at clear_started clear_finished owner_finished clear_status lock_path

    cluster_id=$(new_cluster "$name")
    cluster_ids+=("$cluster_id")
    orbit cluster:node:attach "$cluster_id" "$app_prod_id" --json >/dev/null
    arm_router_pause app-prod set-clear

    owner_started=$(/usr/bin/date +%s%N)
    start_router_set "$cluster_id" "$app_prod_id" set-clear-owner
    wait_for_pause app-prod set-clear
    ready_at=$(/usr/bin/date +%s%N)
    gateway_fixture assert busy-preserved "$name"

    lock_path="$orbit_home/locks/cluster-router/cluster-$cluster_id.lock"
    test "$(stat -c %a -- "$orbit_home/locks/cluster-router")" = 700
    test "$(stat -c %a -- "$lock_path")" = 600

    clear_started=$(/usr/bin/date +%s%N)
    set +e
    orbit cluster:router:clear "$cluster_id" --force --json > "$working_directory/set-clear-busy.json" 2> "$working_directory/set-clear-busy.err"
    clear_status=$?
    set -e
    clear_finished=$(/usr/bin/date +%s%N)
    [[ "$clear_status" -ne 0 ]]
    assert_busy_response "$working_directory/set-clear-busy.json"
    kill -0 "$first_pid"
    gateway_fixture assert busy-preserved "$name"

    release_router_pause app-prod set-clear
    wait_first set-clear-owner "$cluster_id"
    owner_finished=$(/usr/bin/date +%s%N)
    restore_authorization app-prod set-clear
    gateway_fixture assert set-clear-active "$name"

    orbit cluster:router:clear "$cluster_id" --force --json > "$working_directory/set-clear-retry.json"
    assert_cluster_response "$working_directory/set-clear-retry.json" "$cluster_id"
    gateway_fixture assert cleared "$name"

    /usr/bin/php8.5 -r '
        $ready=((int) $argv[2]-(int) $argv[1])/1_000_000_000;
        $busy=((int) $argv[4]-(int) $argv[3])/1_000_000_000;
        $owner=((int) $argv[5]-(int) $argv[1])/1_000_000_000;
        if ($ready <= 0.0 || $ready >= 120.0 || $busy < 28.0 || $busy > 40.0 || $owner <= $busy || $owner >= 240.0) exit(65);
        echo json_encode(["case"=>"set-clear","owner_ready_seconds"=>round($ready,6),"busy_wait_seconds"=>round($busy,6),"owner_total_seconds"=>round($owner,6)], JSON_THROW_ON_ERROR), PHP_EOL;
    ' "$owner_started" "$ready_at" "$clear_started" "$clear_finished" "$owner_finished"

    orbit cluster:node:detach "$cluster_id" "$app_prod_id" --force --json >/dev/null
    orbit cluster:remove "$cluster_id" --force --json >/dev/null
}

case "$scenario" in
    probe)
        probe_surfaces
        printf 'Cluster Router observation surfaces ready\n'
        ;;

    acceptance)
        trap emergency_cleanup EXIT
        trap 'exit 130' INT
        trap 'exit 143' TERM
        working_directory=$(mktemp -d /home/orbit/.orb172-results.XXXXXXXX)
        probe_surfaces >/dev/null
        read -r app_prod_id app_prod_2_id orbit_home < <(
            gateway_fixture probe | /usr/bin/php8.5 -r '
                $v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
                echo $v["nodes"]["app-prod"]["id"], " ", $v["nodes"]["app-prod-2"]["id"], " ", $v["orbit_home"], "\n";
            '
        )

        run_independent_case
        run_set_set_case
        run_set_clear_case

        gateway_fixture restored
        probe_surfaces >/dev/null
        rm -rf -- "$working_directory"
        working_directory=''
        trap - EXIT INT TERM
        printf 'Cluster Router contention and restoration passed\n'
        ;;

    *)
        exit 64
        ;;
esac
