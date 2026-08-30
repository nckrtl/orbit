#!/usr/bin/env bash
set -euo pipefail
umask 077
mode=${1-}
case "$mode" in
  gateway-authorize)
    [[ $# -eq 2 && "$2" =~ ^ssh-ed25519\ [A-Za-z0-9+/]+={0,2}$ ]] || exit 64
    printf '%s\n' "$2" | ssh-keygen -lf - -E sha256 >/dev/null
    install -d -m 0700 /home/orbit/.ssh
    candidate=$(mktemp /home/orbit/.ssh/authorized_keys.XXXXXX)
    trap 'rm -f "$candidate"' EXIT
    printf '%s\n' "$2" >"$candidate"
    chmod 0600 "$candidate"
    chown orbit:orbit "$candidate"
    mv -f "$candidate" /home/orbit/.ssh/authorized_keys
    chown orbit:orbit /home/orbit/.ssh
    ;;
  permissions)
    [[ $# -eq 1 ]]
    install -d -o orbit -g orbit -m 0755 /home/orbit
    for path in /home/orbit/apps/laravel /home/orbit/.orbit/worktrees/laravel/e2e; do
      [[ ! -e "$path" ]] || chown -R orbit:orbit "$path"
    done
    ;;
  align-identity)
    # Feature topologies mount the host worktree (owned by host uid/gid 1000)
    # into /home/orbit/orbit, so the orbit account must own uid/gid 1000.
    # The stock cloud image gives 1000 to the unused ubuntu user.
    [[ $# -eq 1 ]]
    current_uid=$(id -u orbit)
    current_gid=$(id -g orbit)
    if [[ "$current_uid" != 1000 || "$current_gid" != 1000 ]]; then
      if id -u ubuntu >/dev/null 2>&1; then
        userdel --remove ubuntu || userdel ubuntu
      fi
      [[ -z "$(getent passwd 1000)" && -z "$(getent group 1000)" ]] || exit 67
      groupmod --gid 1000 orbit
      usermod --uid 1000 --gid 1000 orbit
    fi
    # uutils chown/chgrp -h (Ubuntu 26.04) silently skip symlinks; lchown does not.
    # Ownership left behind by the old ids has no account any more; adopt it for orbit.
    find /home/orbit \( -nouser -o -nogroup \) -exec python3 -c '
import grp, os, pwd, sys
def orphan(lookup, ident):
    try:
        lookup(ident)
    except KeyError:
        return True
    return False
for path in sys.argv[1:]:
    st = os.lstat(path)
    os.lchown(path, 1000 if orphan(pwd.getpwuid, st.st_uid) else -1, 1000 if orphan(grp.getgrgid, st.st_gid) else -1)
' {} + 2>/dev/null || true
    [[ "$current_uid" == 1000 && "$current_gid" == 1000 ]] && exit 0
    find / /run -xdev \( -uid "$current_uid" -o -gid "$current_gid" \) -exec python3 -c '
import os, sys
old_uid, old_gid = int(sys.argv[1]), int(sys.argv[2])
for path in sys.argv[3:]:
    st = os.lstat(path)
    os.lchown(path, 1000 if st.st_uid == old_uid else -1, 1000 if st.st_gid == old_gid else -1)
' "$current_uid" "$current_gid" {} + 2>/dev/null || true
    if systemctl is-active --quiet php8.5-fpm; then systemctl restart php8.5-fpm; fi
    ;;
  *) exit 64 ;;
esac
