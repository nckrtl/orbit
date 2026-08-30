#!/usr/bin/env bash
# A UFW rule number is a position, not an identity. If anything below Orbit's
# rule goes away between the plan and the delete, the planned number addresses
# somebody else's rule. The escape must notice and delete nothing.
source /var/lib/orbit-e2e/proof/lib.sh

readonly TRANSIENT_RULE=transient-maintenance-rule
readonly FOREIGN_RULE=PRODUCTION-DB-ACCESS
readonly STUB=/usr/local/sbin/ufw
readonly STUB_STATE=/var/tmp/orbit-proof-ufw-calls

address=$(this_address)

cleanup() {
  sudo rm -f "$STUB" "$STUB_STATE"
  delete_firewall_rule "$FOREIGN_RULE"
  delete_firewall_rule "$TRANSIENT_RULE"
  delete_firewall_rule "$EXPORTER_RULE_COMMENT"
}
trap cleanup EXIT

rule_number() {
  grep "# $1\$" <<<"$(firewall_status_text)" | sed -E 's/^ *\[ *([0-9]+)\].*/\1/' | head -1 || true
}

# Orbit's rule must sit directly above the foreign one, so that the number
# Orbit plans addresses the foreign rule once the rule above them both goes.
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 5432 \
  comment "$FOREIGN_RULE" >/dev/null
foreign_number=$(rule_number "$FOREIGN_RULE")
[[ -n "$foreign_number" ]] || fail "the planted foreign rule has no number"

sudo ufw insert "$foreign_number" allow in on orbit proto tcp from 10.44.0.1 to "$address" \
  port 9100 comment "$EXPORTER_RULE_COMMENT" >/dev/null
sudo ufw insert 1 allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9999 \
  comment "$TRANSIENT_RULE" >/dev/null

[[ "$(rule_number "$FOREIGN_RULE")" -eq "$(( $(rule_number "$EXPORTER_RULE_COMMENT") + 1 ))" ]] \
  || fail "the foreign rule is not directly below Orbit's; the shift would prove nothing"

# Interposes deterministically where a race would be milliseconds wide under
# --force and unbounded at the interactive prompt: the plan reads the real
# numbering, and the rule above Orbit's is dropped straight afterwards, so
# every later read sees the shifted numbering.
sudo tee "$STUB" >/dev/null <<'STUBEOF'
#!/bin/bash
readonly REAL=/usr/sbin/ufw
readonly STATE=/var/tmp/orbit-proof-ufw-calls

if [ "$1" = 'status' ]; then
    calls=$(cat "$STATE" 2>/dev/null || echo 0)
    "$REAL" "$@"
    status=$?
    echo $((calls + 1)) >"$STATE"

    if [ "$calls" -eq 0 ]; then
        "$REAL" --force delete 1 >/dev/null 2>&1
    fi

    exit "$status"
fi

exec "$REAL" "$@"
STUBEOF
sudo chmod 0755 "$STUB"
echo 0 | sudo tee "$STUB_STATE" >/dev/null

planned_number=$(sudo /usr/sbin/ufw status numbered 2>/dev/null \
  | grep "# $EXPORTER_RULE_COMMENT\$" | sed -E 's/^ *\[ *([0-9]+)\].*/\1/' | head -1 || true)
[[ -n "$planned_number" ]] || fail "could not read the number the escape will plan"


run_escape --force
sudo rm -f "$STUB" "$STUB_STATE"

[[ "$ESCAPE_STATUS" -eq 3 ]] || fail "escape exited $ESCAPE_STATUS, expected 3: $ESCAPE_OUTPUT"

# Nothing was deleted, and the report says why in the operator's terms.
assert_reports "the rules changed between the plan and the removal, so UFW rule [$planned_number] no longer addresses the rule commented $EXPORTER_RULE_COMMENT"

firewall_rule_exists "$FOREIGN_RULE" \
  || fail "a foreign rule was destroyed by a stale rule number"
firewall_rule_exists "$EXPORTER_RULE_COMMENT" \
  || fail "Orbit's rule was removed even though its number no longer addressed it"
[[ "$ESCAPE_OUTPUT" != *"Removed:"*"UFW rule commented $EXPORTER_RULE_COMMENT"* ]] \
  || fail "the escape reported a firewall removal that did not happen"

echo "refuses-a-shifted-rule-number: stale number refused, $FOREIGN_RULE and Orbit's rule both intact"
