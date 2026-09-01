#!/usr/bin/env bash
set -euo pipefail

mode=${1:?mode required}
record=/var/lib/orbit-e2e/proof-cleanup/orb-7-owned-fixture
target=/var/tmp/orbit-e2e-orb-7-reusable-state
stub=/usr/local/sbin/orbit-e2e-orb-7-stub

[[ "$mode" =~ ^(normal|INT|TERM)$ ]]
sudo test ! -e "$record"
sudo test ! -e "$stub"
sudo install -d -o root -g root -m 0700 -- "$record"
sudo cp --archive -- "$target" "$record/target.before"

cleanup() {
  local status="$1"
  trap - EXIT INT TERM
  sudo rm -f -- "$stub"
  sudo cp --archive -- "$record/target.before" "$target"
  sudo rm -rf -- "$record"
  exit "$status"
}

trap 'cleanup "$?"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

printf '#!/usr/bin/env bash\nexit 0\n' | sudo tee "$stub" >/dev/null
sudo chmod 0755 "$stub"
printf 'mutated\n' | sudo tee "$target" >/dev/null
printf 'ready\n' | sudo tee "$record/ready" >/dev/null

[[ "$mode" == normal ]] && exit 0
while true; do sleep 1; done
