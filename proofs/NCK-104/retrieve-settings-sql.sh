#!/usr/bin/env bash
# Provisioned nodes retrieve settings: null as SQL null; typed roots round-trip.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(app_dev_settings)" == null ]] || fail "app-dev JSON settings were not null"
[[ "$(sql_node_settings app-dev)" == null ]] || fail "app-dev SQL settings were not null"
[[ "$(sql_node_settings app-prod)" == null ]] || fail "app-prod SQL settings were not null"
[[ "$(sql_node_settings gateway)" == null ]] || fail "gateway SQL settings were not null"

out=$(orbit node:settings app-dev \
  --setting=instance.path:/srv/orbit/instances \
  --setting=worktree.path:/srv/orbit/worktrees \
  --json)
[[ "$(echo "$out" | json_get settings.instance.path)" == /srv/orbit/instances ]] || fail "instance path missing: $out"
[[ "$(echo "$out" | json_get settings.worktree.path)" == /srv/orbit/worktrees ]] || fail "worktree path missing: $out"
[[ "$(app_dev_settings)" == '{"instance":{"path":"/srv/orbit/instances"},"worktree":{"path":"/srv/orbit/worktrees"}}' ]] \
  || fail "retrieved settings did not match stored overrides"
[[ "$(sql_node_settings app-dev)" == '{"instance":{"path":"/srv/orbit/instances"},"worktree":{"path":"/srv/orbit/worktrees"}}' ]] \
  || fail "SQL did not store typed settings JSON"
echo "retrieve: JSON and SQL store typed roots; other nodes stay SQL null"
