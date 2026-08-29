#!/usr/bin/env bash
set -euo pipefail
umask 077
mode=${1-}
case "$mode" in
  ssh-pins)
    [[ $# -eq 3 && "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ && "$3" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]
    install -d -m 0700 /home/orbit/.ssh
    pins=$(mktemp)
    first_pins=$(mktemp)
    second_pins=$(mktemp)
    candidate=$(mktemp /home/orbit/.ssh/known_hosts.XXXXXX)
    trap 'rm -f "$pins" "$first_pins" "$second_pins" "$candidate"' EXIT
    deadline=$((SECONDS + 60))
    while :; do
      if ssh-keyscan -T 5 -H -- "$2" >"$first_pins" 2>/dev/null \
        && ssh-keyscan -T 5 -H -- "$3" >"$second_pins" 2>/dev/null \
        && grep -qv '^#' "$first_pins" \
        && grep -qv '^#' "$second_pins"; then
        cat "$first_pins" "$second_pins" >"$pins"
        break
      fi
      (( SECONDS >= deadline )) && exit 75
      sleep 1
    done
    cat "$pins" >"$candidate"
    chmod 0600 "$candidate"
    chown orbit:orbit "$candidate"
    mv -f "$candidate" /home/orbit/.ssh/known_hosts
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
