#!/usr/bin/env bash
# Repair the deliberate unsafe-origin fixture on the Gateway before cleanup.
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

orb7_traps repair-removal-origin
orb7_arm_database repair-removal-origin
orb7_checkpoint repair-removal-origin post-record
sql_set_workspace_origin nck104-unsafe explicit
orb7_mark_active repair-removal-origin
orb7_checkpoint repair-removal-origin post-mutation
[[ "$(sql_workspace_origin nck104-unsafe)" == explicit ]] \
  || fail "unsafe workspace origin was not repaired for cleanup"

orb7_complete repair-removal-origin
echo "removal: deliberate unsafe origin repaired on the Gateway"
