#!/usr/bin/env bash
set -euo pipefail

readonly proof_root=/var/lib/orbit-e2e/proof/NCK-104
export ORBIT_E2E_PROOF_ROOT="$proof_root"
source "$proof_root/lib.sh"

readonly matrix_root=/var/tmp/orbit-e2e-orb7-nck104

remote_exec() {
  local role="$1"
  shift
  local address
  address=$(orb7_node_address "$role")
  "${ORB7_SSH[@]}" -- "orbit@$address" "$@"
}

database_has_mutation() {
  php -r '
    $database = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
    $count = $database->query(
      "SELECT count(*) FROM sqlite_master WHERE type = \"table\" AND name = \"orb7_nck104_cleanup_marker\"",
    )->fetchColumn();
    exit((int) $count === 1 ? 0 : 1);
  '
}

if [[ "${1:-}" == child ]]; then
  action=${2:?action required}
  family=${3:?family required}
  target=${4:?target required}
  checkpoint=${ORBIT_E2E_ORB7_CHECKPOINT:?}
  [[ "$family" =~ ^(local-path|local-database|remote-path|remote-database|custom-hook)$ ]]

  restore_hook_state() {
    printf 'baseline\n' | sudo tee "$target.hook" >/dev/null
  }

  case "$family" in
    local-path)
      orb7_traps "$action"
      orb7_arm_paths "$action" "$target"
      ;;
    local-database)
      orb7_traps "$action"
      orb7_arm_database "$action"
      ;;
    remote-path)
      orb7_traps "$action" app-dev
      orb7_arm_remote_paths app-dev "$action" "$target"
      ;;
    remote-database)
      orb7_traps "$action" gateway
      orb7_arm_remote_database "$action"
      ;;
    custom-hook)
      orb7_traps "$action"
      orb7_set_cleanup_hook restore_hook_state
      orb7_arm_paths "$action" "$target"
      ;;
  esac
  orb7_checkpoint "$action" post-record

  case "$family" in
    local-path|custom-hook)
      printf 'mutated\n' | sudo tee "$target" >/dev/null
      [[ "$family" != custom-hook ]] || printf 'mutated\n' | sudo tee "$target.hook" >/dev/null
      ;;
    local-database|remote-database)
      php -r '$d=new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite"); $d->exec("CREATE TABLE orb7_nck104_cleanup_marker (id INTEGER)");'
      ;;
    remote-path)
      printf 'mutated\n' | remote_exec app-dev sudo tee "$target" >/dev/null
      ;;
  esac
  if [[ "$family" == remote-path ]]; then
    orb7_remote_state app-dev mark "$action" active
  elif [[ "$family" == remote-database ]]; then
    orb7_remote_state gateway mark "$action" active
  else
    orb7_mark_active "$action"
  fi
  orb7_checkpoint "$action" post-mutation
  exit 0
fi

sudo install -d -o orbit -g orbit -m 0700 -- "$matrix_root"
active_pid=

matrix_cleanup() {
  local status=$?
  trap - EXIT INT TERM
  if [[ -n "$active_pid" ]] && kill -0 "$active_pid" 2>/dev/null; then
    kill -TERM -- "-$active_pid" 2>/dev/null || true
    for _ in $(seq 1 40); do
      kill -0 "$active_pid" 2>/dev/null || break
      sleep 0.1
    done
    kill -KILL -- "-$active_pid" 2>/dev/null || true
    wait "$active_pid" 2>/dev/null || true
  fi
  sudo rm -rf -- "$matrix_root" || true
  remote_exec app-dev sudo rm -rf -- "$matrix_root" || true
  exit "$status"
}
trap matrix_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

