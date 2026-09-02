#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

role=${1-}
user=${2-}
[[ "$role" =~ ^(metrics|gateway|exporter)$ ]] || fail "invalid active-state role"
[[ -n "$user" ]] || fail "configured user is required"

address=$(wireguard_address)
[[ -n "$address" ]] || fail "WireGuard address is missing"
[[ "$(systemctl is-active prometheus-node-exporter)" == active ]] || fail "exporter is inactive"
[[ "$(stat -c '%U:%G %a' /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf)" == 'root:root 644' ]] \
  || fail "exporter configuration ownership changed"
grep -Fq '# Managed by Orbit: metrics' /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf \
  || fail "exporter ownership marker is missing"
ufw_has_comment orbit:metrics-node-exporter || fail "exporter firewall rule is missing"

if [[ "$user" != orbit ]]; then
  assert_non_orbit_boundary "$user"
fi

if [[ "$role" == metrics ]]; then
  for name in orbit-metrics-prometheus orbit-metrics-grafana; do
    state=$(sudo docker container inspect --format '{{.State.Status}} {{.State.Health.Status}}' "$name")
    [[ "$state" == 'running healthy' ]] || fail "$name is not healthy"
    [[ "$(sudo docker container inspect --format '{{index .Config.Labels "com.orbit.managed"}}' "$name")" == metrics ]] \
      || fail "$name ownership label changed"
  done
  [[ "$(stat -c '%U:%G %a' /etc/orbit/metrics/prometheus.yml)" == 'root:root 644' ]] \
    || fail "Metrics configuration ownership changed"
  ufw_has_comment orbit:metrics-grafana-upstream || fail "Grafana publication firewall rule is missing"
fi

if [[ "$role" == gateway ]]; then
  fragments=$(dirname "$(sudo readlink -f /etc/caddy/Caddyfile)")/fragments
  [[ "$(sudo stat -c '%U:%G %a' "$fragments/metrics.caddy")" == 'root:caddy 640' ]] \
    || fail "Metrics Caddy publication ownership changed"
  grep -Fq 'metrics.orbit' /etc/dnsmasq.d/orbit-records.conf || fail "Metrics DNS publication is missing"
fi

echo "active-state: $role on $(hostname) retained Metrics service, firewall, publication, and ownership boundaries as $user"
