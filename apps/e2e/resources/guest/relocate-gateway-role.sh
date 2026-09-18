#!/usr/bin/env bash
set -euo pipefail
umask 077

# Disposable proof that the gateway role can leave the vpn Node.
# Run on the Gateway checkout node as orbit:
#   relocate-gateway-role.sh <source-name> <target-name>
# Usual disposable topology: source=gateway target=extra (cold-acceptance)
# or a Node added for the proof. Does not copy the serving checkout.

[[ $# -eq 2 ]]
source_name=$1
target_name=$2
[[ "$source_name" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,62}$ ]]
[[ "$target_name" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,62}$ ]]
[[ "$source_name" != "$target_name" ]]

orbit=${ORBIT_BIN:-/usr/local/bin/orbit}
[[ -x "$orbit" ]]

nodes=$("$orbit" node:list --json)
read -r source_id source_ip target_id < <(php -r '
$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$nodes=$v["nodes"] ?? $v["data"] ?? null;
if (!is_array($nodes)) {
    fwrite(STDERR, "node list is not an array\n");
    exit(65);
}
$byName=[];
foreach ($nodes as $node) {
    if (!is_array($node) || !is_string($node["name"] ?? null) || !is_int($node["id"] ?? null)) {
        exit(65);
    }
    $byName[$node["name"]]=$node;
}
$source=$byName[$argv[1]] ?? null;
$target=$byName[$argv[2]] ?? null;
if (!is_array($source) || !is_array($target)) {
    fwrite(STDERR, "source or target Node is not registered\n");
    exit(65);
}
$ip=$source["wireguard_ip"] ?? null;
if (!is_string($ip) || $ip === "") {
    fwrite(STDERR, "source Node has no WireGuard address\n");
    exit(65);
}
printf("%d %s %d\n", $source["id"], $ip, $target["id"]);
' "$source_name" "$target_name" <<<"$nodes")

roles_on() {
  local node_id=$1
  "$orbit" node:role:list "$node_id" --json | php -r '
$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$data=$v["assignments"] ?? $v["data"] ?? $v["roles"] ?? null;
if (!is_array($data)) {
    exit(65);
}
$roles=[];
foreach ($data as $row) {
    $role=is_array($row) ? ($row["role"] ?? null) : $row;
    if (!is_string($role) || $role === "") {
        exit(65);
    }
    $roles[]=$role;
}
sort($roles);
echo implode(",", $roles), "\n";
'
}

before_source=$(roles_on "$source_id")
before_target=$(roles_on "$target_id")
php -r '
$source=explode(",", $argv[1]);
if (!in_array("gateway", $source, true) || !in_array("vpn", $source, true)) {
    fwrite(STDERR, "source must hold gateway and vpn before relocate\n");
    exit(1);
}
if (in_array("gateway", explode(",", $argv[2]), true)) {
    fwrite(STDERR, "target already holds gateway\n");
    exit(1);
}
' "$before_source" "$before_target"

"$orbit" node:role:relocate "$target_id" gateway --force --json | php -r '
$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($v["role"] ?? null) !== "gateway" || ($v["removed"] ?? true) !== false) {
    fwrite(STDERR, "relocate did not return the gateway assignment\n");
    exit(1);
}
if (($v["assignment"]["status"] ?? null) !== "active") {
    fwrite(STDERR, "relocated gateway assignment is not active\n");
    exit(1);
}
printf("relocated gateway to node %d\n", $v["node_id"]);
'

after_source=$(roles_on "$source_id")
after_target=$(roles_on "$target_id")
php -r '
$source=explode(",", $argv[1]);
$target=explode(",", $argv[2]);
if (in_array("gateway", $source, true)) {
    fwrite(STDERR, "source still holds gateway\n");
    exit(1);
}
if (!in_array("vpn", $source, true)) {
    fwrite(STDERR, "vpn left the source Node\n");
    exit(1);
}
if (!in_array("gateway", $target, true)) {
    fwrite(STDERR, "target does not hold gateway\n");
    exit(1);
}
if (in_array("vpn", $target, true)) {
    fwrite(STDERR, "vpn moved with gateway\n");
    exit(1);
}
' "$after_source" "$after_target"

curl --fail --silent --show-error --max-time 10 "https://${source_ip}/up" >/dev/null
printf 'leftover serving stack answered /up on %s\n' "$source_ip"
printf 'gateway role is on %s; vpn remains on %s\n' "$target_name" "$source_name"
