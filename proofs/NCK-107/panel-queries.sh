#!/usr/bin/env bash
# Every query on the served dashboard returns finite data for every scraped node.
source /var/lib/orbit-e2e/proof/lib.sh

password=$(grafana_password)
served=$(wait_for_dashboard "$password")
mapfile -t queries < <(printf '%s' "$served" | php -r '
  $panels = json_decode(stream_get_contents(STDIN), true)["dashboard"]["panels"] ?? [];
  foreach ($panels as $panel) {
    foreach ($panel["targets"] ?? [] as $target) { echo $panel["title"], "\t", $target["expr"], "\n"; }
  }
')
[[ ${#queries[@]} -eq 12 ]] || fail "expected 12 panel queries on the served dashboard, found ${#queries[@]}"

nodes=$(scraped_nodes)
[[ -n "$nodes" ]] || fail "Prometheus reports no scraped node"

check_all() {
  local node line title expr verdict exact
  for node in ${nodes//,/ }; do
    for line in "${queries[@]}"; do
      title=${line%%$'\t'*}
      expr=${line#*$'\t'}
      # The fstype filter must leave exactly one root filesystem, including on the Docker host.
      if [[ "$title" == 'Root Disk Used' ]]; then exact=(1); else exact=(); fi
      verdict=$(
        prom_query "${expr//\$node/$node}" \
          | php /var/lib/orbit-e2e/proof/series-verdict.php 1 "${exact[@]}"
      )
      if [[ "$verdict" != ok ]]; then
        echo "[$node] $title $verdict"
        return 1
      fi
    done
  done
  return 0
}

for _ in $(seq 1 18); do
  if problem=$(check_all); then
    echo "panels: ${#queries[@]} queries returned finite data for every scraped node ($nodes)"
    exit 0
  fi
  sleep 10
done

fail "$problem"
