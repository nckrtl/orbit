#!/usr/bin/env bash
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

action=${1:?action required}
event=${2:?event required}
window=${3:?window required}
deadline=${4:?deadline required}
[[ "$action" =~ ^(escape-exporter-node|escape-metrics-node|escape-without-wireguard-address|refuses-a-shifted-rule-number|refuses-without-proof)$ ]]
[[ "$event" =~ ^(EXIT|INT|TERM)$ && "$window" =~ ^(post-record|post-mutation)$ \
  && "$deadline" =~ ^[1-9][0-9]*$ ]]
expected=130
[[ "$event" == EXIT ]] && expected=0
[[ "$event" == TERM ]] && expected=143
checkpoint="/var/tmp/orbit-e2e-orb7-${action}-${window}-${event}.ready"
snapshot=$(mktemp -d)

driver_cleanup() {
  sudo rm -f -- "$checkpoint" "$checkpoint.continue"
  rm -rf -- "$snapshot"
}
trap driver_cleanup EXIT INT TERM
sudo test ! -e "$checkpoint" || fail "checkpoint already exists: $checkpoint"

env ORBIT_E2E_PROOF_ROOT="$proof_root" ORBIT_E2E_ORB7_MODE=signal \
  ORBIT_E2E_ORB7_CASE="$action" ORBIT_E2E_ORB7_EVENT="$event" ORBIT_E2E_ORB7_WINDOW="$window" \
  ORBIT_E2E_ORB7_CHECKPOINT="$checkpoint" python3 - "$proof_root/$action.sh" <<'PY' &
import os
import signal
import sys

os.setsid()
signal.signal(signal.SIGINT, signal.SIG_DFL)
signal.signal(signal.SIGTERM, signal.SIG_DFL)
os.execv('/usr/bin/bash', ['bash', sys.argv[1]])
PY
pid=$!
for _ in $(seq 1 300); do
  sudo test ! -f "$checkpoint" || break
  kill -0 "$pid" 2>/dev/null || break
  sleep 0.1
done
sudo test -f "$checkpoint" || fail "$action did not reach its $window checkpoint"
record="$ORB7_CLEANUP_ROOT/$action"
sudo test -f "$record/state" || fail "$action did not publish its cleanup record"
sudo cp -a -- "$record/." "$snapshot/"
sudo chown -R orbit:orbit -- "$snapshot"

assert_path_baseline() {
  local label path existed
  [[ -f "$snapshot/paths.tsv" ]] || return 0
  while IFS=$'\t' read -r label path existed; do
    if [[ "$existed" -eq 1 ]]; then
      sudo tar --acls --xattrs --numeric-owner -C / --compare -pf "$snapshot/paths/$label.tar" >/dev/null
    else
      sudo test ! -e "$path" && sudo test ! -L "$path"
    fi
  done <"$snapshot/paths.tsv"
}

assert_rule_baseline() {
  [[ "$(orb7_ufw_shapes | sort)" == "$(sort <"$snapshot/ufw.before")" ]] \
    || fail "$action did not restore the exact UFW baseline"
}

assert_owned_rules_present() {
  local comment
  [[ -s "$snapshot/rules.tsv" ]] || fail "$action did not record an owned UFW mutation"
  while IFS= read -r comment; do
    firewall_rule_exists "$comment" || fail "$action did not retain its recorded UFW mutation"
  done <"$snapshot/rules.tsv"
}

if [[ "$window" == post-record ]]; then
  [[ "$(sudo cat "$record/state")" == armed ]] || fail "$action was not armed at the post-record checkpoint"
  assert_rule_baseline
  assert_path_baseline
else
  [[ "$(sudo cat "$record/state")" == active ]] || fail "$action did not mark its owned mutation active"
  case "$action" in
    escape-exporter-node)
      assert_owned_rules_present
      ;;
    escape-metrics-node)
      container_exists orbit-metrics-prometheus-decoy || fail "$action did not create its decoy container"
      volume_exists orbit-metrics-prometheus-backup || fail "$action did not create its decoy volume"
      assert_owned_rules_present
      ;;
    escape-without-wireguard-address)
      assert_owned_rules_present
      [[ -z "$(sudo ip -4 -o addr show dev orbit)" ]] || fail "$action did not remove its WireGuard address"
      ;;
    refuses-a-shifted-rule-number)
      sudo test -x /usr/local/sbin/ufw || fail "$action did not install its ufw stub"
      sudo test -s /var/tmp/orbit-proof-ufw-calls || fail "$action did not install its stub state"
      assert_owned_rules_present
      ;;
    refuses-without-proof)
      sudo test -f /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf \
        || fail "$action did not install its foreign drop-in"
      sudo test -f /etc/orbit/metrics/.orbit-owner || fail "$action did not install its foreign marker"
      assert_owned_rules_present
      ;;
  esac
fi
if [[ "$event" == EXIT ]]; then
  sudo touch -- "$checkpoint.continue"
else
  kill -s "$event" -- "-$pid"
fi
timeout "$((deadline + 7))s" tail --pid="$pid" -f /dev/null || fail "$action did not exit within its deadline and cleanup grace"
set +e
wait "$pid"
status=$?
set -e
[[ "$status" -eq "$expected" ]] || fail "$action returned $status after $event, expected $expected"
assert_rule_baseline
assert_path_baseline
if [[ -f "$snapshot/addresses.before" ]]; then
  [[ "$(sudo ip -4 -o addr show dev orbit)" == "$(cat "$snapshot/addresses.before")" ]] \
    || fail "$action did not restore the exact WireGuard addresses"
fi
if [[ -f "$snapshot/docker.tsv" ]]; then
  while IFS=$'\t' read -r kind name _; do
    if [[ "$kind" == container ]]; then
      ! container_exists "$name" || fail "$action left its owned container"
    else
      ! volume_exists "$name" || fail "$action left its owned volume"
    fi
  done <"$snapshot/docker.tsv"
fi
orb7_restore_owned "$action"
sudo test ! -e "$ORB7_CLEANUP_ROOT/$action" || fail "$action cleanup was not idempotent"
echo "$action: $event at $window restored its exact owned delta and returned $status"
