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
    if [[ "$current_uid" == 1000 && "$current_gid" == 1000 ]]; then exit 0; fi
    if id -u ubuntu >/dev/null 2>&1; then
      userdel --remove ubuntu || userdel ubuntu
    fi
    [[ -z "$(getent passwd 1000)" && -z "$(getent group 1000)" ]] || exit 67
    groupmod --gid 1000 orbit
    usermod --uid 1000 --gid 1000 orbit
    find / /run -xdev -uid "$current_uid" -exec chown -h 1000 {} + 2>/dev/null || true
    find / /run -xdev -gid "$current_gid" -exec chgrp -h 1000 {} + 2>/dev/null || true
    if systemctl is-active --quiet php8.5-fpm; then systemctl restart php8.5-fpm; fi
    ;;
  *) exit 64 ;;
esac
