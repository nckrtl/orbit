#!/usr/bin/env bash
set -euo pipefail
umask 077

[[ $# -eq 3 ]]
original_node=$1
extra_node=$2
extra_address=$3
[[ "$original_node" == app-prod ]]
[[ "$extra_node" == app-prod-2 ]]
[[ "$extra_address" == 10.44.0.4 ]]

database=${ORBIT_E2E_GATEWAY_DB:-/home/orbit/.orbit/gateway.sqlite}
[[ -r "$database" ]]

php -r '
$pdo = new PDO("sqlite:".$argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = \"table\"")->fetchAll(PDO::FETCH_COLUMN);
foreach (["nodes", "instances", "workspaces", "app_instances", "routes", "route_targets"] as $table) {
    if (!in_array($table, $tables, true)) exit(65);
}
$node = $pdo->prepare("SELECT id, status FROM nodes WHERE name = ?");
$node->execute([$argv[2]]);
$original = $node->fetch(PDO::FETCH_ASSOC);
$node->execute([$argv[3]]);
$extra = $node->fetch(PDO::FETCH_ASSOC);
if (!is_array($original) || !is_array($extra) || $original["status"] !== "active" || $extra["status"] !== "active") exit(1);
if ((int)$pdo->query("SELECT COUNT(*) FROM instances")->fetchColumn() !== 0) exit(1);
if ((int)$pdo->query("SELECT COUNT(*) FROM workspaces")->fetchColumn() !== 0) exit(1);
$placements = $pdo->query("SELECT ai.id, n.name FROM app_instances ai JOIN nodes n ON n.id = ai.node_id ORDER BY ai.id")->fetchAll(PDO::FETCH_ASSOC);
if (count($placements) !== 1 || $placements[0]["name"] !== $argv[2]) exit(1);
if ((int)$pdo->query("SELECT COUNT(*) FROM routes")->fetchColumn() < 1) exit(1);
$routes = $pdo->query("SELECT r.id, r.node_id, r.generation_basis_node_id, COUNT(rt.id) AS targets, SUM(CASE WHEN ai.node_id = ".(int)$extra["id"]." THEN 1 ELSE 0 END) AS extra_targets FROM routes r LEFT JOIN route_targets rt ON rt.route_id = r.id LEFT JOIN app_instances ai ON ai.id = rt.app_instance_id GROUP BY r.id ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($routes as $route) {
    if ((int)$route["targets"] !== 1 || (int)$route["extra_targets"] !== 0) exit(1);
    if ((int)($route["node_id"] ?? 0) === (int)$extra["id"] || (int)($route["generation_basis_node_id"] ?? 0) === (int)$extra["id"]) exit(1);
}
' -- "$database" "$original_node" "$extra_node"

ping -c 1 -W 5 -- "$extra_address" >/dev/null
sudo -u orbit -- env HOME=/home/orbit ssh \
    -n \
    -o BatchMode=yes \
    -o ConnectTimeout=5 \
    -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
    -i /home/orbit/.orbit/ssh/id_ed25519 \
    "orbit@$extra_address" \
    "set -euo pipefail; php -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);'; test \"\$(systemctl is-active php8.5-fpm)\" = active; test \"\$(systemctl is-active caddy)\" = active; sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null"

printf '%s\n' 'extended-runtime-connectivity: ok'
