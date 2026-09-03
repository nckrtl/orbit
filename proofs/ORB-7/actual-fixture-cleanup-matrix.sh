#!/usr/bin/env bash
set -euo pipefail

proof_root=${1:?namespaced proof root required}
shift
[[ "$proof_root" =~ ^/var/lib/orbit-e2e/proof/NCK-[0-9]+$ ]]
[[ -f "$proof_root/orb-7-signal-driver.sh" ]]
[[ "$#" -gt 0 ]]

for specification in "$@"; do
  action=${specification%%:*}
  deadline=${specification##*:}
  [[ "$action" =~ ^[a-z0-9-]+$ && "$deadline" =~ ^[1-9][0-9]*$ ]]
  for window in post-record post-mutation; do
    for event in EXIT INT TERM; do
      env ORBIT_E2E_PROOF_ROOT="$proof_root" \
        bash "$proof_root/orb-7-signal-driver.sh" "$action" "$event" "$window" "$deadline"
    done
  done
done

echo "actual fixture cleanup: EXIT, INT, and TERM restored every named fixture at post-record and post-mutation"
