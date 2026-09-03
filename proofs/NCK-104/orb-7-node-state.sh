#!/usr/bin/env bash
set -euo pipefail

readonly ROOT=/var/lib/orbit-e2e/proof-cleanup

fail() { echo "FAIL: $*" >&2; exit 1; }

recover_record() {
  local action="$1"
  local record="$ROOT/$action"
  sudo test -e "$record" || return 0
  local state
  state=$(sudo cat "$record/state" 2>/dev/null || true)
  [[ "$state" =~ ^(arming|armed|active|published|restoring|restored)$ ]] \
    || fail "cleanup record has an unknown state: $record"
  restore "$action"
  sudo test ! -e "$record" || fail "cleanup record could not be recovered: $record"
}

arm_paths() {
  local action="$1"
  shift
  local record="$ROOT/$action"
  recover_record "$action"
  sudo test ! -e "$record" || fail "cleanup record already exists: $record"
  sudo install -d -o root -g root -m 0700 -- "$record/paths"
  printf 'arming\n' | sudo tee "$record/state" >/dev/null
  sudo install -o root -g root -m 0600 /dev/null "$record/paths.tsv"
  local index=0 path existed
  for path in "$@"; do
    index=$((index + 1))
    existed=0
    if sudo test -e "$path" || sudo test -L "$path"; then
      existed=1
      sudo tar --acls --xattrs --numeric-owner -C / -cpf "$record/paths/$index.tar.pending" "${path#/}"
      sudo mv -- "$record/paths/$index.tar.pending" "$record/paths/$index.tar"
    fi
    printf '%s\t%s\t%s\n' "$index" "$path" "$existed" | sudo tee -a "$record/paths.tsv" >/dev/null
  done
  printf 'armed\n' | sudo tee "$record/state" >/dev/null
}

arm_database() {
  local action="$1"
  local record="$ROOT/$action"
  recover_record "$action"
  sudo test ! -e "$record" || fail "cleanup record already exists: $record"
  sudo install -d -o root -g root -m 0700 -- "$record"
  printf 'arming\n' | sudo tee "$record/state" >/dev/null
  php -r '$d=new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite"); $d->exec("PRAGMA wal_checkpoint(FULL)");'
  sudo install -o root -g root -m 0600 -- /home/orbit/.orbit/gateway.sqlite "$record/gateway.sqlite.pending"
  sudo mv -- "$record/gateway.sqlite.pending" "$record/gateway.sqlite"
  printf 'armed\n' | sudo tee "$record/state" >/dev/null
}

restore_paths() {
  local record="$1"
  local index path existed
  while IFS=$'\t' read -r index path existed; do
    sudo rm -rf -- "$path"
    if [[ "$existed" -eq 1 ]]; then
      sudo tar --acls --xattrs --numeric-owner -C / -xpf "$record/paths/$index.tar"
    fi
  done < <(tac < <(sudo cat "$record/paths.tsv"))
}

restore_database() {
  local record="$1"
  local was_active=0
  systemctl is-active --quiet php8.5-fpm && was_active=1
  sudo systemctl stop php8.5-fpm
  sudo rm -f -- /home/orbit/.orbit/gateway.sqlite-wal /home/orbit/.orbit/gateway.sqlite-shm
  sudo install -o orbit -g orbit -m 0600 -- "$record/gateway.sqlite" /home/orbit/.orbit/gateway.sqlite
  if [[ "$was_active" -eq 1 ]]; then
    sudo systemctl reset-failed php8.5-fpm
    sudo systemctl start php8.5-fpm
  fi
}

restore() {
  local action="$1"
  local record="$ROOT/$action"
  sudo test -e "$record" || return 0
  sudo install -d -o root -g root -m 0700 -- "$record/restoring"
  printf 'restoring\n' | sudo tee "$record/state" >/dev/null
  sudo test ! -f "$record/paths.tsv" || restore_paths "$record"
  sudo test ! -f "$record/gateway.sqlite" || restore_database "$record"
  printf 'restored\n' | sudo tee "$record/state" >/dev/null
  sudo rm -rf -- "$record"
}

case "${1:-}" in
  arm-paths)
    shift
    arm_paths "$@"
    ;;
  arm-database)
    shift
    [[ $# -eq 1 ]]
    arm_database "$1"
    ;;
  restore)
    [[ $# -eq 2 ]]
    restore "$2"
    ;;
  discard)
    [[ $# -eq 2 ]]
    sudo rm -rf -- "$ROOT/$2"
    ;;
  mark)
    [[ $# -eq 3 ]]
    printf '%s\n' "$3" | sudo tee "$ROOT/$2/state" >/dev/null
    ;;
  *) exit 64 ;;
esac
