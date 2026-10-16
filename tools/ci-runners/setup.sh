#!/usr/bin/env bash
# Orbit CI shard runners in unprivileged Incus containers. See "Self-hosted shard runners" in
# docs/reference/implementation-loop.md.
# Run on the host as a member of incus-admin with sudo. Every step is idempotent.
#
#   tools/ci-runners/setup.sh host SUBNET            # 10.253.1.1/24 on Beast, 10.253.2.1/24 on Shark
#   tools/ci-runners/setup.sh container NAME         # ci-beast-1, ci-beast-2, ci-shark-1
#   tools/ci-runners/setup.sh register NAME [TOKEN]  # ORBIT_RUNNER_TOKEN in the environment, or TOKEN
#   tools/ci-runners/setup.sh verify NAME            # egress and loopback probes from inside the container
#
# A registration token comes from `gh api -X POST repos/nckrtl/orbit/actions/runners/registration-token --jq .token`
# and expires after an hour. The script never stores or prints it. Prefer ORBIT_RUNNER_TOKEN: an argument shows in
# the host's process list. The script hands the token to the container on stdin, but config.sh takes it as an
# argument, so it shows in the host's process list for the seconds that config.sh runs.
#
# `container` installs missing packages without upgrading installed ones, except the recipe's PHP build, which
# bin/php-build installs and moves to the recipe. It sets raw.apparmor on an existing container; a changed raw.apparmor
# applies at the next restart. Run it only while the runner is idle, from a checkout of main.
set -euo pipefail

REPOSITORY=$(cd "$(dirname "$0")/../.." && pwd)
PROJECT=orbit-ci
NETWORK=orbitci0
ACL=orbit-ci-egress
POOL=default
IMAGE=images:ubuntu/26.04
RUNNER_VERSION=2.338.0
RUNNER_SHA256=af4b794c1bc41d73d40535e3fe092a39f9679cd8d965954c2aca25a05ca41d32
COMPOSER_VERSION=2.10.3
COMPOSER_SHA256=7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6
REPO_URL=https://github.com/nckrtl/orbit
LABELS=self-hosted,Linux,X64,orbit-shard
# systemd 259 (Ubuntu 26.04) hangs at boot in (sd-mkuserns) without nesting: AppArmor denies PID 1 and udevd
# signal/ptrace to container processes whose label is stacked (profile//&...). This lets the container signal its own
# stacked processes only; they stay confined by the container profile. Nesting stays off. Set per instance, because a
# profile key is also checked against the project's VM low-level restriction.
APPARMOR="signal peer=@{profile_name}//&*, ptrace peer=@{profile_name}//&*,"

host() {
  local subnet=$1

  incus project show "$PROJECT" >/dev/null 2>&1 || incus project create "$PROJECT" \
    -c features.images=true -c features.profiles=true -c features.storage.volumes=true \
    -c features.storage.buckets=true -c features.networks=false
  # restricted=true keeps containers unprivileged, blocks nesting and host disk devices, and limits the project to its
  # bridge. Container low-level keys stay allowed only for the raw.apparmor line set at launch (see APPARMOR).
  incus project set "$PROJECT" restricted=true restricted.networks.access="$NETWORK" \
    restricted.containers.nesting=block restricted.containers.privilege=unprivileged \
    restricted.virtual-machines.lowlevel=block restricted.containers.lowlevel=allow limits.virtual-machines=0

  # Egress: public IPv4 only. Every private, CGNAT, link-local, multicast and reserved range is rejected, which covers
  # the host LAN, WireGuard 10.44.0.0/24, docker and task bridges, and the bridge gateway (Incus accepts DNS/DHCP to
  # the gateway before the ACL runs). IPv6 has no allow rule, so the default reject applies to all of it.
  incus network acl show "$ACL" >/dev/null 2>&1 || incus network acl create "$ACL"
  incus network acl edit "$ACL" <<'YAML'
description: Orbit CI runner boundary
egress:
- action: reject
  destination: 0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3
  state: enabled
- action: reject
  destination: ::/0
  state: enabled
- action: allow
  destination: 0.0.0.0/0
  state: enabled
ingress: []
config: {}
YAML

  incus network show "$NETWORK" >/dev/null 2>&1 || incus network create "$NETWORK" --type=bridge \
    ipv4.address="$subnet" ipv4.nat=true ipv6.address=none
  incus network set "$NETWORK" ipv4.address="$subnet" ipv4.nat=true ipv6.address=none \
    security.acls="$ACL" security.acls.default.egress.action=reject security.acls.default.ingress.action=reject

  # ufw's routed policy is deny; the bridge needs the same forward allowance the task bridges have (orbittask+).
  sudo ufw status | grep -q "on $NETWORK .*# orbit-ci-runners" ||
    sudo ufw route allow in on "$NETWORK" comment orbit-ci-runners >/dev/null

  incus profile edit default --project "$PROJECT" <<YAML
description: Orbit CI shard runner
config:
  boot.autostart: "true"
  limits.cpu: "4"
  limits.memory: 8GiB
  limits.processes: "8192"
  security.nesting: "false"
  security.privileged: "false"
devices:
  eth0:
    name: eth0
    network: $NETWORK
    type: nic
  root:
    path: /
    pool: $POOL
    type: disk
YAML
}

