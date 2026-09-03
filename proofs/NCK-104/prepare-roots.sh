#!/usr/bin/env bash
# Pre-create safe, loose-mode, foreign, and symlink roots used by later acceptance checks.
proof_root=${ORBIT_E2E_PROOF_ROOT:-/var/lib/orbit-e2e/proof}
source "$proof_root/lib.sh"

restore_original_paths() {
  bash "$ORB7_STATE_HELPER" restore nck104-original-paths
}
orb7_traps prepare-roots
orb7_set_cleanup_hook restore_original_paths
orb7_arm_paths nck104-original-paths \
  /mnt/orbit-ok /mnt/orbit-loose /mnt/orbit-foreign /mnt/orbit-link \
  /srv/orbit /srv/restricted /home/orbit/projects-nck104 \
  /home/orbit/projects-nck104-caddy /home/orbit/custom-worktrees
orb7_arm_paths prepare-roots /mnt/orbit-ok /mnt/orbit-loose /mnt/orbit-foreign /mnt/orbit-link
orb7_checkpoint prepare-roots post-record
sudo install -d -o orbit -g orbit -m 0750 -- /mnt/orbit-ok
orb7_mark_active prepare-roots
orb7_checkpoint prepare-roots post-mutation
sudo install -d -o orbit -g orbit -m 0775 -- /mnt/orbit-loose
sudo install -d -o root -g root -m 0755 -- /mnt/orbit-foreign
sudo ln -sfn /tmp /mnt/orbit-link
test "$(stat -c '%U:%G %a' /mnt/orbit-ok)" = 'orbit:orbit 750'
test "$(stat -c '%U:%G %a' /mnt/orbit-loose)" = 'orbit:orbit 775'
test "$(stat -c '%U:%G %a' /mnt/orbit-foreign)" = 'root:root 755'
test -L /mnt/orbit-link
orb7_publish prepare-roots
echo "prepared /mnt/orbit-ok, /mnt/orbit-loose, /mnt/orbit-foreign, /mnt/orbit-link"
