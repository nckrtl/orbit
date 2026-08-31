#!/usr/bin/env bash
# PATCH omits preserve stored members; nested null restores the derived default.
source /var/lib/orbit-e2e/proof/lib.sh

out=$(orbit node:settings app-dev --setting=instance.path:/mnt/orbit-apps --json)
[[ "$(echo "$out" | json_get settings.instance.path)" == /mnt/orbit-apps ]] || fail "omit did not patch instance: $out"
[[ "$(echo "$out" | json_get settings.worktree.path)" == /srv/orbit/worktrees ]] || fail "omit overwrote worktree: $out"

out=$(orbit node:settings app-dev --setting=instance.path: --json)
[[ "$(echo "$out" | json_get settings.instance)" == null ]] || fail "empty value did not clear instance: $out"
[[ "$(echo "$out" | json_get settings.worktree.path)" == /srv/orbit/worktrees ]] || fail "clearing instance dropped worktree: $out"
[[ "$(sql_node_settings app-dev)" == '{"worktree":{"path":"/srv/orbit/worktrees"}}' ]] \
  || fail "SQL did not keep remaining worktree override"

out=$(orbit node:settings app-dev --setting=worktree.path: --json)
[[ "$(echo "$out" | json_get settings)" == null ]] || fail "clearing last override did not return settings null: $out"
[[ "$(sql_node_settings app-dev)" == null ]] || fail "clearing last override did not store SQL null"

restore_default_roots
echo "patch: omit preserves, null unsets, last unset collapses to SQL null"
