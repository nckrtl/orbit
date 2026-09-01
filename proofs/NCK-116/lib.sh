#!/usr/bin/env bash
# Shared helpers for the NCK-116 proof fixtures. Sourced, never executed.
set -euo pipefail

readonly ESCAPE=/usr/local/sbin/orbit-metrics-uninstall
readonly EXPORTER_RULE_COMMENT=orbit:metrics-node-exporter
readonly ORB7_CLEANUP_ROOT=/var/lib/orbit-e2e/proof-cleanup

fail() { echo "FAIL: $*" >&2; exit 1; }

orb7_ufw_numbered() { sudo /usr/sbin/ufw status numbered 2>/dev/null || true; }

orb7_ufw_shapes() {
  sed -n -E 's/^ *\[ *[0-9]+\] +//p' <<<"$(orb7_ufw_numbered)"
}

orb7_arm() {
  local action="$1"
  local record="$ORB7_CLEANUP_ROOT/$action"
  sudo test ! -e "$record" || fail "cleanup record already exists: $record"
  sudo install -d -o root -g root -m 0700 -- "$record/paths" "$record/rules"
  orb7_ufw_shapes | sort | sudo tee "$record/ufw.before" >/dev/null
  sudo touch "$record/paths.tsv" "$record/rules.tsv"
  sudo chown root:root "$record/paths.tsv" "$record/rules.tsv"
  sudo chmod 0600 "$record/paths.tsv" "$record/rules.tsv"
  printf 'armed\n' | sudo tee "$record/state" >/dev/null
}

orb7_capture_path() {
  local action="$1"
  local label="$2"
  local path="$3"
  local record="$ORB7_CLEANUP_ROOT/$action"
  if sudo test -e "$path" || sudo test -L "$path"; then
    printf '%s\t%s\t1\n' "$label" "$path" | sudo tee -a "$record/paths.tsv" >/dev/null
    sudo tar --acls --xattrs --numeric-owner -C / -cpf "$record/paths/$label.tar" "${path#/}"
  else
    printf '%s\t%s\t0\n' "$label" "$path" | sudo tee -a "$record/paths.tsv" >/dev/null
  fi
}

orb7_record_container() {
  local action="$1"
  local kind="$2"
  local name="$3"
  local id="$4"
  printf '%s\t%s\t%s\n' "$kind" "$name" "$id" \
    | sudo tee -a "$ORB7_CLEANUP_ROOT/$action/docker.tsv" >/dev/null
}

orb7_capture_addresses() {
  local action="$1"
  sudo ip -4 -o addr show dev orbit | sudo tee "$ORB7_CLEANUP_ROOT/$action/addresses.before" >/dev/null
}

orb7_restore_addresses() {
  local action="$1"
  local record="$ORB7_CLEANUP_ROOT/$action"
  sudo test -f "$record/addresses.before" || return 0
  sudo ip -4 addr flush dev orbit
  local cidr
  while read -r _ _ _ cidr _; do
    [[ -n "$cidr" ]] && sudo ip addr add "$cidr" dev orbit
  done < <(sudo cat "$record/addresses.before")
}

orb7_record_ufw_delta() {
  local action="$1"
  local label="$2"
  local record="$ORB7_CLEANUP_ROOT/$action"
  local expected current delta
  expected=$(mktemp)
  current=$(mktemp)
  delta=$(mktemp)
  {
    sudo cat "$record/ufw.before"
    sudo cat "$record/rules.tsv"
  } | sort >"$expected"
  orb7_ufw_shapes | sort >"$current"
  comm -13 "$expected" "$current" >"$delta"
  [[ "$(wc -l <"$delta")" -eq 1 ]] || fail "UFW mutation did not create one exact owned delta"
  sudo install -o root -g root -m 0600 -- "$delta" "$record/rules/$label.shape"
  sudo tee -a "$record/rules.tsv" <"$delta" >/dev/null
  rm -f -- "$expected" "$current" "$delta"
}

orb7_mark_active() {
  local action="$1"
  printf 'active\n' | sudo tee "$ORB7_CLEANUP_ROOT/$action/state" >/dev/null
}

orb7_checkpoint() {
  local action="$1"
  if [[ "${ORBIT_E2E_ORB7_MODE:-}" == signal && "${ORBIT_E2E_ORB7_CASE:-}" == "$action" ]]; then
    printf 'ready\n' | sudo tee "$ORB7_CLEANUP_ROOT/$action/checkpoint" >/dev/null
    while true; do sleep 1; done
  fi
}

orb7_timeout_checkpoint() {
  local action="$1"
  if [[ "${ORBIT_E2E_ORB7_MODE:-}" == timeout && "${ORBIT_E2E_ORB7_CASE:-}" == "$action" ]]; then
    printf 'ready\n' | sudo tee "$ORB7_CLEANUP_ROOT/$action/timeout-checkpoint" >/dev/null
    while true; do sleep 1; done
  fi
}

