#!/usr/bin/env bash
set -euo pipefail
umask 077

# Disposable proof that the current gateway Node can become vpn,
# the gateway role can move, and the target can take the name gateway.
# Run on the Gateway checkout node as orbit:
#   rename-and-relocate-gateway-role.sh <source-name> <target-name>
# Usual disposable topology: source=gateway target=extra
# Does not copy the serving checkout.

[[ $# -eq 2 ]]
source_name=$1
target_name=$2
[[ "$source_name" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,62}$ ]]
[[ "$target_name" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,62}$ ]]
[[ "$source_name" != "$target_name" ]]
[[ "$source_name" != "vpn" ]]
[[ "$target_name" != "gateway" ]]

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
if (isset($byName["vpn"])) {
    fwrite(STDERR, "name vpn is already registered\n");
    exit(65);
}
if (isset($byName["gateway"]) && $argv[1] !== "gateway") {
    fwrite(STDERR, "name gateway is already registered\n");
    exit(65);
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
    fwrite(STDERR, "source must hold gateway and vpn before rename\n");
    exit(1);
}
if (in_array("gateway", explode(",", $argv[2]), true)) {
    fwrite(STDERR, "target already holds gateway\n");
    exit(1);
}
' "$before_source" "$before_target"

"$orbit" node:rename "$source_id" vpn --json | php -r '
$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($v["name"] ?? null) !== "vpn" || ($v["id"] ?? null) !== (int) $argv[1]) {
    fwrite(STDERR, "rename did not return the source Node as vpn\n");
    exit(1);
}
if (($v["wireguard_ip"] ?? null) === null || $v["wireguard_ip"] === "") {
    fwrite(STDERR, "rename dropped the WireGuard address\n");
    exit(1);
}
printf("renamed node %d to vpn\n", $v["id"]);
' "$source_id"

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

"$orbit" node:rename "$target_id" gateway --json | php -r '
$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($v["name"] ?? null) !== "gateway" || ($v["id"] ?? null) !== (int) $argv[1]) {
    fwrite(STDERR, "rename did not return the target Node as gateway\n");
    exit(1);
}
printf("renamed node %d to gateway\n", $v["id"]);
' "$target_id"

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

"$orbit" node:show "$target_id" --json | php -r '
$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$access=$v["data"]["access"]["can_access"] ?? $v["access"]["can_access"] ?? null;
if (!is_array($access)) {
    fwrite(STDERR, "relocated gateway has no access list\n");
    exit(1);
}
$ids=[];
foreach ($access as $row) {
    if (is_array($row) && is_int($row["id"] ?? null)) {
        $ids[]=$row["id"];
    }
}
if (!in_array((int) $argv[1], $ids, true)) {
    fwrite(STDERR, "relocated gateway was not granted access to the vpn node\n");
    exit(1);
}
printf("granted gateway access to vpn node %d\n", (int) $argv[1]);
' "$source_id"

listed=$("$orbit" node:list --json)
php -r '
$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$nodes=$v["nodes"] ?? $v["data"] ?? null;
if (!is_array($nodes)) {
    exit(65);
}
$byId=[];
foreach ($nodes as $node) {
    if (!is_array($node) || !is_int($node["id"] ?? null) || !is_string($node["name"] ?? null)) {
        exit(65);
    }
    $byId[$node["id"]]=$node["name"];
}
if (($byId[(int) $argv[1]] ?? null) !== "vpn") {
    fwrite(STDERR, "source Node is not named vpn\n");
    exit(1);
}
if (($byId[(int) $argv[2]] ?? null) !== "gateway") {
    fwrite(STDERR, "target Node is not named gateway\n");
    exit(1);
}
' "$source_id" "$target_id" <<<"$listed"

ca=/home/orbit/.orbit/e2e-gateway-root-ca.pem
[[ -f "$ca" ]] || ca=/home/orbit/.orbit/ca/root.pem
curl --fail --silent --show-error --max-time 10 \
  --cacert "$ca" \
  --resolve "gateway.orbit:443:${source_ip}" \
  https://gateway.orbit/up >/dev/null
printf 'leftover serving stack answered /up on %s\n' "$source_ip"
printf 'names are vpn + gateway; gateway role is on gateway; vpn remains on vpn\n'
