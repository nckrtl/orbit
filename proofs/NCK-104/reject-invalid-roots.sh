#!/usr/bin/env bash
# Closed keys, JSON lists, protected catalog, symlinks, overlap, grammar, and managed checkouts fail without persisting.
source /var/lib/orbit-e2e/proof/lib.sh

before=$(sql_node_settings app-dev)
gateway_checkout=/home/orbit/orbit/apps/gateway
app_prod_checkout=$(instance_checkout e2e-prod)
test -d "$gateway_checkout" || fail "configured Gateway checkout is missing: $gateway_checkout"
[[ "$app_prod_checkout" == /var/www/laravel/e2e-prod ]] \
  || fail "app-prod checkout is not the recorded storage path: $app_prod_checkout"

assert_unchanged() {
  local why=$1
  [[ "$(sql_node_settings app-dev)" == "$before" ]] || fail "rejected mutation persisted ($why)"
}

reject_root() {
  local code=$1
  local path=$2
  expect_error "$code" orbit node:settings app-dev --setting=instance.path:"$path" --json
  assert_unchanged "settings $path"
  expect_error "$code" provision_app_dev --setting=instance.path:"$path" --json
  assert_unchanged "provision $path"
}

expect_local_error node.setting_unknown orbit node:settings app-dev --setting=packages.path:/srv/orbit/packages --json
assert_unchanged "local unknown settings"
expect_local_error node.setting_invalid orbit node:settings app-dev --setting=instance.path --json
assert_unchanged "local invalid settings"
expect_local_error node.setting_invalid orbit node:settings app-dev --setting=:/srv/a --json
assert_unchanged "local empty-key settings"
expect_local_error node.setting_duplicate orbit node:settings app-dev \
  --setting=instance.path:/srv/a --setting=instance.path:/srv/b --json
assert_unchanged "local duplicate settings"
expect_local_error node.setting_unknown provision_app_dev --setting=packages.path:/srv/orbit/packages --json
assert_unchanged "local unknown provision"
expect_local_error node.setting_invalid provision_app_dev --setting=instance.path --json
assert_unchanged "local invalid provision"
expect_local_error node.setting_invalid provision_app_dev --setting=:/srv/a --json
assert_unchanged "local empty-key provision"
expect_local_error node.setting_duplicate provision_app_dev \
  --setting=instance.path:/srv/a --setting=instance.path:/srv/b --json
assert_unchanged "local duplicate provision"

