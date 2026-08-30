#!/usr/bin/env bash
# Doctor proof fixture (NCK-58). Runs as the orbit user on a checkout role
# (gateway or app-dev) at /home/orbit/orbit. Every subcommand is
# self-checking: it exits non-zero when its expectation does not hold.
set -euo pipefail
cd /
orbit=/home/orbit/orbit/apps/cli/orbit
pool=/etc/php/8.5/fpm/pool.d/orbit-scopes.conf
state=/tmp/orbit-doctor-proof
doctor_exit=0

run_doctor() {
  if json=$("$orbit" doctor --json "$@"); then doctor_exit=0; else doctor_exit=$?; fi
}

assert_report() {
  DOCTOR_JSON="$1" php -- "$2" <<'PHP'
<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, "doctor-proof: {$message}\n"); exit(1); }
$mode = $argv[1] ?? '';
$raw = (string) getenv('DOCTOR_JSON');
$report = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
$families = ['node', 'role', 'app', 'instance', 'workspace', 'tool', 'process', 'firewall'];
$keys = ['code', 'kind', 'resource_type', 'resource_id', 'resource_name', 'summary', 'expected', 'observed'];
if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', (string) ($report['request_id'] ?? '')) !== 1) fail('request_id is not a UUID');
foreach (['/etc/', '/home/', '/var/', '/run/', 'PRIVATE KEY', 'ssh-ed25519', 'AAAA', 'sudo', 'Sorry', 'Permission denied', '10.44.', 'base64', 'Exception'] as $needle) {
    if (str_contains($raw, $needle)) fail("report contains unredacted text [{$needle}]");
}
$nodes = $report['nodes'] ?? [];
if (array_column($nodes, 'node_name') !== ['app-dev', 'app-prod', 'gateway']) fail('nodes are not app-dev, app-prod, gateway in name order');
$issues = [];
foreach ($nodes as $node) {
    if (array_column($node['families'], 'family') !== $families) fail("family order is wrong on {$node['node_name']}");
    foreach ($node['families'] as $family) {
        if (! in_array($family['status'], ['healthy', 'drift', 'unverifiable'], true)) fail('family status is unbounded');
        if (($family['status'] === 'healthy') !== ($family['issues'] === [])) fail('family status disagrees with its issues');
        foreach ($family['issues'] as $issue) {
            if (array_keys($issue) !== $keys) fail('issue keys are not the bounded set');
            foreach (['expected', 'observed'] as $key) {
                if ($issue[$key] !== null && ! is_bool($issue[$key]) && ! is_string($issue[$key])) fail('issue value is not bounded');
                if (is_string($issue[$key]) && strlen($issue[$key]) > 32) fail('issue value is not bounded');
            }
            if (! in_array($issue['kind'], ['drift', 'unverifiable'], true)) fail('issue kind is unbounded');
            $issues[] = ['node' => $node['node_name'], 'family' => $family['family'], 'status' => $family['status']] + $issue;
        }
    }
}
$summary = $report['summary'] ?? [];
if (($summary['nodes'] ?? null) !== 3 || ($summary['families'] ?? null) !== 24) fail('summary does not cover 3 nodes and 24 families');
if ($mode === 'healthy') {
    if ($report['healthy'] !== true || $issues !== [] || $summary['drift'] !== 0 || $summary['unverifiable'] !== 0) fail('report is not healthy');
    foreach ($nodes as $node) { if ($node['healthy'] !== true) fail("node {$node['node_name']} is not healthy"); }
} elseif ($mode === 'issues') {
    if ($report['healthy'] !== false || count($issues) !== 2 || $summary['drift'] !== 1 || $summary['unverifiable'] !== 1) fail('report does not hold exactly one drift and one unverifiable issue');
    [$drift, $unverifiable] = $issues;
    if ($drift['kind'] !== 'drift' || $drift['code'] !== 'instance.php_fpm_projection_mismatch' || $drift['node'] !== 'app-dev' || $drift['family'] !== 'instance' || $drift['status'] !== 'drift' || $drift['resource_type'] !== 'instance' || $drift['resource_name'] !== 'e2e-dev' || $drift['expected'] !== 'matching' || $drift['observed'] !== 'mismatch') fail('drift issue is not the declared app-dev instance projection drift');
    if ($unverifiable['kind'] !== 'unverifiable' || $unverifiable['code'] !== 'role.inspection_failed' || $unverifiable['node'] !== 'app-prod' || $unverifiable['family'] !== 'role' || $unverifiable['status'] !== 'unverifiable' || $unverifiable['resource_type'] !== 'role' || $unverifiable['resource_name'] !== 'app-prod') fail('unverifiable issue is not the declared app-prod role inspection failure');
    foreach ($nodes as $node) { if ($node['healthy'] !== ($node['node_name'] === 'gateway')) fail("node {$node['node_name']} health flag is wrong"); }
} else {
    fail('unknown assertion mode');
}
echo "doctor-proof: report {$report['request_id']} matches [{$mode}]\n";
PHP
}

