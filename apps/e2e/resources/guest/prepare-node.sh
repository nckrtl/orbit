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
  *) exit 64 ;;
esac