in_ct() { incus exec "$1" --project "$PROJECT" -- "${@:2}"; }

container() {
  local name=$1
  incus info "$name" --project "$PROJECT" >/dev/null 2>&1 || incus launch "$IMAGE" "$name" --project "$PROJECT" -c raw.apparmor="$APPARMOR"
  if [ "$(incus config get "$name" raw.apparmor --project "$PROJECT")" != "$APPARMOR" ]; then
    incus config set "$name" raw.apparmor="$APPARMOR" --project "$PROJECT"
    echo "$name: raw.apparmor repaired; restart the container while its runner is idle to apply it" >&2
  fi
  [ "$(incus list "$name" --project "$PROJECT" -c s -f csv)" = RUNNING ] || incus start "$name" --project "$PROJECT"
  for _ in $(seq 90); do in_ct "$name" getent hosts archive.ubuntu.com >/dev/null 2>&1 && break; sleep 1; done

  # The recipe's exact PHP build (ADR 0216), the same one that CI publishes caches for. A rerun moves it to the recipe.
  # bin/php-build runs php.install-build, the installer that Nodes run too, with the libraries it uses. The container
  # gets the script, the recipe, and the whole remote program tree in the repository layout that bin/php-build reads.
  local tree=/root/orbit-php-build
  in_ct "$name" rm -rf "$tree"
  in_ct "$name" install -d -m 0700 "$tree/bin" "$tree/apps/gateway/resources/machine-software"
  incus file push "$REPOSITORY/bin/php-build" "$name$tree/bin/php-build" --project "$PROJECT"
  incus file push "$REPOSITORY/apps/gateway/resources/machine-software/recipe.json" \
    "$name$tree/apps/gateway/resources/machine-software/recipe.json" --project "$PROJECT"
  incus file push --recursive "$REPOSITORY/apps/gateway/resources/remote" "$name$tree/apps/gateway/resources/" --project "$PROJECT"
  # A recursive push keeps the host's owners; root runs this code, so root owns it.
  in_ct "$name" chown -R root:root "$tree"

  in_ct "$name" env RUNNER_VERSION="$RUNNER_VERSION" RUNNER_SHA256="$RUNNER_SHA256" \
    COMPOSER_VERSION="$COMPOSER_VERSION" COMPOSER_SHA256="$COMPOSER_SHA256" bash -euo pipefail <<'SH'
export DEBIAN_FRONTEND=noninteractive
caddy_key=/usr/share/keyrings/caddy-stable-archive-keyring.gpg
if [ ! -s "$caddy_key" ]; then
  apt-get update -qq && apt-get install -y -qq --no-install-recommends curl ca-certificates gnupg >/dev/null
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor > /tmp/caddy.gpg
  gpg --show-keys --with-colons /tmp/caddy.gpg | grep -q '^fpr:::::::::65760C51EDEA2017CEA2CA15155B6D79CA56EA34:'
  install -m 0644 /tmp/caddy.gpg "$caddy_key" && rm /tmp/caddy.gpg
fi
echo "deb [signed-by=$caddy_key] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main" \
  > /etc/apt/sources.list.d/caddy-stable.list
apt-get update -qq
apt-get install -y -qq --no-install-recommends --no-upgrade \
  git curl ca-certificates jq unzip zip rsync sqlite3 python3 openssh-client socat netcat-openbsd bind9-dnsutils \
  acl attr wireguard-tools openssl nodejs caddy tar gzip xz-utils libkrb5-3 zlib1g gpgv >/dev/null
# A shard container runs only CI jobs, so it purges PHP packages and sources outside the recipe.
python3 /root/orbit-php-build/bin/php-build install --purge-extras --no-composer >/dev/null
# The runner's own native dependencies (ICU, OpenSSL, Kerberos, zlib) come with the list above; its
# installdependencies.sh only knows libicu names up to 76.
systemctl disable --now caddy >/dev/null 2>&1 || true

if ! echo "${COMPOSER_SHA256}  /usr/local/bin/composer" | sha256sum -c - >/dev/null 2>&1; then
  curl -fsSLo /tmp/composer.phar "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar"
  echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c - >/dev/null
  install -m 0755 /tmp/composer.phar /usr/local/bin/composer && rm /tmp/composer.phar
fi
# The Composer update program runs /usr/bin/composer, the path on Nodes, and its tests run that program.
ln -sfn /usr/local/bin/composer /usr/bin/composer

# The runner user owns nothing outside its home and has no sudo. Remove the image's default user if it has one.
id ubuntu >/dev/null 2>&1 && userdel -r ubuntu 2>/dev/null || true
id runner >/dev/null 2>&1 || useradd -m -U -s /bin/bash runner
# The image grants its default user passwordless sudo in 90-incus.
rm -f /etc/sudoers.d/runner /etc/sudoers.d/90-incus
install -d -o runner -g runner /home/runner/work

if [ ! -x /home/runner/actions-runner/config.sh ]; then
  curl -fsSLo /tmp/runner.tgz "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
  echo "${RUNNER_SHA256}  /tmp/runner.tgz" | sha256sum -c - >/dev/null
  install -d -o runner -g runner /home/runner/actions-runner
  tar -xzf /tmp/runner.tgz -C /home/runner/actions-runner && rm /tmp/runner.tgz
  chown -R runner:runner /home/runner/actions-runner
fi
SH
}

