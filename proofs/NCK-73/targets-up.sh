#!/usr/bin/env bash
# Prometheus scrapes every expected node label successfully (bounded wait for the first scrape).
# Usage: targets-up.sh <comma separated node names>
source /var/lib/orbit-e2e/proof/lib.sh

expected=$1
for attempt in $(seq 1 30); do
  report=$(curl --silent --max-time 5 http://127.0.0.1:9090/api/v1/targets | php -r '
    $expected = explode(",", $argv[1]);
    $targets = json_decode(stream_get_contents(STDIN), true)["data"]["activeTargets"] ?? [];
    $seen = [];
    foreach ($targets as $target) { $seen[$target["labels"]["node"]] = $target["health"]; }
    ksort($seen);
    $ok = count($seen) === count($expected);
    foreach ($expected as $node) { $ok = $ok && (($seen[$node] ?? null) === "up"); }
    echo $ok ? "ok" : "pending", " ", json_encode($seen);
  ' -- "$expected")
  if [[ "$report" == ok* ]]; then
    echo "targets: $report"
    exit 0
  fi
  sleep 3
done
fail "targets not up: $report"
