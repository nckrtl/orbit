#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

action=${1:?action required}
signal=${2:?signal required}
deadline=${3:?deadline required}
[[ "$action" == metrics-node-fails-closed && "$signal" =~ ^(INT|TERM)$ && "$deadline" =~ ^[1-9][0-9]*$ ]]
expected=130
[[ "$signal" == TERM ]] && expected=143

setsid --wait env ORBIT_E2E_ORB7_MODE=signal ORBIT_E2E_ORB7_CASE="$action" \
  bash /var/lib/orbit-e2e/proof/metrics-node-fails-closed.sh &
pid=$!
for _ in $(seq 1 300); do
  sudo test ! -f "$ORB7_CLEANUP_ROOT/$action/checkpoint" || break
  kill -0 "$pid" 2>/dev/null || break
  sleep 0.1
done
sudo test -f "$ORB7_CLEANUP_ROOT/$action/checkpoint" || fail "$action did not reach its signal checkpoint"
kill -s "$signal" -- "-$pid"
timeout "$((deadline + 7))s" tail --pid="$pid" -f /dev/null || fail "$action did not exit within its deadline and cleanup grace"
set +e
wait "$pid"
status=$?
set -e
[[ "$status" -eq "$expected" ]] || fail "$action returned $status after $signal, expected $expected"
orb7_restore_services "$action"
sudo test ! -e "$ORB7_CLEANUP_ROOT/$action" || fail "$action cleanup was not idempotent"
echo "$action: $signal restored service state and returned $status"
