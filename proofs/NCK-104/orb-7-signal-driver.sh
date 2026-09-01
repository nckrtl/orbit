#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

action=${1:?action required}
signal=${2:?signal required}
deadline=${3:?deadline required}
[[ "$action" =~ ^(prepare-roots|retrieve-settings-sql|patch-omit-null|cli-setting-parse|derived-explicit-origin|non-migrating-app-prod|root-ownership|checkout-overlap|caddy-acl-sharing|removal-recorded-origin|repair-removal-origin|removal-restoration|restore-legacy-origin)$ ]]
[[ "$signal" =~ ^(INT|TERM)$ && "$deadline" =~ ^[1-9][0-9]*$ ]]
expected=130
[[ "$signal" == TERM ]] && expected=143

remotes=()
case "$action" in
  retrieve-settings-sql) remotes=(app-dev app-prod) ;;
  patch-omit-null|cli-setting-parse|derived-explicit-origin|non-migrating-app-prod|restore-legacy-origin)
    remotes=(app-dev)
    ;;
  root-ownership|checkout-overlap|caddy-acl-sharing|removal-recorded-origin|removal-restoration)
    remotes=(gateway)
    ;;
esac

env ORBIT_E2E_ORB7_MODE=signal ORBIT_E2E_ORB7_CASE="$action" \
  python3 - "/var/lib/orbit-e2e/proof/$action.sh" <<'PY' &
import os
import signal
import sys

os.setsid()
signal.signal(signal.SIGINT, signal.SIG_DFL)
signal.signal(signal.SIGTERM, signal.SIG_DFL)
os.execv('/usr/bin/bash', ['bash', sys.argv[1]])
PY
pid=$!
for _ in $(seq 1 600); do
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
orb7_restore_action "$action" "${remotes[@]}"
if [[ "$action" == prepare-roots ]]; then
  bash "$ORB7_STATE_HELPER" restore nck104-original-paths
fi
if [[ "$action" == retrieve-settings-sql ]]; then
  bash "$ORB7_STATE_HELPER" restore nck104-original-database
fi
sudo test ! -e "$ORB7_CLEANUP_ROOT/$action" || fail "$action cleanup was not idempotent"
echo "$action: $signal restored local and cross-node state and returned $status"
