#!/usr/bin/env bash
# Pre-create safe, loose-mode, foreign, and symlink roots used by later acceptance checks.
set -euo pipefail

sudo install -d -o orbit -g orbit -m 0750 -- /mnt/orbit-ok
sudo install -d -o orbit -g orbit -m 0775 -- /mnt/orbit-loose
sudo install -d -o root -g root -m 0755 -- /mnt/orbit-foreign
sudo ln -sfn /tmp /mnt/orbit-link
test "$(stat -c '%U:%G %a' /mnt/orbit-ok)" = 'orbit:orbit 750'
test "$(stat -c '%U:%G %a' /mnt/orbit-loose)" = 'orbit:orbit 775'
test "$(stat -c '%U:%G %a' /mnt/orbit-foreign)" = 'root:root 755'
test -L /mnt/orbit-link
echo "prepared /mnt/orbit-ok, /mnt/orbit-loose, /mnt/orbit-foreign, /mnt/orbit-link"