snapshot() {
  case "$1" in
    gateway)
      # SQLite creates and removes -wal and -shm sidecars for any connection,
      # including a read-only one, so they are not evidence of a Doctor write.
      find /home/orbit/.orbit -xdev \( -type f -o -type d -o -type l \) -printf '%p\n' \
        | grep -v '^/home/orbit/\.orbit/gateway\.sqlite-\(wal\|shm\)$' | sort
      test -f /home/orbit/.orbit/gateway.sqlite
      php -r '$pdo = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite"); foreach ($pdo->query("select name from sqlite_master where type = \"table\" and name not like \"sqlite_%\" order by name")->fetchAll(PDO::FETCH_COLUMN) as $table) { printf("rows %s %d\n", $table, $pdo->query("select count(*) from \"{$table}\"")->fetchColumn()); }'
      for unit in caddy dnsmasq wg-quick@orbit php8.5-fpm ssh; do printf 'unit %s %s\n' "$unit" "$(systemctl is-active "$unit")"; done
      ;;
    app-dev)
      sudo find /home/orbit/.orbit /etc/caddy /etc/php/8.5/fpm/pool.d -xdev \( -type f -o -type l \) -printf '%p %s %T@\n' | sort
      for unit in caddy php8.5-fpm ssh; do printf 'unit %s %s\n' "$unit" "$(systemctl is-active "$unit")"; done
      ip -o -4 addr show | grep -c ' 10\.44\.0\.2/' | sed 's/^/wireguard-address /'
      ;;
    *) exit 64 ;;
  esac
}

