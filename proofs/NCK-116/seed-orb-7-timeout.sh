#!/usr/bin/env bash
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

baseline_pending="$ORB7_TIMEOUT_BASELINE_RECORD.pending"
seed_cleanup() {
  local status="$1"
  trap - EXIT INT TERM
  if [[ "$status" -ne 0 ]]; then
    orb7_restore_timeout_seed || status=1
    sudo rm -rf -- "$baseline_pending"
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
sudo test ! -e "$baseline_pending" \
  || fail "timeout seed pending baseline already exists"
sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"
sudo install -d -o root -g root -m 0700 -- "$baseline_pending"
orb7_ufw_shapes | sudo tee "$baseline_pending/ufw.before" >/dev/null

numbered=$(orb7_ufw_numbered)
matching=$(grep "# $EXPORTER_RULE_COMMENT\$" <<<"$numbered" || true)
[[ "$(awk 'NF { count++ } END { print count + 0 }' <<<"$matching")" -eq 1 ]] \
  || fail "timeout seed requires one existing exporter rule"
exporter_number=$(sed -E 's/^ *\[ *([0-9]+)\].*/\1/' <<<"$matching")
printf '%s\n' "$exporter_number" \
  | sudo tee "$baseline_pending/exporter.number" >/dev/null
sudo mv -- "$baseline_pending" "$ORB7_TIMEOUT_BASELINE_RECORD"
orb7_arm "$ORB7_TIMEOUT_SEED_ACTION"
sudo /usr/sbin/ufw --force delete "$exporter_number" >/dev/null

address=$(this_address)
orb7_record_ufw_rule "$ORB7_TIMEOUT_SEED_ACTION" ORB7-FOREIGN-KEEP
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 5433 \
  comment ORB7-FOREIGN-KEEP >/dev/null
orb7_record_ufw_rule "$ORB7_TIMEOUT_SEED_ACTION" orbit:metrics-node-exporter-v2
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9101 \
  comment orbit:metrics-node-exporter-v2 >/dev/null
echo "timeout seed: foreign and look-alike rules installed"
