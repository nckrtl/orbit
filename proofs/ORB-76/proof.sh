#!/usr/bin/env bash
set -euo pipefail

criterion=${1:-}
proof_root=$(cd "$(dirname "$0")" && pwd)
fixture=/home/orbit/orb76-fixture
apps_root=/home/orbit/orb76-apps
next_apps_root=/home/orbit/orb76-apps-next
app_dev_address=10.44.0.2
origin="https://${app_dev_address}:9443/origin.git"
baseline_apps=/tmp/orb76-baseline-apps.json
baseline_clusters=/tmp/orb76-baseline-clusters.json
baseline_instances=/tmp/orb76-baseline-app-instances.json
baseline_nodes=/tmp/orb76-baseline-nodes.json
baseline_apps_settings=/tmp/orb76-baseline-apps-settings.json
baseline_apps_path=/tmp/orb76-baseline-apps-path

fail() { echo "FAIL: $*" >&2; exit 1; }

json_get() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $key) {
      if (! is_array($data) || ! array_key_exists($key, $data)) { exit(1); }
      $data = $data[$key];
    }
    if (is_bool($data)) { echo $data ? "true" : "false"; }
    elseif (is_array($data) || $data === null) { echo json_encode($data, JSON_UNESCAPED_SLASHES); }
    else { echo (string) $data; }
  ' -- "$1"
}

json_find() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    $items = $data[$argv[1]] ?? [];
    foreach (is_array($items) ? $items : [] as $item) {
      if (is_array($item) && ($item[$argv[2]] ?? null) === $argv[3]) {
        $value = $item[$argv[4]] ?? null;
        if (is_bool($value)) { echo $value ? "true" : "false"; }
        elseif (is_array($value) || $value === null) { echo json_encode($value, JSON_UNESCAPED_SLASHES); }
        else { echo (string) $value; }
        exit(0);
      }
    }
    exit(1);
  ' -- "$1" "$2" "$3" "$4"
}

json_lacks() {
  php -r '$data=json_decode(stream_get_contents(STDIN), true); exit(is_array($data) && ! array_key_exists($argv[1], $data) ? 0 : 1);' -- "$1"
}

app_instance_count() {
  php -r '
    $data=json_decode(stream_get_contents(STDIN), true);
    $count=0;
    foreach ($data["app_instances"] ?? [] as $instance) {
      if (is_array($instance) && (string) ($instance["app_id"] ?? "") === $argv[1]) { $count++; }
    }
    echo $count;
  ' -- "$1"
}

instance_signature() {
  php -r '
    $data=json_decode(stream_get_contents(STDIN), true);
    $keys=["app_id","node_id","source_kind","checkout_path","root","selected_branch","starting_commit","status"];
    $result=[];
    foreach ($keys as $key) { $result[$key]=$data[$key] ?? null; }
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
  '
}

node_id() { orbit node:list --json | json_find nodes name "$1" id; }
app_id() { orbit app:list --json | json_find apps slug "$1" id; }
instance_id() {
  local orbit_app
  orbit_app=$(app_id orb76)
  orbit instance:list --json | php -r '
    $data=json_decode(stream_get_contents(STDIN), true);
    foreach ($data["app_instances"] ?? [] as $instance) {
      if (is_array($instance)
        && ($instance["name"] ?? null) === $argv[1]
        && (string) ($instance["app_id"] ?? "") === $argv[2]) {
        echo $instance["id"];
        exit(0);
      }
    }
    exit(1);
  ' -- "$1" "$orbit_app"
}

assert_instance() {
  local payload=$1 name=$2 expected_path=$3 expected_root=$4 expected_effective_root=$5
  [[ "$(echo "$payload" | json_get name)" == "$name" ]] || fail "$name identity mismatch: $payload"
  [[ "$(echo "$payload" | json_get source_kind)" == managed_clone ]] || fail "$name source kind mismatch: $payload"
  [[ "$(echo "$payload" | json_get checkout_path)" == "$expected_path" ]] || fail "$name path mismatch: $payload"
  [[ "$(echo "$payload" | json_get root)" == "$expected_root" ]] || fail "$name root mismatch: $payload"
  [[ "$(echo "$payload" | json_get effective_root)" == "$expected_effective_root" ]] || fail "$name effective root mismatch: $payload"
  [[ "$(echo "$payload" | json_get selected_branch)" == "$name" ]] || fail "$name branch mismatch: $payload"
  [[ "$(echo "$payload" | json_get starting_commit)" =~ ^[0-9a-f]{40}$ ]] || fail "$name starting commit is invalid: $payload"
  [[ "$(echo "$payload" | json_get status)" == active ]] || fail "$name is not active: $payload"
  echo "$payload" | json_lacks cluster_id || fail "$name exposed stored Cluster identity: $payload"
}

