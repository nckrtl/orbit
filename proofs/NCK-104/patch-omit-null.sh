#!/usr/bin/env bash
# PATCH omits preserve stored members; nested null restores the derived default.
source /var/lib/orbit-e2e/proof/lib.sh

orb7_arm_database patch-omit-null
orb7_arm_remote_paths app-dev patch-omit-null /mnt/orbit-apps /srv/orbit
orb7_traps patch-omit-null app-dev
out=$(orbit node:settings app-dev --setting=instance.path:/mnt/orbit-apps --json)
orb7_mark_active patch-omit-null app-dev
orb7_checkpoint patch-omit-null
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
orb7_complete patch-omit-null app-dev
echo "patch: omit preserves, null unsets, last unset collapses to SQL null"
