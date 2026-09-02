#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

mode=${1-}
label=${2-}
[[ "$label" =~ ^[a-z-]+$ ]] || fail "invalid authentication window label"
cursor_file="/tmp/nck121-$label.cursor"

start_window() {
  local cursor

  cursor=$(sudo journalctl -u ssh --no-pager -n 0 --show-cursor | sed -n 's/^-- cursor: //p')
  [[ -n "$cursor" ]] || fail "SSH journal cursor is missing"
  printf '%s\n' "$cursor" >"$cursor_file"
  echo "auth-window: started $label on $(hostname)"
}

read_window() {
  [[ -s "$cursor_file" ]] || fail "authentication cursor is missing for $label"
  sudo journalctl -u ssh --no-pager --after-cursor "$(cat "$cursor_file")"
}

assert_no_orbit_authentication() {
  local logs=$1

  ! grep -Fq "for orbit from $NCK121_AUTH_SOURCE" <<<"$logs" \
    || fail "orbit authentication was attempted in $label"
  ! grep -Fq "user orbit from $NCK121_AUTH_SOURCE" <<<"$logs" \
    || fail "orbit authentication was attempted in $label"
  ! grep -Fq "user orbit $NCK121_AUTH_SOURCE" <<<"$logs" \
    || fail "orbit authentication was attempted in $label"
}

accepted_identity() {
  local user=$1
  local logs count

  logs=$(read_window)
  count=$(grep -Fc "Accepted publickey for $user from $NCK121_AUTH_SOURCE" <<<"$logs" || true)
  [[ "$count" -gt 0 ]] || fail "no accepted SSH authentication for $user in $label"
  if [[ "$user" != orbit ]]; then
    assert_no_orbit_authentication "$logs"
  fi
  rm -f -- "$cursor_file"
  echo "auth-window: $label accepted $count connection(s) for $user on $(hostname), no fallback"
}

no_authentication() {
  local logs

  logs=$(read_window)
  ! grep -Fq "Accepted publickey" <<<"$logs" || fail "an SSH identity was accepted in $label"
  assert_no_orbit_authentication "$logs"
  rm -f -- "$cursor_file"
  echo "auth-window: $label accepted no SSH identity and no orbit fallback on $(hostname)"
}

rejected_identity() {
  local user=$1
  local logs

  logs=$(read_window)
  grep -Fq "$user" <<<"$logs" || fail "SSH did not record the rejected $user identity in $label"
  ! grep -Fq "Accepted publickey for $user" <<<"$logs" || fail "$user unexpectedly authenticated in $label"
  assert_no_orbit_authentication "$logs"
  rm -f -- "$cursor_file"
  echo "auth-window: $label recorded SSH rejection for $user and no orbit fallback on $(hostname)"
}

case "$mode" in
  start)
    [[ $# -eq 2 ]] || fail "start requires one label"
    start_window
    ;;
  accepted)
    [[ $# -eq 3 ]] || fail "accepted requires label and user"
    accepted_identity "$3"
    ;;
  none)
    [[ $# -eq 2 ]] || fail "none requires one label"
    no_authentication
    ;;
  rejected)
    [[ $# -eq 3 ]] || fail "rejected requires label and user"
    rejected_identity "$3"
    ;;
  *)
    fail "unknown authentication window mode [$mode]"
    ;;
esac
