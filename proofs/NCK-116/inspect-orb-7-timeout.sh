#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

cleanup_witness() {
  sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"
}
trap cleanup_witness EXIT
sudo test -f "$ORB7_TIMEOUT_WITNESS" || fail "the timed-out fixture never published its installed-state witness"
[[ "$(sudo cat "$ORB7_TIMEOUT_WITNESS")" == installed ]] \
  || fail "the timed-out fixture published an invalid installed-state witness"
sudo test ! -e /usr/local/sbin/ufw || fail "the timed-out fixture left its fake ufw binary"
sudo test ! -e /var/tmp/orbit-proof-ufw-calls || fail "the timed-out fixture left its call log"
sudo test ! -e "$ORB7_CLEANUP_ROOT/refuses-a-shifted-rule-number" || fail "the timed-out fixture left its ownership record"
! firewall_rule_exists transient-maintenance-rule || fail "the timed-out fixture left its transient rule"
! firewall_rule_exists "$EXPORTER_RULE_COMMENT" || fail "the timed-out fixture left its owned exporter rule"
! firewall_rule_exists PRODUCTION-DB-ACCESS || fail "the timed-out fixture left its planted foreign rule"
firewall_rule_exists ORB7-FOREIGN-KEEP || fail "cleanup removed a foreign rule"
firewall_rule_exists orbit:metrics-node-exporter-v2 || fail "cleanup removed an exporter look-alike"
echo "timeout inspector: installed-state witnessed; stub, log, and owned rules absent; foreign and look-alike rules remain"
