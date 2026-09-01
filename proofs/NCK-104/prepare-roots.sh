#!/usr/bin/env bash
# Pre-create safe, loose-mode, foreign, and symlink roots used by later acceptance checks.
source /var/lib/orbit-e2e/proof/lib.sh

orb7_arm_paths nck104-original-paths \
  /mnt/orbit-ok /mnt/orbit-loose /mnt/orbit-foreign /mnt/orbit-link \
  /srv/orbit /srv/restricted /home/orbit/projects-nck104 \
  /home/orbit/projects-nck104-caddy /home/orbit/custom-worktrees
orb7_arm_paths prepare-roots /mnt/orbit-ok /mnt/orbit-loose /mnt/orbit-foreign /mnt/orbit-link
orb7_traps prepare-roots
sudo install -d -o orbit -g orbit -m 0750 -- /mnt/orbit-ok
orb7_mark_active prepare-roots
orb7_checkpoint prepare-roots
sudo install -d -o orbit -g orbit -m 0775 -- /mnt/orbit-loose
sudo install -d -o root -g root -m 0755 -- /mnt/orbit-foreign
sudo ln -sfn /tmp /mnt/orbit-link
test "$(stat -c '%U:%G %a' /mnt/orbit-ok)" = 'orbit:orbit 750'
test "$(stat -c '%U:%G %a' /mnt/orbit-loose)" = 'orbit:orbit 775'
test "$(stat -c '%U:%G %a' /mnt/orbit-foreign)" = 'root:root 755'
test -L /mnt/orbit-link
orb7_publish prepare-roots
echo "prepared /mnt/orbit-ok, /mnt/orbit-loose, /mnt/orbit-foreign, /mnt/orbit-link"