case ${1-} in
  expect-healthy)
    [[ $# -eq 1 ]] || exit 64
    run_doctor
    [[ $doctor_exit -eq 0 ]] || { printf 'doctor-proof: orbit doctor exited %d for a healthy report\n' "$doctor_exit" >&2; printf '%s\n' "$json" >&2; exit 1; }
    assert_report "$json" healthy
    printf '%s\n' "$json"
    ;;
  expect-issues)
    [[ $# -eq 1 ]] || exit 64
    run_doctor
    [[ $doctor_exit -eq 1 ]] || { printf 'doctor-proof: orbit doctor exited %d for a report with issues\n' "$doctor_exit" >&2; printf '%s\n' "$json" >&2; exit 1; }
    assert_report "$json" issues
    printf '%s\n' "$json"
    ;;
  expect-api)
    [[ $# -eq 2 && "$2" =~ ^(healthy|issues)$ ]] || exit 64
    php -- "$2" <<'PHP'
<?php
declare(strict_types=1);
require '/home/orbit/orbit/apps/cli/vendor/autoload.php';
function fail(string $message): never { fwrite(STDERR, "doctor-proof: {$message}\n"); exit(1); }
$mode = $argv[1];
$config = json_decode((string) file_get_contents('/home/orbit/.orbit/config.json'), true, 16, JSON_THROW_ON_ERROR);
$profile = $config['gateways'][$config['active_gateway']];
$connector = new Orbit\Sdk\GatewayConnector($profile['url'], $profile['ca_path'], requestIdResolver: static fn (): string => (string) Ramsey\Uuid\Uuid::uuid4());
foreach (['fleet' => new Orbit\Sdk\Requests\Doctor\RunDoctorRequest(), 'app-prod' => new Orbit\Sdk\Requests\Doctor\RunDoctorRequest(3)] as $label => $request) {
    $response = $connector->send($request);
    if ($response->status() !== 200) fail("{$label} report returned HTTP {$response->status()}");
    $report = $response->dto();
    if (! $report instanceof Orbit\Sdk\Responses\Doctor\DoctorReportResponse) fail("{$label} report did not map to DoctorReportResponse");
    if ($report->healthy !== ($mode === 'healthy')) fail("{$label} report health is not [{$mode}]");
    if (preg_match('/\A[0-9a-f-]{36}\z/', $report->requestId) !== 1) fail("{$label} report has no request ID");
    printf("doctor-proof: %s report HTTP %d healthy=%s request_id=%s\n", $label, $response->status(), var_export($report->healthy, true), $report->requestId);
}
PHP
    ;;
  inject-drift)
    [[ $# -eq 1 ]] || exit 64
    sudo sed -i '/^\[orbit-instance-1\]$/,/^$/ s/^pm.max_children = 10$/pm.max_children = 11/' "$pool"
    [[ "$(sudo grep -c '^pm.max_children = 11$' "$pool")" == 1 ]]
    echo 'doctor-proof: instance-1 pool drifted (pm.max_children 10 -> 11)'
    ;;
  restore-drift)
    [[ $# -eq 1 ]] || exit 64
    sudo sed -i '/^\[orbit-instance-1\]$/,/^$/ s/^pm.max_children = 11$/pm.max_children = 10/' "$pool"
    [[ "$(sudo grep -c '^pm.max_children = 11$' "$pool")" == 0 ]]
    echo 'doctor-proof: instance-1 pool restored'
    ;;
  mutation-snapshot)
    [[ $# -eq 2 && "$2" =~ ^(gateway|app-dev)$ ]] || exit 64
    install -d -m 0700 "$state"
    snapshot "$2" > "$state/$2.snapshot"
    printf 'doctor-proof: %s snapshot holds %d lines\n' "$2" "$(wc -l < "$state/$2.snapshot")"
    ;;
  mutation-compare)
    [[ $# -eq 3 && "$2" =~ ^(gateway|app-dev)$ && "$3" =~ ^[0-9]+$ ]] || exit 64
    snapshot "$2" > "$state/$2.current"
    if [[ "$2" == gateway ]]; then
      before=$(grep '^rows activity_log ' "$state/$2.snapshot" | awk '{print $3}')
      after=$(grep '^rows activity_log ' "$state/$2.current" | awk '{print $3}')
      [[ $((after - before)) -eq "$3" ]] || { printf 'doctor-proof: activity_log grew by %d rows, expected %d\n' "$((after - before))" "$3" >&2; exit 1; }
      diff <(grep -v '^rows activity_log ' "$state/$2.snapshot") <(grep -v '^rows activity_log ' "$state/$2.current")
      printf 'doctor-proof: gateway unchanged except %d request-audit activity rows\n' "$3"
    else
      diff "$state/$2.snapshot" "$state/$2.current"
      echo 'doctor-proof: app-dev unchanged'
    fi
    ;;
  exit-codes)
    [[ $# -eq 1 ]] || exit 64
    run_doctor; [[ $doctor_exit -eq 0 ]] || exit 1
    echo 'doctor-proof: healthy report exits 0'
    run_doctor --node=999; [[ $doctor_exit -eq 1 ]] || exit 1
    printf '%s' "$json" | php -r '$error = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR); if (($error["error"]["code"] ?? null) !== "http.404") { exit(1); }'
    echo 'doctor-proof: unknown node exits 1 with the http.404 envelope'
    run_doctor --node=abc; [[ $doctor_exit -eq 1 ]] || exit 1
    printf '%s' "$json" | php -r '$error = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR); if (($error["error"]["code"] ?? null) !== "doctor.node_id_invalid") { exit(1); }'
    echo 'doctor-proof: invalid node ID exits 1 without a request'
    run_doctor --node=2 --family=node; [[ $doctor_exit -eq 0 ]] || exit 1
    printf '%s' "$json" | php -r '$report = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR); if ($report["healthy"] !== true || count($report["nodes"]) !== 1 || array_column($report["nodes"][0]["families"], "family") !== ["node"]) { exit(1); }'
    echo 'doctor-proof: filtered report keeps canonical family order and exits 0'
    set +e; human=$("$orbit" doctor); doctor_exit=$?; set -e
    [[ $doctor_exit -eq 0 ]] && grep -q '^Healthy: yes$' <<<"$human"
    echo 'doctor-proof: human report exits 0'
    ;;
  architecture-coverage)
    [[ $# -eq 1 ]] || exit 64
    cd /home/orbit/orbit/apps/gateway
    php vendor/bin/pest tests/Unit/Architecture/DoctorModelCoverageTest.php --colors=never
    grep -q 'Activity::class' tests/Unit/Architecture/DoctorModelCoverageTest.php
    ! grep -q "'activity'" app/Domain/Doctor/DoctorFamily.php
    echo 'doctor-proof: model partition covers every landed model and excludes Activity'
    ;;
  *) exit 64 ;;
esac
