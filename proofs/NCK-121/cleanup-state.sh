#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

role=${1-}
[[ "$role" =~ ^(metrics|gateway|exporter)$ ]] || fail "invalid cleanup-state role"

[[ ! -e /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf ]] || fail "exporter drop-in remains"
[[ "$(systemctl is-active prometheus-node-exporter || true)" != active ]] || fail "exporter remains active"
! ufw_has_comment orbit:metrics-node-exporter || fail "exporter firewall rule remains"
dpkg -s prometheus-node-exporter >/dev/null 2>&1 || fail "shared exporter package was removed"

if [[ "$role" == metrics ]]; then
  [[ -z "$(sudo docker ps -a --format '{{.Names}}' | grep '^orbit-metrics-' || true)" ]] || fail "Metrics containers remain"
  [[ ! -e /etc/orbit/metrics ]] || fail "Metrics configuration remains"
  ! ufw_has_comment orbit:metrics-grafana-upstream || fail "Grafana publication firewall rule remains"
  volumes=$(sudo docker volume ls --format '{{.Name}}' | grep -c '^orbit-metrics-\(prometheus\|grafana\)-data$')
  [[ "$volumes" == 2 ]] || fail "preserved Metrics volumes changed"
fi

if [[ "$role" == gateway ]]; then
  fragments=$(dirname "$(sudo readlink -f /etc/caddy/Caddyfile)")/fragments
  ! sudo test -e "$fragments/metrics.caddy" || fail "Metrics Caddy publication remains"
  ! grep -Rq 'metrics.orbit' /etc/dnsmasq.d/ || fail "Metrics DNS publication remains"
fi

echo "cleanup-state: $role on $(hostname) removed owned runtime state and preserved shared state"
