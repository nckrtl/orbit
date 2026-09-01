#!/usr/bin/env bash
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

seed_cleanup() {
  delete_firewall_rule ORB7-FOREIGN-KEEP
  delete_firewall_rule orbit:metrics-node-exporter-v2
  sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"
}
trap seed_cleanup EXIT INT TERM
seed_cleanup
address=$(this_address)
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 5433 \
  comment ORB7-FOREIGN-KEEP >/dev/null
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9101 \
  comment orbit:metrics-node-exporter-v2 >/dev/null
trap - EXIT INT TERM
echo "timeout seed: foreign and look-alike rules installed"