run_case() {
  local family="$1"
  local window="$2"
  local event="$3"
  local action="orb7-nck104-${family}-${window}-${event,,}"
  local target="$matrix_root/$action"
  local checkpoint="$target.ready"
  local expected=130
  [[ "$event" == EXIT ]] && expected=0
  [[ "$event" == TERM ]] && expected=143

  if [[ "$family" == remote-path ]]; then
    remote_exec app-dev sudo install -D -o orbit -g orbit -m 0600 /dev/null "$target"
    printf 'baseline\n' | remote_exec app-dev sudo tee "$target" >/dev/null
  elif [[ "$family" != local-database && "$family" != remote-database ]]; then
    printf 'baseline\n' | sudo tee "$target" >/dev/null
    [[ "$family" != custom-hook ]] || printf 'baseline\n' | sudo tee "$target.hook" >/dev/null
  fi

  env ORBIT_E2E_ORB7_MODE=signal ORBIT_E2E_ORB7_CASE="$action" \
    ORBIT_E2E_ORB7_EVENT="$event" ORBIT_E2E_ORB7_WINDOW="$window" \
    ORBIT_E2E_ORB7_CHECKPOINT="$checkpoint" python3 - "$0" child "$action" "$family" "$target" <<'PY' &
import os
import signal
import sys

os.setsid()
signal.signal(signal.SIGINT, signal.SIG_DFL)
signal.signal(signal.SIGTERM, signal.SIG_DFL)
os.execv('/usr/bin/bash', ['bash', *sys.argv[1:]])
PY
  pid=$!
  active_pid=$pid
  for _ in $(seq 1 600); do
    sudo test ! -f "$checkpoint" || break
    kill -0 "$pid" 2>/dev/null || break
    sleep 0.1
  done
  sudo test -f "$checkpoint" || fail "$family did not reach $window for $event"

  if [[ "$family" == remote-path ]]; then
    state=$(remote_exec app-dev sudo cat "$ORB7_CLEANUP_ROOT/$action/state")
  elif [[ "$family" == remote-database ]]; then
    state=$(remote_exec gateway sudo cat "$ORB7_CLEANUP_ROOT/$action/state")
  else
    state=$(sudo cat "$ORB7_CLEANUP_ROOT/$action/state")
  fi

  if [[ "$window" == post-record ]]; then
    [[ "$state" == armed ]] || fail "$family was not armed at its post-record checkpoint"
    case "$family" in
      local-path|custom-hook)
        [[ "$(sudo cat "$target")" == baseline ]] || fail "$family changed its path before mutation"
        ;;
      local-database|remote-database)
        ! database_has_mutation || fail "$family changed its database before mutation"
        ;;
      remote-path)
        [[ "$(remote_exec app-dev sudo cat "$target")" == baseline ]] \
          || fail "$family changed its remote path before mutation"
        ;;
    esac
  else
    [[ "$state" == active ]] || fail "$family was not active at its post-mutation checkpoint"
    case "$family" in
      local-path)
        [[ "$(sudo cat "$target")" == mutated ]] || fail "$family did not create its path mutation"
        ;;
      custom-hook)
        [[ "$(sudo cat "$target")" == mutated && "$(sudo cat "$target.hook")" == mutated ]] \
          || fail "$family did not create its custom-hook mutation"
        ;;
      local-database|remote-database)
        database_has_mutation || fail "$family did not create its database mutation"
        ;;
      remote-path)
        [[ "$(remote_exec app-dev sudo cat "$target")" == mutated ]] \
          || fail "$family did not create its remote path mutation"
        ;;
    esac
  fi

  if [[ "$event" == EXIT ]]; then
    sudo touch -- "$checkpoint.continue"
  else
    kill -s "$event" -- "-$pid"
  fi
  timeout 127s tail --pid="$pid" -f /dev/null || fail "$family did not finish cleanup"
  set +e
  wait "$pid"
  status=$?
  set -e
  active_pid=
  [[ "$status" -eq "$expected" ]] || fail "$family returned $status after $event, expected $expected"

  case "$family" in
    local-path)
      [[ "$(sudo cat "$target")" == baseline ]]
      orb7_restore_action "$action"
      ;;
    local-database)
      ! database_has_mutation || fail "$family left its database mutation"
      orb7_restore_action "$action"
      ;;
    remote-path)
      [[ "$(remote_exec app-dev sudo cat "$target")" == baseline ]]
      orb7_restore_action "$action" app-dev
      ;;
    remote-database)
      ! database_has_mutation || fail "$family left its database mutation"
      orb7_restore_action "$action" gateway
      ;;
    custom-hook)
      [[ "$(sudo cat "$target")" == baseline ]]
      [[ "$(sudo cat "$target.hook")" == baseline ]]
      orb7_restore_action "$action"
      ;;
  esac
  sudo test ! -e "$ORB7_CLEANUP_ROOT/$action" || fail "$family left its local cleanup record"
  sudo rm -f -- "$checkpoint" "$checkpoint.continue" "$target" "$target.hook"
  [[ "$family" != remote-path ]] || remote_exec app-dev sudo rm -f -- "$target"
}

[[ $# -eq 1 ]] || fail "cleanup family required"
family_filter="$1"
for family in "$family_filter"; do
  [[ "$family" =~ ^(local-path|local-database|remote-path|remote-database|custom-hook)$ ]] \
    || fail "unknown cleanup family: $family"
  for window in post-record post-mutation; do
    for event in EXIT INT TERM; do
      run_case "$family" "$window" "$event"
    done
  done
done

echo "NCK-104 helper cleanup proved for prepare-roots, retrieve-settings-sql, patch-omit-null, cli-setting-parse, derived-explicit-origin, non-migrating-app-prod, root-ownership, checkout-overlap, caddy-acl-sharing, removal-recorded-origin, repair-removal-origin, removal-restoration, and restore-legacy-origin"
