#!/usr/bin/env bash
# app-prod managed-Caddyfile proof fixture (NCK-84). Runs as the orbit user on
# a checkout role. Every subcommand exits non-zero when its expectation fails.
set -euo pipefail
cd /
orbit=/home/orbit/orbit/apps/cli/orbit

case ${1-} in
  publish)
    # A product Caddy publish on app-prod succeeds against the managed layout:
    # re-project the production instance through the product and require it
    # to be active again (a failed publish surfaces app-prod.caddy_config_failed).
    [[ $# -eq 1 ]] || exit 64
    "$orbit" instance:list --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => ($x["environment"] ?? null)==="production")); if(count($m)!==1) exit(65); printf("%d %s\n", $m[0]["id"], $m[0]["php_version"]);' | while read -r id version; do
      if ! output=$("$orbit" instance:php "$id" "$version" --json 2>&1); then
        printf 'caddy-proof: instance:php failed: %s\n' "$output" >&2
        exit 1
      fi
      printf '%s' "$output" | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (($v["status"] ?? null) !== "active") { fwrite(STDERR, "caddy-proof: production instance is not active after publish\n"); exit(1); } printf("caddy-proof: product publish re-projected instance %d (%s) on node %d\n", $v["id"], $v["name"], $v["node_id"]);'
    done
    ;;
  doctor)
    # Doctor reports no drift anywhere: the managed symlink and the app-prod
    # fragment lookup both work on a fresh topology.
    [[ $# -eq 1 ]] || exit 64
    if json=$("$orbit" doctor --json); then doctor_exit=0; else doctor_exit=$?; fi
    [[ $doctor_exit -eq 0 || $doctor_exit -eq 1 ]] || { printf 'caddy-proof: orbit doctor exited %d\n' "$doctor_exit" >&2; exit 1; }
    DOCTOR_JSON="$json" php <<'PHP'
<?php
declare(strict_types=1);
$report = json_decode((string) getenv('DOCTOR_JSON'), true, 32, JSON_THROW_ON_ERROR);
$issues = [];
foreach ($report['nodes'] ?? [] as $node) {
    foreach ($node['families'] as $family) {
        foreach ($family['issues'] as $issue) {
            $issues[] = [$node['node_name'], $family['family'], $issue['code']];
        }
    }
}
if (count($report['nodes'] ?? []) !== 3) { fwrite(STDERR, "caddy-proof: report does not cover three nodes\n"); exit(1); }
if ($issues !== []) { fwrite(STDERR, 'caddy-proof: unexpected drift: '.json_encode($issues)."\n"); exit(1); }
printf("caddy-proof: %d nodes, no drift, no Caddy tolerance\n", count($report['nodes']));
PHP
    ;;
  *) exit 64 ;;
esac
