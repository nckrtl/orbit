#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install --yes --no-install-recommends sudo openssh-server git curl ca-certificates python3 iproute2 wireguard-tools resolvconf composer php8.5-cli php8.5-fpm php8.5-curl php8.5-mbstring php8.5-xml php8.5-sqlite3 php8.5-zip php8.5-bcmath
if ! id orbit >/dev/null 2>&1; then
  if id ubuntu >/dev/null 2>&1; then userdel --remove ubuntu; fi
  useradd --create-home --uid 1000 --shell /bin/bash orbit
fi
install -d -o orbit -g orbit -m 0755 /home/orbit/orbit
printf 'orbit ALL=(ALL) NOPASSWD: ALL\n' >/etc/sudoers.d/orbit
chmod 0440 /etc/sudoers.d/orbit
systemctl enable --now ssh php8.5-fpm