orb7_restore_owned() {
  local action="$1"
  local record="$ORB7_CLEANUP_ROOT/$action"
  sudo test -e "$record" || return 0
  sudo mkdir "$record/restoring" 2>/dev/null || return 0

  local kind name id current_id
  if sudo test -f "$record/docker.tsv"; then
    while IFS=$'\t' read -r kind name id; do
      if [[ "$kind" == container ]]; then
        current_id=$(docker container inspect --format '{{.Id}}' "$name" 2>/dev/null || true)
        [[ -z "$current_id" || "$current_id" == "$id" ]] || return 1
        [[ -z "$current_id" ]] || docker container rm --force --volumes "$name" >/dev/null
      else
        current_id=$(docker volume inspect --format '{{.Name}}' "$name" 2>/dev/null || true)
        [[ -z "$current_id" || "$current_id" == "$id" ]] || return 1
        [[ -z "$current_id" ]] || docker volume rm "$name" >/dev/null
      fi
    done < <(tac < <(sudo cat "$record/docker.tsv"))
  fi

  local shape numbered matching number reread
  while IFS= read -r shape; do
    numbered=$(orb7_ufw_numbered)
    matching=$(awk -v shape="$shape" '
      { normalized=$0; sub(/^ *\[ *[0-9]+\] +/, "", normalized); if (normalized == shape) print $0 }
    ' <<<"$numbered")
    [[ "$(grep -c . <<<"$matching")" -le 1 ]] || return 1
    [[ -n "$matching" ]] || continue
    number=$(sed -E 's/^ *\[ *([0-9]+)\].*/\1/' <<<"$matching")
    reread=$(orb7_ufw_numbered | sed -n -E "s/^ *\\[ *$number\\] +//p")
    [[ "$reread" == "$shape" ]] || return 1
    sudo /usr/sbin/ufw --force delete "$number" >/dev/null
  done < <(sudo cat "$record/rules.tsv")

  if sudo test -f "$record/addresses.before"; then
    orb7_restore_addresses "$action"
  fi

  local label path existed
  while IFS=$'\t' read -r label path existed; do
    sudo rm -rf -- "$path"
    if [[ "$existed" -eq 1 ]]; then
      sudo tar --acls --xattrs --numeric-owner -C / -xpf "$record/paths/$label.tar"
    fi
  done < <(tac < <(sudo cat "$record/paths.tsv"))

  printf 'restored\n' | sudo tee "$record/state" >/dev/null
  sudo rm -rf -- "$record"
}

orb7_cleanup_exit() {
  local status="$1"
  local action="$2"
  trap - EXIT INT TERM
  local cleanup_status=0
  orb7_restore_owned "$action" || cleanup_status=$?
  if [[ "$status" -eq 0 && "$cleanup_status" -ne 0 ]]; then
    exit "$cleanup_status"
  fi
  exit "$status"
}

orb7_traps() {
  local action="$1"
  trap 'orb7_cleanup_exit "$?" '"'"$action"'"'' EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
}

# Extracts one JSON path (dot separated) from stdin with PHP; prints nothing when absent.
json_get() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    foreach (explode(".", $argv[1]) as $key) {
      if (!is_array($data) || !array_key_exists($key, $data)) { exit(0); }
      $data = $data[$key];
    }
    if (is_bool($data)) { echo $data ? "true" : "false"; }
    elseif (is_array($data)) { echo json_encode($data); }
    elseif ($data !== null) { echo (string) $data; }
  ' -- "$1"
}

this_address() {
  local addresses
  addresses=$(hostname -I | tr -s ' ' '\n')
  awk '/^10\.44\./ { print; exit }' <<<"$addresses"
}

# Runs the escape and prints its output; the exit code lands in ESCAPE_STATUS.
run_escape() {
  set +e
  ESCAPE_OUTPUT=$(sudo "$ESCAPE" "$@" 2>&1)
  ESCAPE_STATUS=$?
  set -e
  printf '%s\n' "$ESCAPE_OUTPUT"
}

container_exists() {
  local containers
  containers=$(docker container ls --all --format '{{.Names}}')
  grep -qx "$1" <<<"$containers"
}

volume_exists() {
  local volumes
  volumes=$(docker volume ls --format '{{.Name}}')
  grep -qx "$1" <<<"$volumes"
}

# Reads the numbering into a variable. `ufw status | grep -q` exits at the
# first match and leaves ufw writing into a closed pipe, which `pipefail` turns
# into a failure; the product script had exactly that bug.
firewall_status_text() { sudo ufw status numbered 2>/dev/null || true; }

# True when a UFW rule carries exactly this comment. The comment ends the line,
# so the match is anchored there and a look-alike neighbour cannot satisfy it.
firewall_rule_exists() { grep -q "# $1\$" <<<"$(firewall_status_text)"; }

# Absent is not an error: callers use this to tidy up rules that may already
# be gone, and a bare `grep` miss under `set -e` would abort them.
delete_firewall_rule() {
  local number
  number=$(grep "# $1\$" <<<"$(firewall_status_text)" | sed -E 's/^ *\[ *([0-9]+)\].*/\1/' | head -1 || true)
  [[ -n "$number" ]] || return 0
  sudo ufw --force delete "$number" >/dev/null
}

package_installed() {
  local status
  status=$(dpkg-query --show --showformat='${db:Status-Status}' "$1" 2>/dev/null || true)
  grep -qx installed <<<"$status"
}

assert_reports() {
  local needle="$1"
  [[ "$ESCAPE_OUTPUT" == *"$needle"* ]] || fail "escape output does not report [$needle]: $ESCAPE_OUTPUT"
}
