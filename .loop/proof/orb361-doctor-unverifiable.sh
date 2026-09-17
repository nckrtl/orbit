#!/usr/bin/env bash
set -euo pipefail
printf 'orbit ALL=(ALL) NOPASSWD: ALL\norbit ALL=(ALL) NOPASSWD: !/usr/sbin/ufw\n' | sudo tee /etc/sudoers.d/zzz-orb361-deny-ufw > /dev/null
sudo chmod 0440 /etc/sudoers.d/zzz-orb361-deny-ufw
sudo visudo -c
