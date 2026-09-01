#!/usr/bin/env bash
set -euo pipefail

fixture_root=${1:?fixture root required}
action=${2:?action required}
deadline=${3:?deadline required}

[[ "$fixture_root" =~ ^/var/lib/orbit-e2e/proof/NCK-(73|104|108|116)$ ]]
[[ "$action" =~ ^[a-z0-9][a-z0-9-]*$ && "$deadline" =~ ^[1-9][0-9]*$ ]]
test -f "$fixture_root/orb-7-signal-driver.sh"
test -f "$fixture_root/$action.sh"

for window in post-record post-mutation; do
  for event in EXIT INT TERM; do
    ORBIT_E2E_PROOF_ROOT="$fixture_root" \
      bash "$fixture_root/orb-7-signal-driver.sh" "$action" "$event" "$window" "$deadline"
  done
done

echo "actual fixture: ${fixture_root##*/}/$action passed normal exit, INT, and TERM cleanup windows"
