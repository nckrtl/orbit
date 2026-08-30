#!/usr/bin/env bash
# Takes the Gateway control plane down, so no node can reach any Metrics route.
source /var/lib/orbit-e2e/proof/lib.sh

sudo systemctl stop caddy || fail "could not stop caddy"
sudo systemctl stop php8.5-fpm || fail "could not stop php8.5-fpm"

echo "gateway-offline: the Gateway control plane is down"
