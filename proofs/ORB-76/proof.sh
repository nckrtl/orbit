#!/usr/bin/env bash
set -euo pipefail

criterion=${1:-}
proof_root=$(cd "$(dirname "$0")" && pwd)
fixture=/home/orbit/orb76-fixture
apps_root=/home/orbit/orb76-apps
next_apps_root=/home/orbit/orb76-apps-next
origin=https://127.0.0.1:9443/origin.git

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

node_id() {
  orbit node:list --json | json_find nodes name "$1" id
}

app_id() {
  orbit app:list --json | json_find apps slug "$1" id
}

instance_id() {
  orbit instance:list --json | json_find app_instances name "$1" id
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
    if [[ -f "$fixture/server.pid" ]]; then
      kill "$(cat "$fixture/server.pid")" 2>/dev/null || true
    fi
    rm -rf -- "$fixture"
    install -d -m 0755 -- "$fixture/work"
    git -C "$fixture/work" init --initial-branch=main >/dev/null
    git -C "$fixture/work" config user.name 'Orbit Proof'
    git -C "$fixture/work" config user.email orbit@example.test
    printf 'main\n' > "$fixture/work/README.md"
    git -C "$fixture/work" add README.md
    git -C "$fixture/work" commit -m Main >/dev/null
    git -C "$fixture/work" switch -c existing >/dev/null
    printf 'existing\n' > "$fixture/work/existing.txt"
    git -C "$fixture/work" add existing.txt
    git -C "$fixture/work" commit -m Existing >/dev/null
    git -C "$fixture/work" switch main >/dev/null
    git clone --bare "$fixture/work" "$fixture/origin.git" >/dev/null
    git --git-dir="$fixture/origin.git" symbolic-ref HEAD refs/heads/main
    git --git-dir="$fixture/origin.git" update-server-info
    openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
      -subj '/CN=127.0.0.1' \
      -addext 'subjectAltName=IP:127.0.0.1' \
      -keyout "$fixture/server.key" \
      -out "$fixture/server.crt" >/dev/null 2>&1
    nohup python3 "$proof_root/https-server.py" \
      "$fixture" "$fixture/server.crt" "$fixture/server.key" 9443 \
      >"$fixture/server.log" 2>&1 &
    echo $! > "$fixture/server.pid"
    git config --global http.sslVerify false
    for _ in $(seq 1 30); do
      if curl --silent --show-error --insecure --fail \
        "https://127.0.0.1:9443/origin.git/HEAD" >/dev/null; then
        install -d -m 0755 -- "$apps_root/unrelated"
        printf 'preserve\n' > "$apps_root/unrelated/sentinel"
        echo 'app-dev source fixture is ready'
        exit 0
      fi
      sleep 1
    done
    fail 'app-dev source fixture did not start'
    ;;
  01)
    cluster=$(orbit cluster:new orb76-dev --json)
    cluster_id=$(echo "$cluster" | json_get id)
    app_dev=$(node_id app-dev)
    orbit cluster:node:attach "$cluster_id" "$app_dev" --json >/dev/null
    orbit cluster:router:set "$cluster_id" "$app_dev" --json >/dev/null
    orbit cluster:update "$cluster_id" --state=active --json >/dev/null
    orbit node:settings app-dev --setting="apps.path:$apps_root" --json >/dev/null

    expect_error app.source_defaults_incomplete \
      orbit instance:new 1 "$app_dev" legacy --json
    [[ ! -e "$apps_root/laravel/legacy" ]] || fail 'legacy AppInstance rejection created source'

    app=$(orbit app:new orb76 "$origin" --name='ORB 76' --main-branch=main --root=public --json)
    orbit_app=$(echo "$app" | json_get id)
    [[ "$(echo "$app" | json_get repository_url)" == "$origin" ]] || fail "App origin mismatch: $app"
    [[ "$(echo "$app" | json_get main_branch)" == main ]] || fail "stored main branch mismatch: $app"
    [[ "$(echo "$app" | json_get root)" == public ]] || fail "App root mismatch: $app"

    stored=$(orbit app:show "$orbit_app" --json)
    [[ "$(echo "$stored" | json_get main_branch)" == main ]] || fail "stored main branch changed: $stored"

    existing=$(orbit instance:new "$orbit_app" "$app_dev" existing --json)
    fallback=$(orbit instance:new "$orbit_app" "$app_dev" fallback --root=web --json)
    [[ "$(echo "$existing" | json_get checkout_path)" == "$apps_root/orb76/existing" ]] || fail "existing path mismatch: $existing"
    [[ "$(echo "$existing" | json_get root)" == null ]] || fail "existing root override was not null: $existing"
    [[ "$(echo "$existing" | json_get effective_root)" == public ]] || fail "App root was not inherited: $existing"
    [[ "$(echo "$existing" | json_get starting_commit)" =~ ^[0-9a-f]{40}$ ]] || fail "existing branch commit is invalid: $existing"
    [[ "$(echo "$fallback" | json_get checkout_path)" == "$apps_root/orb76/fallback" ]] || fail "fallback path mismatch: $fallback"
    [[ "$(echo "$fallback" | json_get root)" == web ]] || fail "root override mismatch: $fallback"
    [[ "$(echo "$fallback" | json_get effective_root)" == web ]] || fail "effective root mismatch: $fallback"
    [[ "$(echo "$fallback" | json_get starting_commit)" =~ ^[0-9a-f]{40}$ ]] || fail "fallback commit is invalid: $fallback"

    retry=$(orbit instance:new "$orbit_app" "$app_dev" fallback --root=web --json)
    [[ "$(echo "$retry" | json_get id)" == "$(echo "$fallback" | json_get id)" ]] || fail "retry created a second row: $retry"
    [[ "$(echo "$retry" | json_get starting_commit)" == "$(echo "$fallback" | json_get starting_commit)" ]] || fail "retry changed source evidence: $retry"
    expect_error instance.placement_conflict \
      orbit instance:new "$orbit_app" "$app_dev" fallback --root=changed --json
    [[ "$(orbit instance:list --json | json_get app_instances | php -r '$v=json_decode(stream_get_contents(STDIN), true); echo count($v);')" == 2 ]] || fail 'AppInstance count is not two'

    orbit node:settings app-dev --setting="apps.path:$next_apps_root" --json >/dev/null
    unchanged=$(orbit instance:show "$(echo "$existing" | json_get id)" --json)
    [[ "$(echo "$unchanged" | json_get checkout_path)" == "$apps_root/orb76/existing" ]] || fail "apps-root update moved existing source: $unchanged"
    echo 'criterion 1: source defaults, branch selection, root transport, immutable paths, and retry passed'
    ;;
  02)
    for name in existing fallback; do
      checkout="$apps_root/orb76/$name"
      [[ -d "$checkout/.git" && ! -L "$checkout/.git" ]] || fail "$name is not an independent clone"
      [[ "$(git -C "$checkout" symbolic-ref --short HEAD)" == "$name" ]] || fail "$name branch mismatch"
      [[ "$(git -C "$checkout" rev-parse --show-toplevel)" == "$checkout" ]] || fail "$name top-level mismatch"
      [[ "$(git -C "$checkout" rev-parse --git-common-dir)" == .git ]] || fail "$name shares Git administration"
      [[ "$(git -C "$checkout" remote get-url origin)" == "$origin" ]] || fail "$name origin mismatch"
    done
    [[ "$(git -C "$apps_root/orb76/existing" rev-parse HEAD)" == "$(git --git-dir="$fixture/origin.git" rev-parse refs/heads/existing)" ]] || fail 'existing remote branch commit mismatch'
    [[ "$(git -C "$apps_root/orb76/fallback" rev-parse HEAD)" == "$(git --git-dir="$fixture/origin.git" rev-parse refs/heads/main)" ]] || fail 'fallback main-branch commit mismatch'
    [[ "$(git -C "$apps_root/orb76/existing" rev-parse HEAD)" != "$(git -C "$apps_root/orb76/fallback" rev-parse HEAD)" ]] || fail 'branch-specific commits were not selected'
    git -C "$apps_root/orb76/fallback" remote set-url origin https://127.0.0.1:9443/wrong.git
    echo 'criterion 2: sibling independent clones and exact source evidence passed'
    ;;
  03)
    fallback=$(instance_id fallback)
    expect_error instance.discard_failed orbit instance:remove "$fallback" --discard-source --json
    [[ "$(instance_id fallback)" == "$fallback" ]] || fail 'unsafe discard removed the AppInstance row'
    echo 'criterion 3: destructive intent did not waive repository identity'
    ;;
  04)
    git -C "$apps_root/orb76/fallback" remote set-url origin "$origin"
    printf 'dirty\n' > "$apps_root/orb76/fallback/dirty.txt"
    echo 'criterion 4: dirty removal case is ready'
    ;;
  05)
    orbit_app=$(app_id orb76)
    app_dev=$(node_id app-dev)
    fallback=$(instance_id fallback)
    existing=$(instance_id existing)

    retry=$(orbit instance:new "$orbit_app" "$app_dev" fallback --root=web --json)
    [[ "$(echo "$retry" | json_get id)" == "$fallback" ]] || fail "active retry changed identity: $retry"
    expect_error instance.remove_refused orbit instance:remove "$fallback" --json
    [[ "$(instance_id fallback)" == "$fallback" ]] || fail 'normal dirty refusal removed the row'
    orbit instance:remove "$existing" --json >/dev/null
    orbit instance:remove "$fallback" --discard-source --json >/dev/null
    [[ "$(orbit instance:list --json | json_get app_instances)" == '[]' ]] || fail 'removed AppInstances remain listed'
    echo 'criterion 5: active retry, clean removal, dirty refusal, and explicit discard passed'
    ;;
  06)
    [[ ! -e "$apps_root/orb76/existing" ]] || fail 'clean checkout remains'
    [[ ! -e "$apps_root/orb76/fallback" ]] || fail 'discarded checkout remains'
    [[ -f "$apps_root/unrelated/sentinel" ]] || fail 'unrelated source was removed'
    [[ "$(cat "$apps_root/unrelated/sentinel")" == preserve ]] || fail 'unrelated source changed'
    echo 'criterion 6: only the exact recorded checkouts were removed'
    ;;
  *)
    fail "unknown ORB-76 proof criterion [$criterion]"
    ;;
esac
