#!/usr/bin/env bash
set -euo pipefail
umask 077
mode=${1-}
case "$mode" in
  checkout)
    [[ $# -eq 3 && "$2" =~ ^[0-9a-f]{40}$ ]]
    case "$3" in gateway|app-dev) ;; *) exit 64 ;; esac
    cd /home/orbit
    /home/orbit/orbit/apps/e2e/resources/guest/hydrate-orbit.sh orbit "$([[ "$3" == gateway ]] && printf gateway || printf cli)"
    git -C /home/orbit/orbit reset --hard --quiet "$2"
    ;;
  ssh-pins)
    [[ $# -eq 3 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]]
    install -d -m 0700 /home/orbit/.ssh
    pins=$(mktemp)
    trap 'rm -f "$pins"' EXIT
    ssh-keyscan -H -- "$2" "$3" >"$pins"
    touch /home/orbit/.ssh/known_hosts
    while IFS= read -r pin; do
      grep -qxF -- "$pin" /home/orbit/.ssh/known_hosts || printf '%s\n' "$pin" >>/home/orbit/.ssh/known_hosts
    done <"$pins"
    chmod 0600 /home/orbit/.ssh/known_hosts
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
