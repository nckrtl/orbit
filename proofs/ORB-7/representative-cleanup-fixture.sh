#!/usr/bin/env bash
set -euo pipefail

mode=${1:-run}
record=/var/lib/orbit-e2e/proof-cleanup/orb-7-representative
target=/var/tmp/orbit-e2e-orb-7-representative-state
stub=/usr/local/sbin/orbit-e2e-orb-7-representative-stub

[[ "$mode" =~ ^(run|cleanup)$ ]]

restore_owned() {
  sudo test -e "$record" || return 0
  sudo mkdir "$record/restoring" 2>/dev/null || return 0
  sudo rm -f -- "$stub"
  sudo cp --archive -- "$record/target.before" "$target"
  sudo rm -rf -- "$record"
}

cleanup() {
  local status="$1"
  trap - EXIT INT TERM
  restore_owned
  exit "$status"
}

if [[ "$mode" == cleanup ]]; then
  restore_owned
  exit 0
fi

checkpoint() {
  local window="$1"
  if [[ "${ORBIT_E2E_ORB7_WINDOW:-}" == "$window" ]]; then
    local checkpoint=${ORBIT_E2E_ORB7_CHECKPOINT:?}
    printf 'ready\n' | sudo tee "$checkpoint" >/dev/null
    if [[ "${ORBIT_E2E_ORB7_EVENT:-}" == EXIT ]]; then
      until sudo test -f "$checkpoint.continue"; do sleep 0.1; done
      exit 0
    fi
    while true; do sleep 1; done
  fi
}

trap 'cleanup "$?"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

sudo test ! -e "$record"
sudo test ! -e "$stub"
sudo test -f "$target"
sudo install -d -o root -g root -m 0700 -- "$record"
sudo cp --archive -- "$target" "$record/target.before"
checkpoint post-record

printf '#!/usr/bin/env bash\nexit 0\n' | sudo install -o root -g root -m 0755 /dev/stdin "$stub"
printf 'mutated\n' | sudo tee "$target" >/dev/null
checkpoint post-mutation
