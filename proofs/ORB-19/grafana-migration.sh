#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

password=$(grafana_password)
address=$(wireguard_address)
api="http://$address:3000/api"

curl --silent --show-error --fail --max-time 15 --user "admin:$password" \
  --request DELETE "$api/datasources/uid/orbit-prometheus" >/dev/null
curl --silent --show-error --fail --max-time 15 --user "admin:$password" \
  --header 'Content-Type: application/json' \
  --data '{"name":"Prometheus","type":"prometheus","access":"proxy","url":"http://127.0.0.1:9090","isDefault":true}' \
  "$api/datasources" >/dev/null

before=$(curl --silent --show-error --fail --max-time 15 --user "admin:$password" "$api/datasources")
echo "$before" | php -r '
  $rows = json_decode(stream_get_contents(STDIN), true);
  $legacy = array_values(array_filter($rows, fn ($row) => ($row["name"] ?? null) === "Prometheus"));
  exit(count($legacy) === 1 ? 0 : 1);
' || fail "legacy Prometheus datasource precondition was not planted"

grafana_before=$(docker container inspect --format '{{.Id}}' orbit-metrics-grafana)
prometheus_before=$(docker container inspect --format '{{.Id}}' orbit-metrics-prometheus)
volume_before=$(docker container inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/grafana"}}{{.Name}}{{end}}{{end}}' orbit-metrics-grafana)
[[ -n "$volume_before" ]] || fail "Grafana named volume is missing"

docker container rm --force orbit-metrics-grafana >/dev/null
orbit node:role:add app-dev metrics --converge --json >/dev/null

grafana_after=$(docker container inspect --format '{{.Id}}' orbit-metrics-grafana)
prometheus_after=$(docker container inspect --format '{{.Id}}' orbit-metrics-prometheus)
volume_after=$(docker container inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/grafana"}}{{.Name}}{{end}}{{end}}' orbit-metrics-grafana)
[[ "$grafana_after" != "$grafana_before" ]] || fail "Grafana container was not replaced"
[[ "$prometheus_after" == "$prometheus_before" ]] || fail "Prometheus was replaced during Grafana-only recovery"
[[ "$volume_after" == "$volume_before" ]] || fail "Grafana volume changed: $volume_before -> $volume_after"

after=$(curl --silent --show-error --fail --max-time 15 --user "admin:$password" "$api/datasources")
echo "$after" | php -r '
  $rows = json_decode(stream_get_contents(STDIN), true);
  $pinned = array_values(array_filter($rows, fn ($row) => ($row["name"] ?? null) === "orbit-prometheus" && ($row["uid"] ?? null) === "orbit-prometheus"));
  $legacy = array_values(array_filter($rows, fn ($row) => ($row["name"] ?? null) === "Prometheus"));
  exit(count($pinned) === 1 && count($legacy) === 0 ? 0 : 1);
' || fail "Grafana datasource migration did not converge to one pinned datasource: $after"

echo "grafana-migration: legacy datasource removed, pinned datasource active, Grafana-only restart preserved $volume_after"
