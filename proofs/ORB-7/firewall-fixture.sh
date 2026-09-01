#!/usr/bin/env bash
set -euo pipefail
source /var/lib/orbit-e2e/proof/firewall-lib.sh

record=/var/lib/orbit-e2e/proof-cleanup/orb-7-firewall-fixture
stub=/usr/local/sbin/ufw
exporter=ORB7-OWNED-EXPORTER
transient=ORB7-OWNED-TRANSIENT
shifted=ORB7-OWNED-SHIFTED
address=$(wireguard_address)

[[ -n "$address" ]] || fail "the firewall fixture has no WireGuard address"
sudo test ! -e "$record" || fail "the firewall cleanup record already exists"
sudo test ! -e "$stub" || fail "the fake ufw path already exists"
sudo install -d -o root -g root -m 0700 -- "$record"
capture_firewall_shapes /tmp/orb-7-firewall-before
sudo install -o root -g root -m 0600 -- /tmp/orb-7-firewall-before "$record/ufw.before"
rm -f -- /tmp/orb-7-firewall-before

cleanup() {
  local status="$1"
  trap - EXIT INT TERM
  delete_firewall_rule "$shifted"
  delete_firewall_rule "$transient"
  delete_firewall_rule "$exporter"
  sudo rm -f -- "$stub"
  sudo cat "$record/ufw.before" > /tmp/orb-7-firewall-expected
  assert_firewall_shapes /tmp/orb-7-firewall-expected
  rm -f -- /tmp/orb-7-firewall-expected
  sudo rm -rf -- "$record"
  exit "$status"
}

trap 'cleanup "$?"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

sudo /usr/sbin/ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9100 comment "$exporter" >/dev/null
sudo /usr/sbin/ufw insert 1 allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9999 comment "$transient" >/dev/null
sudo /usr/sbin/ufw insert 1 allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9998 comment "$shifted" >/dev/null
printf '#!/usr/bin/env bash\nexec /usr/sbin/ufw "$@"\n' | sudo tee "$stub" >/dev/null
sudo chmod 0755 "$stub"
printf 'ready\n' | sudo tee "$record/ready" >/dev/null
while true; do sleep 1; done
