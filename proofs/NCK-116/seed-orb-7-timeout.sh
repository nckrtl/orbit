#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

sudo rm -f -- "$ORB7_TIMEOUT_WITNESS"
address=$(this_address)
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 5433 \
  comment ORB7-FOREIGN-KEEP >/dev/null
sudo ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9101 \
  comment orbit:metrics-node-exporter-v2 >/dev/null
echo "timeout seed: foreign and look-alike rules installed"