expect_error() {
  local code=$1
  shift
  local out status
  set +e
  out=$("$@" 2>&1)
  status=$?
  set -e
  [[ "$status" -eq 1 ]] || fail "expected exit 1 for $code, got $status: $out"
  [[ "$(echo "$out" | json_get error.code)" == "$code" ]] || fail "expected $code: $out"
}

case "$criterion" in
  setup-app-dev)
    if [[ -f "$fixture/server.pid" ]]; then kill "$(cat "$fixture/server.pid")" 2>/dev/null || true; fi
    rm -rf -- "$fixture" "$apps_root" "$next_apps_root"
    install -d -m 0755 -- "$fixture/work"
    git -C "$fixture/work" init --initial-branch=main >/dev/null
    git -C "$fixture/work" config user.name 'Orbit Proof'
    git -C "$fixture/work" config user.email orbit@example.test
    printf 'main\n' > "$fixture/work/README.md"
    git -C "$fixture/work" add README.md
    git -C "$fixture/work" commit -m Main >/dev/null
    git -C "$fixture/work" switch -c e2e-dev >/dev/null
    printf 'existing\n' > "$fixture/work/existing.txt"
    git -C "$fixture/work" add existing.txt
    git -C "$fixture/work" commit -m Existing >/dev/null
    git -C "$fixture/work" switch main >/dev/null
    git clone --bare "$fixture/work" "$fixture/origin.git" >/dev/null
    git --git-dir="$fixture/origin.git" symbolic-ref HEAD refs/heads/main
    git --git-dir="$fixture/origin.git" update-server-info
    openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
      -subj "/CN=${app_dev_address}" -addext "subjectAltName=IP:${app_dev_address}" \
      -keyout "$fixture/server.key" -out "$fixture/server.crt" >/dev/null 2>&1
    sudo install -m 0644 "$fixture/server.crt" /usr/local/share/ca-certificates/orb76-source.crt
    sudo update-ca-certificates >/dev/null
    nohup python3 "$proof_root/https-server.py" "$fixture" "$fixture/server.crt" "$fixture/server.key" 9443 \
      >"$fixture/server.log" 2>&1 &
    echo $! > "$fixture/server.pid"
    for _ in $(seq 1 30); do
      if curl --silent --show-error --insecure --fail "$origin/HEAD" >/dev/null; then
        install -d -m 0755 -- "$apps_root/unrelated"
        printf 'preserve\n' > "$apps_root/unrelated/sentinel"
        echo 'app-dev source fixture is ready'
        exit 0
      fi
      sleep 1
    done
    fail 'app-dev source fixture did not start'
    ;;
  setup-gateway)
    rm -f -- "$baseline_apps" "$baseline_clusters" "$baseline_instances" "$baseline_nodes" \
      "$baseline_apps_settings" "$baseline_apps_path"
    app_dev=$(node_id app-dev)
    orbit firewall:allow orb76-source --node="$app_dev" --from=10.44.0.1 --protocol=tcp --port=9443 --json >/dev/null
    curl --silent --show-error --insecure --fail "https://${app_dev_address}:9443/server.crt" -o /tmp/orb76-source.crt
    sudo install -m 0644 /tmp/orb76-source.crt /usr/local/share/ca-certificates/orb76-source.crt
    sudo update-ca-certificates >/dev/null
    echo 'gateway trusts the source fixture'
    ;;
  01)
    app_dev=$(node_id app-dev)
    for _ in $(seq 1 30); do curl --silent --show-error --insecure --fail "$origin/HEAD" >/dev/null && break; sleep 1; done
    curl --silent --show-error --insecure --fail "$origin/HEAD" >/dev/null
    orbit app:list --json | json_get apps > "$baseline_apps"
    orbit cluster:list --json | json_get clusters > "$baseline_clusters"
    orbit instance:list --json | json_get app_instances > "$baseline_instances"
    orbit node:list --json | json_get nodes > "$baseline_nodes"
    orbit node:list --json | json_find nodes name app-dev settings > "$baseline_apps_settings"
    if [[ "$(cat "$baseline_apps_settings")" != null ]]; then
      cat "$baseline_apps_settings" | json_get apps.path > "$baseline_apps_path"
      [[ "$(cat "$baseline_apps_path")" == /* ]] || fail 'baseline apps path is invalid'
    fi

    orbit node:settings app-dev --setting="apps.path:$apps_root" --json >/dev/null
    legacy_app=$(app_id laravel)
    expect_error app.source_defaults_incomplete orbit instance:new "$legacy_app" "$app_dev" legacy --json
    [[ ! -e "$apps_root/laravel/legacy" ]] || fail 'legacy AppInstance rejection created source'

    app=$(orbit app:new orb76 "$origin" --name='ORB 76' --main-branch=main --root=public --json)
    orbit_app=$(echo "$app" | json_get id)
    [[ "$(echo "$app" | json_get repository_url)" == "$origin" ]] || fail "App origin mismatch: $app"
    [[ "$(echo "$app" | json_get main_branch)" == main ]] || fail "stored main branch mismatch: $app"
    [[ "$(echo "$app" | json_get root)" == public ]] || fail "App root mismatch: $app"

    standalone=$(orbit instance:new "$orbit_app" "$app_dev" e2e-dev --json)
    assert_instance "$standalone" e2e-dev "$apps_root/orb76/e2e-dev" null public

    baseline_cluster_id=$(orbit node:list --json | json_find nodes name app-dev cluster_id)
    [[ "$(orbit cluster:show "$baseline_cluster_id" --json | json_get name)" == e2e-development ]] || fail 'unexpected baseline Cluster'
    orbit cluster:router:clear "$baseline_cluster_id" --force --json >/dev/null
    orbit cluster:node:detach "$baseline_cluster_id" "$app_dev" --force --json >/dev/null

    cluster=$(orbit cluster:new orb76-dev --json)
    cluster_id=$(echo "$cluster" | json_get id)
    orbit cluster:node:attach "$cluster_id" "$app_dev" --json >/dev/null
    inactive=$(orbit instance:new "$orbit_app" "$app_dev" inactive-member --json)
    assert_instance "$inactive" inactive-member "$apps_root/orb76/inactive-member" null public

    orbit cluster:update "$cluster_id" --state=active --json >/dev/null
    tldless=$(orbit instance:new "$orbit_app" "$app_dev" tldless-member --json)
    assert_instance "$tldless" tldless-member "$apps_root/orb76/tldless-member" null public

    orbit cluster:router:set "$cluster_id" "$app_dev" --json >/dev/null
    orbit cluster:update "$cluster_id" --tld=beast --json >/dev/null
    tld=$(orbit instance:new "$orbit_app" "$app_dev" tld-member --root=web --json)
    assert_instance "$tld" tld-member "$apps_root/orb76/tld-member" web web
    before=$(echo "$tld" | instance_signature)

    orbit cluster:update "$cluster_id" --state=inactive --json >/dev/null
    orbit cluster:router:clear "$cluster_id" --force --json >/dev/null
    orbit cluster:node:detach "$cluster_id" "$app_dev" --force --json >/dev/null
    after=$(orbit instance:show "$(echo "$tld" | json_get id)" --json)
    [[ "$(echo "$after" | instance_signature)" == "$before" ]] || fail 'Cluster changes altered AppInstance source identity'
    orbit cluster:remove "$cluster_id" --force --json >/dev/null
    orbit cluster:node:attach "$baseline_cluster_id" "$app_dev" --json >/dev/null
    orbit cluster:router:set "$baseline_cluster_id" "$app_dev" --json >/dev/null
    baseline_cluster=$(orbit cluster:show "$baseline_cluster_id" --json)
    [[ "$(echo "$baseline_cluster" | json_get state)" == active ]] || fail 'baseline Cluster state changed'
    [[ "$(echo "$baseline_cluster" | json_get tld)" == null ]] || fail 'baseline Cluster TLD changed'
    [[ "$(echo "$baseline_cluster" | json_get router.id)" == "$app_dev" ]] || fail 'baseline Cluster Router was not restored'
    [[ "$(orbit cluster:list --json | json_get clusters)" == "$(cat "$baseline_clusters")" ]] || fail 'ORB-76 changed baseline Clusters'

    retry=$(orbit instance:new "$orbit_app" "$app_dev" tld-member --root=web --json)
    [[ "$(echo "$retry" | json_get id)" == "$(echo "$tld" | json_get id)" ]] || fail 'retry created a second AppInstance'
    expect_error instance.placement_conflict orbit instance:new "$orbit_app" "$app_dev" tld-member --root=changed --json

    orbit node:settings app-dev --setting="apps.path:$next_apps_root" --json >/dev/null
    later=$(orbit instance:new "$orbit_app" "$app_dev" later --json)
    assert_instance "$later" later "$next_apps_root/orb76/later" null public
    unchanged=$(orbit instance:show "$(echo "$standalone" | json_get id)" --json)
    [[ "$(echo "$unchanged" | json_get checkout_path)" == "$apps_root/orb76/e2e-dev" ]] || fail 'apps-root update moved existing source'
    [[ "$(orbit instance:list --json | app_instance_count "$orbit_app")" == 5 ]] || fail 'ORB-76 AppInstance count is not five'
    echo 'criterion 1: standalone and optional-Cluster managed-clone creation passed'
    ;;
  02)
    for name in e2e-dev inactive-member tldless-member tld-member; do
      checkout="$apps_root/orb76/$name"
      [[ -d "$checkout/.git" && ! -L "$checkout/.git" ]] || fail "$name is not an independent clone"
      [[ "$(git -C "$checkout" symbolic-ref --short HEAD)" == "$name" ]] || fail "$name branch mismatch"
      [[ "$(git -C "$checkout" rev-parse --show-toplevel)" == "$checkout" ]] || fail "$name top-level mismatch"
      [[ "$(git -C "$checkout" rev-parse --git-common-dir)" == .git ]] || fail "$name shares Git administration"
      [[ "$(git -C "$checkout" remote get-url origin)" == "$origin" ]] || fail "$name origin mismatch"
    done
    later="$next_apps_root/orb76/later"
    [[ -d "$later/.git" && "$(git -C "$later" rev-parse --git-common-dir)" == .git ]] || fail 'later is not an independent clone'
    [[ "$(git -C "$apps_root/orb76/e2e-dev" rev-parse HEAD)" == "$(git --git-dir="$fixture/origin.git" rev-parse refs/heads/e2e-dev)" ]] || fail 'existing remote branch commit mismatch'
    [[ "$(git -C "$apps_root/orb76/inactive-member" rev-parse HEAD)" == "$(git --git-dir="$fixture/origin.git" rev-parse refs/heads/main)" ]] || fail 'fallback main-branch commit mismatch'
    git -C "$apps_root/orb76/inactive-member" remote set-url origin "https://${app_dev_address}:9443/wrong.git"
    echo 'criterion 2: independent clone and exact fetched source evidence passed'
    ;;
  03)
    unsafe=$(instance_id inactive-member)
    expect_error instance.discard_failed orbit instance:remove "$unsafe" --discard-source --json
    [[ "$(instance_id inactive-member)" == "$unsafe" ]] || fail 'unsafe discard removed the AppInstance row'
    echo 'criterion 3: discard did not waive repository identity'
    ;;
  04)
    git -C "$apps_root/orb76/inactive-member" remote set-url origin "$origin"
    printf 'dirty\n' > "$apps_root/orb76/inactive-member/dirty.txt"
    git -C "$apps_root/orb76/tld-member" config user.name 'Orbit Proof'
    git -C "$apps_root/orb76/tld-member" config user.email orbit@example.test
    printf 'development\n' > "$apps_root/orb76/tld-member/development.txt"
    git -C "$apps_root/orb76/tld-member" add development.txt
    git -C "$apps_root/orb76/tld-member" commit -m Development >/dev/null
    git -C "$apps_root/orb76/tld-member" rev-parse HEAD > "$fixture/advanced-head"
    echo 'criterion 4: dirty and advanced-HEAD removal cases are ready'
    ;;
  05)
    orbit_app=$(app_id orb76)
    app_dev=$(node_id app-dev)
    advanced=$(instance_id tld-member)
    starting=$(orbit instance:show "$advanced" --json | json_get starting_commit)
    retry=$(orbit instance:new "$orbit_app" "$app_dev" tld-member --root=web --json)
    [[ "$(echo "$retry" | json_get id)" == "$advanced" ]] || fail 'active retry changed identity'
    [[ "$(echo "$retry" | json_get starting_commit)" == "$starting" ]] || fail 'active retry rewrote historical starting commit'

    dirty=$(instance_id inactive-member)
    expect_error instance.remove_refused orbit instance:remove "$dirty" --json
    expect_error instance.remove_refused orbit instance:remove "$advanced" --json
    echo 'criterion 5: active retry and non-discard removal refusal passed'
    ;;
  05-source-preserved)
    current=$(git -C "$apps_root/orb76/tld-member" rev-parse HEAD)
    expected=$(cat "$fixture/advanced-head")
    [[ "$current" == "$expected" ]] || fail 'active retry rewrote development HEAD'
    [[ -f "$apps_root/orb76/inactive-member/dirty.txt" ]] || fail 'active retry changed another checkout'
    echo 'criterion 5 source evidence: active retry preserved development HEAD'
    ;;
  05-remove)
    app_dev=$(node_id app-dev)
    dirty=$(instance_id inactive-member)
    advanced=$(instance_id tld-member)
    orbit instance:remove "$(instance_id e2e-dev)" --json >/dev/null
    orbit instance:remove "$(instance_id tldless-member)" --json >/dev/null
    orbit instance:remove "$(instance_id later)" --json >/dev/null
    orbit instance:remove "$dirty" --discard-source --json >/dev/null
    orbit instance:remove "$advanced" --discard-source --json >/dev/null
    orbit firewall:remove orb76-source --node="$app_dev" --json >/dev/null
    if [[ "$(cat "$baseline_apps_settings")" == null ]]; then
      orbit node:settings app-dev --setting='apps.path:' --json >/dev/null
    else
      orbit node:settings app-dev --setting="apps.path:$(cat "$baseline_apps_path")" --json >/dev/null
    fi
    orbit app:remove "$(app_id orb76)" --json >/dev/null
    sudo rm -f -- /usr/local/share/ca-certificates/orb76-source.crt /tmp/orb76-source.crt
    sudo update-ca-certificates >/dev/null
    [[ -f "$baseline_apps" && -f "$baseline_instances" && -f "$baseline_nodes" && -f "$baseline_apps_settings" ]] \
      || fail 'baseline Gateway snapshot is missing'
    [[ "$(orbit app:list --json | json_get apps)" == "$(cat "$baseline_apps")" ]] || fail 'ORB-76 changed baseline Apps'
    [[ "$(orbit instance:list --json | json_get app_instances)" == "$(cat "$baseline_instances")" ]] || fail 'ORB-76 removal changed baseline AppInstances or left owned AppInstances listed'
    [[ "$(orbit node:list --json | json_get nodes)" == "$(cat "$baseline_nodes")" ]] || fail 'ORB-76 changed baseline Nodes'
    rm -f -- "$baseline_apps" "$baseline_clusters" "$baseline_instances" "$baseline_nodes" \
      "$baseline_apps_settings" "$baseline_apps_path"
    echo 'criterion 5 removal: safe clean, dirty, and unpublished removal passed'
    ;;
  06)
    for path in "$apps_root/orb76/e2e-dev" "$apps_root/orb76/inactive-member" \
      "$apps_root/orb76/tldless-member" "$apps_root/orb76/tld-member" "$next_apps_root/orb76/later"; do
    [[ ! -e "$path" ]] || fail "removed checkout remains: $path"
    done
    [[ -f "$apps_root/unrelated/sentinel" ]] || fail 'unrelated source was removed'
    [[ "$(cat "$apps_root/unrelated/sentinel")" == preserve ]] || fail 'unrelated source changed'
    if [[ -f "$fixture/server.pid" ]]; then kill "$(cat "$fixture/server.pid")" 2>/dev/null || true; fi
    sudo rm -f -- /usr/local/share/ca-certificates/orb76-source.crt
    sudo update-ca-certificates >/dev/null
    rm -rf -- "$fixture" "$apps_root" "$next_apps_root"
    echo 'criterion 6: only exact recorded checkouts were removed'
    ;;
  *) fail "unknown ORB-76 proof criterion [$criterion]" ;;
esac
