#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 2 && "$2" =~ ^[0-9a-f]{32}$ ]] || exit 64
action=$1
token=$2
unit=orbit-e2e-web.service
owner="orbit-e2e-web:$token"
state_dir=/home/orbit/.orbit/e2e-web-sessions
install -d -m 0700 "$state_dir"
# Serialize start and stop, including a guest command whose Incus response was lost.
exec 9>"$state_dir/lock"
flock -x 9
case "$action" in
  start)
    # Cleanup leaves a tombstone so a delayed start cannot outlive its reservation.
    [[ ! -e "$state_dir/$token.cancelled" ]] || { echo 'This web session has been cancelled.' >&2; exit 1; }
    systemd-run --quiet --collect --unit="$unit" --description="$owner" --uid=orbit --gid=orbit \
      --working-directory=/home/orbit --property=TimeoutStopSec=10 \
      -- env -i HOME=/home/orbit PATH=/home/orbit/.local/share/vite-plus/bin:/usr/local/bin:/usr/bin:/bin \
      bash /home/orbit/orbit/apps/e2e/resources/web-session.sh
    ;;
  stop)
    touch "$state_dir/$token.cancelled"
    inspect() {
      local status key value
      status=$(systemctl show "$unit" --property=LoadState --property=Description --property=ActiveState)
      load= description= active=
      while IFS='=' read -r key value; do
        case "$key" in
          LoadState) load=$value ;;
          Description) description=$value ;;
          ActiveState) active=$value ;;
        esac
      done <<<"$status"
      [[ -n "$load" && -n "$description" && -n "$active" ]] || { echo 'Cannot inspect the web unit identity.' >&2; return 1; }
    }
    inspect
    # A pre-existing unit is not ours. Cancellation still prevents this token starting later.
    if [[ "$load" == not-found || "$description" != "$owner" ]]; then exit 0; fi
    systemctl stop "$unit" || true
    inspect
    if [[ "$load" != not-found && "$description" == "$owner" && "$active" != inactive && "$active" != failed ]]; then
      echo 'The owned web unit has not stopped; keep its reservation for cleanup retry.' >&2
      exit 1
    fi
    ;;
  *) exit 64 ;;
esac
