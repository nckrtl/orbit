#!/usr/bin/env bash
# Brings the Gateway control plane back, so ordinary registration works again.
source /var/lib/orbit-e2e/proof/lib.sh

sudo systemctl start php8.5-fpm || fail "could not start php8.5-fpm"
sudo systemctl start caddy || fail "could not start caddy"

address=$(this_address)

for _ in $(seq 1 30); do
  if curl --silent --fail --insecure --header 'Host: gateway.orbit' "https://${address}/up" >/dev/null 2>&1; then
    echo "gateway-online: the Gateway control plane answers again on ${address}"
    exit 0
  fi
  sleep 2
done

fail "the Gateway did not come back on ${address}"
