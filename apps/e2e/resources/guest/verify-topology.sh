#!/usr/bin/env bash
set -euo pipefail
umask 077

# Mounted source probes carry one extra trailing argument: the expected SHA-256 of
# the worktree `.git` pointer file the host mounted at $source_root.
# Two probes read the whole fleet. `role.assignments` always takes the encoded
# required assignment map, filtered to the nodes the proof declares present.
# `wireguard.reachability` takes those declared nodes except the gateway. Without
# a declaration, both probes receive the complete canonical topology.
[[ $# -ge 4 ]]
case "$1" in
  source.gateway|source.app-dev) [[ $# -eq 4 || $# -eq 5 ]] ;;
  source.manifest) [[ $# -eq 6 || $# -eq 7 ]] ;;
  wireguard.reachability) [[ $# -ge 5 ]] ;;
  role.assignments) [[ $# -eq 5 ]] ;;
  *) [[ $# -eq 4 ]] ;;
esac
probe=$1
mode=$2
identity=$3
instance=$4
expected_pointer=
case "$probe" in
  source.gateway|source.app-dev) [[ $# -eq 4 ]] || expected_pointer=$5 ;;
  source.manifest) [[ $# -eq 6 ]] || expected_pointer=$7 ;;
esac
[[ -z "$expected_pointer" || "$expected_pointer" =~ ^[0-9a-f]{64}$ ]]
[[ "$mode" == readiness || "$mode" == proof ]]
[[ "$identity" =~ ^[0-9a-f]{40}$ ]]
[[ "$instance" =~ ^[a-z0-9][a-z0-9-]{0,62}$ ]]
peer_names=()
required_assignments=
if [[ "$probe" == wireguard.reachability ]]; then
  peer_names=("${@:5}")
  for peer_name in "${peer_names[@]}"; do
    [[ "$peer_name" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,62}$ ]]
  done
  # One name per node: a repeated peer would prove one node twice and the other never.
  [[ "$(printf '%s\n' "${peer_names[@]}" | sort | uniq -d | wc -l)" -eq 0 ]]
fi
if [[ "$probe" == role.assignments ]]; then
  required_assignments=$5
  [[ "$required_assignments" =~ ^[A-Za-z0-9+/]*={0,2}$ ]]
fi
expected=healthy
observed=healthy
repo_git() {
  if [[ "$(id -u)" -eq 0 ]]; then
    sudo -u orbit -- env HOME=/home/orbit "$@"
  else
    "$@"
  fi
}
# A discovery topology mounts the host worktree over the checkout; its `.git` is a
# pointer file guest git cannot follow, so the host records the exact identity here.
# The guest still proves the mount itself: the path must be a mountpoint carrying
# the pointer file and the gateway tree, and the pointer hash must match both the
# marker and the host argument.
source_root=/home/orbit/orbit
source_marker=/var/lib/orbit-e2e/source-state
mounted_source_state() {
  /usr/bin/php -r '$state = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR); if (($state["mounted"] ?? null) !== true) exit(66); $sha = $state["sha"] ?? null; $tree = $state["tree"] ?? null; $pointer = $state["git_pointer_sha256"] ?? null; if (!is_string($sha) || !preg_match("/\\A[0-9a-f]{40}\\z/D", $sha) || !is_string($tree) || !preg_match("/\\A[0-9a-f]{64}\\z/D", $tree) || !is_string($pointer) || !preg_match("/\\A[0-9a-f]{64}\\z/D", $pointer)) exit(65); echo $sha, " ", $tree, " ", $pointer, "\n";' -- "$1"
}
# Sets marker_sha, marker_tree, and observed_pointer; every check fails closed.
assert_mounted_source() {
  [[ -n "$expected_pointer" && -f "$source_marker" ]]
  mountpoint -q -- "$source_root"
  [[ -f "$source_root/.git" && -f "$source_root/apps/gateway/artisan" ]]
  read -r marker_sha marker_tree marker_pointer < <(mounted_source_state "$source_marker")
  observed_pointer=$(sha256sum -- "$source_root/.git" | cut -d ' ' -f 1)
  [[ "$observed_pointer" =~ ^[0-9a-f]{64}$ ]]
  [[ "$marker_pointer" == "$expected_pointer" && "$observed_pointer" == "$expected_pointer" ]]
}

case "$probe" in
  vm.gateway.running|vm.app-dev.running|vm.app-prod.running) state=$(systemctl is-system-running 2>/dev/null) || { [[ "$state" == degraded ]] || exit 1; }; expected='running|degraded'; observed=$state; [[ "$state" == running || "$state" == degraded ]] ;;
  role.gateway) [[ -f /home/orbit/orbit/apps/gateway/artisan && -f /home/orbit/orbit/apps/gateway/.env && -f /home/orbit/.orbit/gateway.app-key && -f /home/orbit/.orbit/gateway.sqlite && "$(stat -c '%U:%a' /home/orbit/.orbit/gateway.sqlite 2>/dev/null)" == orbit:600 && -f /etc/wireguard/orbit.conf && -f /etc/caddy/Caddyfile ]]; expected='gateway,vpn:configured'; observed=$expected ;;
  role.assignments)
    db=/home/orbit/.orbit/gateway.sqlite
    [[ -r "$db" ]]
    # The declared topology must be converged. A proof may add roles (Metrics on
    # app-dev, for example); every extra assignment must be active as well. A
    # node the plan left out of its declaration must not be registered at all,
    # in any status: that is what fails a plan declaring an absence it did not
    # bring about.
    read -r expected extra < <(php -r '$pdo = new PDO("sqlite:".$argv[1]); $required = json_decode(base64_decode($argv[2], true), true, 16, JSON_THROW_ON_ERROR); if (!is_array($required) || array_is_list($required) || $required === []) exit(65); $base=[]; $parts=[]; foreach ($required as $node => $roles) { if (!is_string($node) || !in_array($node, ["gateway", "app-dev", "app-prod"], true) || !is_array($roles) || !array_is_list($roles) || $roles === []) exit(65); $parts[]=$node.":".implode("+", $roles); foreach ($roles as $role) { if (!is_string($role) || $role === "") exit(65); $base[]=$node.":".$role; } } foreach (array_diff(["gateway", "app-dev", "app-prod"], array_keys($required)) as $node) { $statement=$pdo->prepare("SELECT COUNT(*) FROM nodes WHERE name = ?"); $statement->execute([$node]); if ((int)$statement->fetchColumn() !== 0) exit(1); } $rows=$pdo->query("SELECT n.name, n.status AS node_status, r.role, r.status AS role_status FROM nodes n INNER JOIN node_roles r ON r.node_id = n.id ORDER BY n.name, r.role")->fetchAll(PDO::FETCH_ASSOC); $seen=[]; $extra=[]; foreach ($rows as $row) { if ($row["node_status"] !== "active" || $row["role_status"] !== "active") exit(1); $key=$row["name"].":".$row["role"]; if (in_array($key, $base, true)) { $seen[]=$key; continue; } $extra[]=$key; } foreach ($base as $key) { if (!in_array($key, $seen, true)) exit(1); } echo implode(",", $parts), ":active ", implode(",", $extra), "\n";' -- "$db" "$required_assignments")
    observed=$expected
    if [[ -n "$extra" ]]; then
      observed="${expected}+${extra}"
    fi
    ;;
  role.app-dev) [[ -d /home/orbit/apps/laravel && -d /home/orbit/.orbit/worktrees/laravel/e2e ]]; expected='app-dev,workspace:prepared'; observed=$expected ;;
  role.app-prod) [[ -d /var/www/laravel/e2e-prod ]]; expected='app-prod:prepared'; observed=$expected ;;
  service.gateway) caddy_state=$(systemctl is-active caddy 2>/dev/null); php_state=$(systemctl is-active php8.5-fpm 2>/dev/null); expected='caddy=active,php8.5-fpm=active'; observed="caddy=$caddy_state,php8.5-fpm=$php_state"; [[ "$observed" == "$expected" ]] ;;
  service.vpn) vpn_state=$(systemctl is-active wg-quick@orbit 2>/dev/null); expected='wg-quick@orbit=active'; observed="wg-quick@orbit=$vpn_state"; [[ "$observed" == "$expected" ]] ;;
  wireguard.reachability)
    command -v wg >/dev/null
    command -v php >/dev/null
    command -v ssh >/dev/null
    db=/home/orbit/.orbit/gateway.sqlite
    key=/home/orbit/.orbit/ssh/id_ed25519
    known_hosts=/home/orbit/.orbit/ssh/known_hosts
    [[ -r "$db" && -r "$key" && -r "$known_hosts" ]]
    # Every declared route first, then every SSH: one missing route must not
    # reach any node, whatever order the peers were declared in.
    routes=$(wg show orbit allowed-ips)
    peer_addresses=()
    for peer_name in "${peer_names[@]}"; do
      peer_address=$(php -r '$pdo = new PDO("sqlite:".$argv[1]); $statement = $pdo->prepare("SELECT wireguard_address FROM nodes WHERE name = ? AND status = ?"); $statement->execute([$argv[2], "active"]); $address = $statement->fetchColumn(); if (!is_string($address) || !preg_match("/\\A(?:[0-9]{1,3}\\.){3}[0-9]{1,3}\\z/D", $address)) exit(1); echo $address;' -- "$db" "$peer_name")
      printf '%s\n' "$routes" | awk -v expected="$peer_address/32" '{ for (i = 1; i <= NF; i++) if ($i == expected) found = 1 } END { exit !found }'
      peer_addresses+=("$peer_address")
    done
    for peer_address in "${peer_addresses[@]}"; do
      sudo -u orbit -- env HOME=/home/orbit ssh -n -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" -i "$key" "orbit@$peer_address" true
    done
    expected="$(IFS=,; printf '%s' "${peer_names[*]}"):wireguard-route+ssh"
    observed=$expected
    ;;
  https.gateway-internal)
    # One late WireGuard DNS reply right after convergence must not fail the
    # topology: bounded retry, 3 tries of 3 s with 2 s between them.
    dns_tries=0
    resolved=
    while ((dns_tries < 3)); do
      dns_tries=$((dns_tries + 1))
      resolved=$(dig +time=3 +tries=1 +short gateway.orbit @10.44.0.1 2>/dev/null | awk 'NF { print; exit }') || resolved=
      if [[ "$resolved" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
        break
      fi
      resolved=
      if ((dns_tries < 3)); then
        sleep 2
      fi
    done
    [[ "$resolved" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]
    curl --fail --silent --show-error --max-time 5 --cacert /home/orbit/.orbit/e2e-gateway-root-ca.pem --resolve "gateway.orbit:443:$resolved" https://gateway.orbit/up >/dev/null
    expected='https://gateway.orbit/up:vpn-dns+reachable,tries<=3'
    observed="https://gateway.orbit/up:vpn-dns+reachable,tries=$dns_tries"
    ;;
  php-fpm.app-dev|php-fpm.app-prod) php_state=$(systemctl is-active php8.5-fpm 2>/dev/null); expected='php8.5-fpm=active'; observed="php8.5-fpm=$php_state"; [[ "$observed" == "$expected" ]] ;;
  caddy.app-dev|caddy.app-prod) caddy_state=$(systemctl is-active caddy 2>/dev/null); caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; expected='caddy=active,config=valid'; observed="caddy=$caddy_state,config=valid"; [[ "$observed" == "$expected" ]] ;;
  laravel.dev) [[ -f /home/orbit/apps/laravel/artisan ]] && php /home/orbit/apps/laravel/artisan --version >/dev/null; expected='app-dev-laravel:operational'; observed=$expected ;;
  laravel.prod) [[ -f /var/www/laravel/e2e-prod/artisan ]] && php /var/www/laravel/e2e-prod/artisan --version >/dev/null && curl --fail --silent --show-error --retry 10 --retry-delay 2 --retry-connrefused --retry-all-errors --connect-timeout 10 --max-time 30 --cacert "$(cat /var/lib/orbit-e2e/caddy-ca-path)" --resolve laravel.internal:443:127.0.0.1 https://laravel.internal/ >/dev/null; expected='app-prod-laravel:https-operational'; observed=$expected ;;
  workspace.app-dev) [[ -d /home/orbit/.orbit/worktrees/laravel/e2e && -f /home/orbit/.orbit/worktrees/laravel/e2e/artisan ]]; expected='app-dev-workspace:operational'; observed=$expected ;;
  source.gateway|source.app-dev)
    if [[ -n "$expected_pointer" ]]; then
      assert_mounted_source
      expected="$identity:git-pointer=$expected_pointer"
      observed="$marker_sha:git-pointer=$observed_pointer"
    else
      # A transferred checkout must not carry a mounted marker.
      [[ ! -e "$source_marker" ]]
      source_sha=$(repo_git git -C "$source_root" rev-parse HEAD 2>/dev/null)
      expected=$identity; observed=$source_sha
    fi
    [[ "$observed" == "$expected" ]] ;;
  source.manifest)
    repo=$source_root
    expected_tree=$5
    expected_manifest=$6
    [[ "$expected_tree" == - || "$expected_tree" =~ ^[0-9a-f]{64}$ ]]
    [[ "$expected_manifest" =~ ^[A-Za-z0-9+/]*={0,2}$ ]]
    if [[ -n "$expected_pointer" ]]; then
      assert_mounted_source
      [[ "$marker_sha" == "$identity" ]]
      if [[ "$expected_tree" == - ]]; then
        expected_tree=$marker_tree
      else
        [[ "$marker_tree" == "$expected_tree" ]]
      fi
      expected="$identity:$expected_tree:git-pointer=$expected_pointer"
      observed="$marker_sha:$marker_tree:git-pointer=$observed_pointer"
    else
      [[ ! -e "$source_marker" ]]
      [[ -f "$repo/.git/orbit-overlay.paths" && -f "$repo/.git/orbit-source-state" ]]
      printf '%s' "$expected_manifest" | base64 --decode | cmp -s - "$repo/.git/orbit-overlay.paths"
      read -r marker_sha marker_tree < <(/usr/bin/php -r '$state = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR); $sha = $state["sha"] ?? null; $tree = $state["tree"] ?? null; if (!is_string($sha) || !preg_match("/\\A[0-9a-f]{40}\\z/D", $sha) || !is_string($tree) || !preg_match("/\\A[0-9a-f]{64}\\z/D", $tree)) exit(65); echo $sha, " ", $tree, "\n";' -- "$repo/.git/orbit-source-state")
      [[ "$marker_sha" == "$identity" ]]
      index=$(repo_git mktemp)
      trap 'rm -f -- "$index"' EXIT
      rm -f -- "$index"
      repo_git env GIT_INDEX_FILE="$index" git -C "$repo" read-tree HEAD
      repo_git env GIT_INDEX_FILE="$index" git -C "$repo" add -A -- .
      actual_tree=$(repo_git env GIT_INDEX_FILE="$index" git -C "$repo" write-tree)
      actual_tree_hash=$(printf '%s' "$actual_tree" | sha256sum | cut -d ' ' -f 1)
      rm -f -- "$index"
      trap - EXIT
      [[ "$marker_tree" == "$actual_tree_hash" ]]
      if [[ "$expected_tree" == - ]]; then
        [[ -z "$(git -C "$repo" status --porcelain=v1 --untracked-files=all)" ]]
        expected_tree=$actual_tree_hash
      else
        [[ "$actual_tree_hash" == "$expected_tree" ]]
      fi
      expected="$identity:$expected_tree"
      observed="$marker_sha:$actual_tree_hash"
    fi
    ;;
  operator.app-dev) sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite /home/orbit/orbit/apps/cli/orbit gateway:status --json >/dev/null; expected='gateway:status=available'; observed=$expected ;;
  *) exit 64 ;;
esac

checked_at=$(date -u +'%Y-%m-%dT%H:%M:%SZ')
evidence_ref="incus://$instance/$probe"
/usr/bin/php -r 'echo json_encode(["probe" => $argv[1], "passed" => true, "identity" => $argv[2], "checked_at" => $argv[3], "expected" => $argv[4], "observed" => $argv[5], "evidence_ref" => $argv[6]], JSON_THROW_ON_ERROR), "\n";' -- "$probe" "$identity" "$checked_at" "$expected" "$observed" "$evidence_ref"
