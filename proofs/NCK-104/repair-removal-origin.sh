#!/usr/bin/env bash
# Repair the deliberate unsafe-origin fixture on the Gateway before cleanup.
source /var/lib/orbit-e2e/proof/lib.sh

sql_set_workspace_origin nck104-unsafe explicit
[[ "$(sql_workspace_origin nck104-unsafe)" == explicit ]] \
  || fail "unsafe workspace origin was not repaired for cleanup"

echo "removal: deliberate unsafe origin repaired on the Gateway"