app_dev_id=$(node_id app-dev)
expect_http_error node.settings_invalid PATCH "/api/v1/nodes/${app_dev_id}/settings" '[]'
assert_unchanged "PATCH list body"
expect_http_error node.settings_invalid PATCH "/api/v1/nodes/${app_dev_id}/settings" '{"instance":[]}'
assert_unchanged "PATCH nested list"
list_node=nck104-json-list
post_list=$(php -r '
  echo json_encode([
    "name" => $argv[1],
    "public_ssh_host" => "192.0.2.11",
    "platform" => "linux",
    "architecture" => "x86_64",
    "tld" => "dev.orbit",
    "roles" => ["app-dev"],
    "host_key_fingerprint" => "SHA256:".str_repeat("A", 43),
    "settings" => [],
  ], JSON_UNESCAPED_SLASHES);
' -- "$list_node")
expect_http_error node.settings_invalid POST /api/v1/nodes "$post_list"
assert_unchanged "POST list settings"
[[ "$(sql_node_exists "$list_node")" == 0 ]] || fail "POST list settings created $list_node"
post_nested=$(php -r '
  echo json_encode([
    "name" => $argv[1],
    "public_ssh_host" => "192.0.2.11",
    "platform" => "linux",
    "architecture" => "x86_64",
    "tld" => "dev.orbit",
    "roles" => ["app-dev"],
    "host_key_fingerprint" => "SHA256:".str_repeat("A", 43),
    "settings" => ["instance" => []],
  ], JSON_UNESCAPED_SLASHES);
' -- "$list_node")
expect_http_error node.settings_invalid POST /api/v1/nodes "$post_nested"
assert_unchanged "POST nested list"
[[ "$(sql_node_exists "$list_node")" == 0 ]] || fail "POST nested list created $list_node"

reject_root node.settings_path_invalid /
reject_root node.settings_path_protected /home/orbit
reject_root node.settings_path_protected /home
reject_root node.settings_path_protected /boot
reject_root node.settings_path_protected /dev
reject_root node.settings_path_protected /etc/orbit
reject_root node.settings_path_protected /proc
reject_root node.settings_path_protected /run
reject_root node.settings_path_protected /sys
reject_root node.settings_path_protected /usr
reject_root node.settings_path_protected /opt/orbit
reject_root node.settings_path_protected /var/lib/orbit
reject_root node.settings_path_protected /var/www
reject_root node.settings_path_protected "$app_prod_checkout"
reject_root node.settings_path_protected /home/orbit/.ssh
reject_root node.settings_path_protected /home/orbit/.orbit

expect_error node.settings_roots_overlap orbit node:settings app-dev \
  --setting=instance.path:/srv/orbit/source --setting=worktree.path:/srv/orbit/source/worktrees --json
assert_unchanged "settings overlap"
expect_error node.settings_root_failed orbit node:settings app-dev --setting=instance.path:/mnt/orbit-link --json
assert_unchanged "settings symlink"
expect_error node.settings_path_invalid orbit node:settings app-dev --setting=instance.path:/srv/orbit/instances/ --json
assert_unchanged "settings trailing slash"
expect_error node.settings_path_invalid orbit node:settings app-dev --setting=instance.path:/srv/../orbit/instances --json
assert_unchanged "settings dot-dot"
expect_error node.settings_path_managed orbit node:settings app-dev --setting=instance.path:/home/orbit/apps/laravel --json
assert_unchanged "settings managed checkout"
expect_error node.settings_path_protected orbit node:settings app-dev --setting=instance.path:"$gateway_checkout" --json
assert_unchanged "settings gateway checkout"
expect_error node.settings_path_protected orbit node:settings app-dev --setting=instance.path:"$gateway_checkout/nested" --json
assert_unchanged "settings inside gateway checkout"
expect_error node.settings_path_protected orbit node:settings app-dev --setting=instance.path:"$(dirname "$gateway_checkout")" --json
assert_unchanged "settings gateway ancestor"

expect_error node.settings_roots_overlap provision_app_dev \
  --setting=instance.path:/srv/orbit/source --setting=worktree.path:/srv/orbit/source/worktrees --json
assert_unchanged "provision overlap"
expect_error node.settings_root_failed provision_app_dev --setting=instance.path:/mnt/orbit-link --json
assert_unchanged "provision symlink"
expect_error node.settings_path_invalid provision_app_dev --setting=instance.path:/srv/orbit/instances/ --json
assert_unchanged "provision trailing slash"
expect_error node.settings_path_invalid provision_app_dev --setting=instance.path:/srv/../orbit/instances --json
assert_unchanged "provision dot-dot"
expect_error node.settings_path_managed provision_app_dev --setting=instance.path:/home/orbit/apps/laravel --json
assert_unchanged "provision managed checkout"
expect_error node.settings_path_protected provision_app_dev --setting=instance.path:"$gateway_checkout" --json
assert_unchanged "provision gateway checkout"
expect_error node.settings_path_protected provision_app_dev --setting=instance.path:"$gateway_checkout/nested" --json
assert_unchanged "provision inside gateway checkout"
expect_error node.settings_path_protected provision_app_dev --setting=instance.path:"$(dirname "$gateway_checkout")" --json
assert_unchanged "provision gateway ancestor"

[[ "$(sql_node_settings app-dev)" == "$before" ]] || fail "rejected settings were persisted"

echo "reject: lists, catalog, overlap, symlink, grammar, and managed paths did not persist"
