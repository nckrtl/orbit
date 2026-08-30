#!/usr/bin/env bash
# Nodes provisioned without settings stay SQL null; provision with settings round-trips raw overrides.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(app_dev_settings)" == null ]] || fail "app-dev JSON settings were not null"
[[ "$(sql_node_settings app-dev)" == null ]] || fail "app-dev SQL settings were not null"
[[ "$(sql_node_settings app-prod)" == null ]] || fail "app-prod SQL settings were not null"
[[ "$(sql_node_settings gateway)" == null ]] || fail "gateway SQL settings were not null"

out=$(orbit node:provision app-dev "$(node_field app-dev public_ssh_host)" \
  --user=orbit \
  --host-key-fingerprint="$(node_field app-dev ssh_host_fingerprint)" \
  --architecture="$(node_field app-dev architecture)" \
  --tld="$(node_field app-dev tld)" \
  --role=app-dev \
  --setting=instance.path:/srv/orbit/instances \
  --setting=worktree.path:/srv/orbit/worktrees \
  --json) || fail "provision with settings failed: $out"
[[ "$(echo "$out" | json_get settings.instance.path)" == /srv/orbit/instances ]] || fail "provision did not return instance path: $out"
[[ "$(echo "$out" | json_get settings.worktree.path)" == /srv/orbit/worktrees ]] || fail "provision did not return worktree path: $out"
[[ "$(app_dev_settings)" == '{"instance":{"path":"/srv/orbit/instances"},"worktree":{"path":"/srv/orbit/worktrees"}}' ]] \
  || fail "retrieved settings did not match stored overrides"
[[ "$(sql_node_settings app-dev)" == '{"instance":{"path":"/srv/orbit/instances"},"worktree":{"path":"/srv/orbit/worktrees"}}' ]] \
  || fail "SQL did not store typed settings JSON"
echo "provision/retrieve: without settings is SQL null; with settings stores typed roots"
