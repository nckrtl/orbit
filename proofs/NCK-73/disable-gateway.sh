#!/usr/bin/env bash
# After disable the Gateway holds no publication and still stores the encrypted credential.
source /var/lib/orbit-e2e/proof/lib.sh

fragments=$(dirname "$(readlink -f /etc/caddy/Caddyfile)")/fragments
[[ ! -e "$fragments/metrics.caddy" ]] || fail "Caddy fragment remains"
! grep -rq 'metrics.orbit' /etc/dnsmasq.d/ || fail "DNS record remains"
[[ -z "$(dig +short metrics.orbit @127.0.0.1 | head -n 1)" ]] || fail "metrics.orbit still resolves"
stored=$(php -r '
  $pdo = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
  echo (int) $pdo->query("select count(*) from settings where key = \"metrics.grafana.admin_password\"")->fetchColumn();
')
[[ "$stored" == 1 ]] || fail "credential not preserved: $stored rows"
echo "disable-gateway: publication removed, credential setting preserved"
