#!/usr/bin/env bash
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

committed=0
seed_cleanup() {
  local status="$1"
  trap - EXIT INT TERM
  if [[ "$committed" -eq 0 ]]; then
    orb7_restore_timeout_seed || status=1
    sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"
  fi
  exit "$status"
}
trap 'seed_cleanup "$?"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

sudo test ! -e "$ORB7_CLEANUP_ROOT/$ORB7_TIMEOUT_SEED_ACTION" \
  || fail "timeout seed cleanup record already exists"
sudo test ! -e "$ORB7_TIMEOUT_BASELINE_RECORD" \
  || fail "timeout seed baseline record already exists"
sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"
sudo install -d -o root -g root -m 0700 -- "$ORB7_TIMEOUT_BASELINE_RECORD"
orb7_ufw_shapes | sudo tee "$ORB7_TIMEOUT_BASELINE_RECORD/ufw.before" >/dev/null
orb7_arm "$ORB7_TIMEOUT_SEED_ACTION"

numbered=$(orb7_ufw_numbered)
matching=$(grep "# $EXPORTER_RULE_COMMENT\$" <<<"$numbered" || true)
[[ "$(awk 'NF { count++ } END { print count + 0 }' <<<"$matching")" -eq 1 ]] \
  || fail "timeout seed requires one existing exporter rule"
exporter_number=$(sed -E 's/^ *\[ *([0-9]+)\].*/\1/' <<<"$matching")
printf '%s\n' "$exporter_number" \
  | sudo tee "$ORB7_TIMEOUT_BASELINE_RECORD/exporter.number" >/dev/null
sudo /usr/sbin/ufw --force delete "$exporter_number" >/dev/null

address=$(this_address)
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 5433 \
  comment ORB7-FOREIGN-KEEP >/dev/null
orb7_record_ufw_delta "$ORB7_TIMEOUT_SEED_ACTION" foreign
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9101 \
  comment orbit:metrics-node-exporter-v2 >/dev/null
orb7_record_ufw_delta "$ORB7_TIMEOUT_SEED_ACTION" look-alike
committed=1
echo "timeout seed: foreign and look-alike rules installed"
