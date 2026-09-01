set -euo pipefail

readonly ORB_92_SOCKET_STATE=/tmp/orbit-proof-orb-92-docker-socket
readonly ORB_92_SUDO_STATE=/tmp/orbit-proof-orb-92-sudo

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

json_get() {
    php -r '
        $data = json_decode(stream_get_contents(STDIN), true);
        foreach (explode(".", $argv[1]) as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data)) {
                exit(0);
            }

            $data = $data[$key];
        }

        if (is_bool($data)) {
            echo $data ? "true" : "false";
        } elseif (is_array($data)) {
            echo json_encode($data);
        } elseif ($data !== null) {
            echo (string) $data;
        }
    ' -- "$1"
}

assert_direct_docker_denied() {
    local groups
    groups=" $(id -nG) "
    [[ "$groups" != *' docker '* ]] || fail "orbit still belongs to the Docker group: $groups"
    [[ ! -r /var/run/docker.sock ]] || fail 'orbit can read the Docker socket'

    if docker info >/dev/null 2>&1; then
        fail 'orbit can reach Docker without the privileged boundary'
    fi
}

assert_boundary_unchanged() {
    local expected_socket current_socket expected_sudo current_sudo
    expected_socket=$(<"$ORB_92_SOCKET_STATE")
    current_socket=$(stat -c '%U:%G:%a' /var/run/docker.sock)
    [[ "$current_socket" == "$expected_socket" ]] \
        || fail "Docker socket changed: $expected_socket -> $current_socket"

    expected_sudo=$(<"$ORB_92_SUDO_STATE")
    current_sudo=$(sudo -n -l)
    [[ "$current_sudo" == "$expected_sudo" ]] || fail 'orbit effective sudo policy changed'

    assert_direct_docker_denied
}

assert_metrics_healthy() {
    local status
    status=$(orbit metrics:status --json)
    [[ "$(printf '%s' "$status" | json_get assignment.status)" == active ]] \
        || fail "Metrics assignment is not active: $status"
    [[ "$(printf '%s' "$status" | json_get prometheus)" == healthy ]] \
        || fail "Prometheus is not healthy: $status"
    [[ "$(printf '%s' "$status" | json_get grafana)" == healthy ]] \
        || fail "Grafana is not healthy: $status"
}

assert_expected_runtime() {
    local containers volumes
    containers=$(sudo docker container ls --all \
        --filter label=com.orbit.managed=metrics --format '{{.Names}}' | sort)
    [[ "$containers" == $'orbit-metrics-grafana\norbit-metrics-prometheus' ]] \
        || fail "unexpected Metrics containers: $containers"

    volumes=$(sudo docker volume ls \
        --filter label=com.orbit.managed=metrics --format '{{.Name}}' | sort)
    [[ "$volumes" == $'orbit-metrics-grafana-data\norbit-metrics-prometheus-data' ]] \
        || fail "unexpected Metrics volumes: $volumes"

    local name label state
    for name in orbit-metrics-prometheus orbit-metrics-grafana; do
        label=$(sudo docker container inspect \
            --format '{{index .Config.Labels "com.orbit.managed"}}' -- "$name")
        [[ "$label" == metrics ]] || fail "$name is not Orbit-owned: $label"
        state=$(sudo docker container inspect \
            --format '{{.State.Status}} {{.State.Health.Status}}' -- "$name")
        [[ "$state" == 'running healthy' ]] || fail "$name is not healthy: $state"
    done
}
