#!/usr/bin/env bash
# A node whose WireGuard interface has lost its address is squarely inside the
# case this escape exists for. The destination address is then the one field
# that cannot be checked either way, so the escape proves every other field,
# says so, and still cleans up.
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

readonly DECOY_RULE=orbit:metrics-grafana-upstream

orb7_traps escape-without-wireguard-address
orb7_arm escape-without-wireguard-address
orb7_capture_addresses escape-without-wireguard-address
orb7_checkpoint escape-without-wireguard-address post-record
addresses=$(sudo ip -4 -o addr show dev orbit)
saved=$(awk 'NR == 1 {print $4}' <<<"$addresses")
[[ -n "$saved" ]] || fail "the orbit interface has no IPv4 address to remove"
address="${saved%%/*}"

# A genuine exporter rule, and a rule that keeps an Orbit comment but carries
# the wrong port. With the address gone the first must still be removed and
# the second must still be refused.
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9100 \
  comment "$EXPORTER_RULE_COMMENT" >/dev/null
orb7_record_ufw_delta escape-without-wireguard-address exporter
orb7_mark_active escape-without-wireguard-address
orb7_checkpoint escape-without-wireguard-address post-mutation
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 3001 \
  comment "$DECOY_RULE" >/dev/null
orb7_record_ufw_delta escape-without-wireguard-address decoy

sudo ip addr flush dev orbit
[[ -z "$(sudo ip -4 -o addr show dev orbit)" ]] || fail "the orbit interface still has an IPv4 address"

run_escape --force
escape_status="$ESCAPE_STATUS"
escape_output="$ESCAPE_OUTPUT"
orb7_restore_addresses escape-without-wireguard-address

ESCAPE_STATUS="$escape_status"
ESCAPE_OUTPUT="$escape_output"

# Exit 3, because the wrong-port rule was refused.
[[ "$ESCAPE_STATUS" -eq 3 ]] || fail "escape exited $ESCAPE_STATUS, expected 3: $ESCAPE_OUTPUT"

# The downgrade is named before the operator confirms, not only afterwards.
assert_reports 'Proved with less evidence than usual:'
assert_reports 'the orbit interface has no IPv4 address, so the destination address of the UFW rules below could not be verified'
assert_reports "(destination address not verified)"
[[ "${ESCAPE_OUTPUT%%Will remove:*}" == *'Proved with less evidence than usual:'* ]] \
  || fail "the downgrade was not reported before the list the operator approves"

# The genuine rule went, on everything except the destination address.
! firewall_rule_exists "$EXPORTER_RULE_COMMENT" || fail "the genuine exporter rule survived"
assert_reports "UFW rule commented $EXPORTER_RULE_COMMENT"

# The wrong-port rule stayed, so the reduced proof is still a proof.
firewall_rule_exists "$DECOY_RULE" || fail "the escape removed a rule whose port does not match Orbit's"
assert_reports 'are not the rule Orbit writes'

restored_addresses=$(sudo ip -4 -o addr show dev orbit)
grep -q "$address" <<<"$restored_addresses" || fail "the orbit address was not restored"
orb7_restore_owned escape-without-wireguard-address
trap - EXIT INT TERM

echo "escape-without-wireguard-address: genuine rule removed, wrong-port rule refused, downgrade reported"