register() {
  local name=$1 token=${2:-${ORBIT_RUNNER_TOKEN:-}}
  if ! in_ct "$name" test -f /home/runner/actions-runner/.runner; then
    [ -n "$token" ] || { echo 'register needs TOKEN or ORBIT_RUNNER_TOKEN' >&2; exit 2; }
    in_ct "$name" chown -R runner:runner /home/runner/actions-runner
    # The token reaches the container on stdin, not in the argv of incus. config.sh still takes it as an argument.
    local uid gid
    uid=$(in_ct "$name" id -u runner) gid=$(in_ct "$name" id -g runner)
    printf '%s\n' "$token" | incus exec "$name" --project "$PROJECT" --user "$uid" --group "$gid" \
      --env HOME=/home/runner --cwd /home/runner/actions-runner -- \
      sh -c 'IFS= read -r token; exec ./config.sh --unattended --url "$1" --token "$token" --name "$2" --labels "$3" --work /home/runner/work --replace' \
      config "$REPO_URL" "$name" "$LABELS" >/dev/null
  fi
  in_ct "$name" bash -c "cd /home/runner/actions-runner && { [ -f .service ] || ./svc.sh install runner >/dev/null; } && ./svc.sh start >/dev/null && systemctl is-active \$(cat .service)"
}

verify() {
  local name=$1 targets=() ip
  for ip in $(ip -4 -o addr show | awk '$2 != "lo" {split($4,a,"/"); print a[1]}'); do
    targets+=("$ip:22" "$ip:53" "$ip:2019" "$ip:5432" "$ip:6379" "$ip:443")
  done
  targets+=(10.44.0.2:443 192.168.6.1:80)
  in_ct "$name" su runner -c "
    probe() { timeout 3 bash -c \"exec 3<>/dev/tcp/\${1%:*}/\${1##*:}\" 2>/dev/null && echo \"OPEN    \$1\" || echo \"blocked \$1\"; }
    for t in ${targets[*]}; do probe \$t; done
    gw=\$(ip -4 route show default | awk '{print \$3}')
    probe \$gw:22; probe \$gw:2019
    echo 'note    port 53 on host addresses is a known gap: Incus accepts DNS from the bridge before the ACL' 
    getent hosts github.com >/dev/null && echo 'dns     ok' || echo 'dns     FAILED'
    curl -fsS -o /dev/null -w 'https   github.com %{http_code}\n' https://github.com
    timeout 3 curl -6 -fsS -o /dev/null https://github.com 2>/dev/null && echo 'OPEN    ipv6' || echo 'blocked ipv6'
    python3 -c 'import socket;s=socket.socket();s.bind((\"127.0.0.1\",2019));s.listen();c=socket.create_connection((\"127.0.0.1\",2019));print(\"own lo  127.0.0.1:2019 is the container (bound by the probe itself)\")'
    sudo -n true 2>/dev/null && echo 'SUDO    allowed' || echo 'sudo    denied'
  "
}

case "${1:-}" in
  host | container | register | verify) "$@" ;;
  *) sed -n '2,17p' "$0" >&2; exit 2 ;;
esac
