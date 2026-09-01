#!/usr/bin/env bash
# Nodes provisioned without settings stay SQL null; provision with settings round-trips raw overrides.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(app_dev_settings)" == null ]] || fail "app-dev JSON settings were not null"
[[ "$(sql_node_settings app-dev)" == null ]] || fail "app-dev SQL settings were not null"
[[ "$(sql_node_settings app-prod)" == null ]] || fail "app-prod SQL settings were not null"
[[ "$(sql_node_settings gateway)" == null ]] || fail "gateway SQL settings were not null"

orb7_arm_database nck104-original-database
orb7_arm_database retrieve-settings-sql
orb7_arm_remote_paths app-dev retrieve-settings-sql /srv/orbit
orb7_arm_remote_paths app-prod retrieve-settings-sql /var/www/laravel/e2e-prod
orb7_traps retrieve-settings-sql app-dev app-prod
provision_app_prod --json >/dev/null || fail "provision without settings failed"
orb7_mark_active retrieve-settings-sql app-dev app-prod
orb7_checkpoint retrieve-settings-sql
[[ "$(sql_node_settings app-prod)" == null ]] || fail "provision without settings stored overrides"

out=$(provision_app_dev \
  --setting=instance.path:/srv/orbit/instances \
  --setting=worktree.path:/srv/orbit/worktrees \
  --json) || fail "provision with settings failed: $out"
[[ "$(echo "$out" | json_get settings.instance.path)" == /srv/orbit/instances ]] || fail "provision did not return instance path: $out"
[[ "$(echo "$out" | json_get settings.worktree.path)" == /srv/orbit/worktrees ]] || fail "provision did not return worktree path: $out"
[[ "$(app_dev_settings)" == '{"instance":{"path":"/srv/orbit/instances"},"worktree":{"path":"/srv/orbit/worktrees"}}' ]] \
  || fail "retrieved settings did not match stored overrides"
[[ "$(sql_node_settings app-dev)" == '{"instance":{"path":"/srv/orbit/instances"},"worktree":{"path":"/srv/orbit/worktrees"}}' ]] \
  || fail "SQL did not store typed settings JSON"
orb7_complete retrieve-settings-sql app-dev app-prod
echo "provision/retrieve: without settings is SQL null; with settings stores typed roots"
