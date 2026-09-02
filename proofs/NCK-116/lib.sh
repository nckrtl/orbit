#!/usr/bin/env bash
# Shared helpers for the NCK-116 proof fixtures. Sourced, never executed.
set -euo pipefail

readonly ESCAPE=/usr/local/sbin/orbit-metrics-uninstall
readonly EXPORTER_RULE_COMMENT=orbit:metrics-node-exporter
readonly ORB7_CLEANUP_ROOT=/var/lib/orbit-e2e/proof-cleanup
readonly ORB7_TIMEOUT_BASELINE_RECORD=$ORB7_CLEANUP_ROOT/orb-7-timeout-baseline
readonly ORB7_TIMEOUT_SEED_ACTION=orb-7-timeout-seed
readonly ORB7_TIMEOUT_WITNESS=/var/tmp/orbit-e2e-orb7-timeout-witness

fail() { echo "FAIL: $*" >&2; exit 1; }

orb7_ufw_numbered() { sudo /usr/sbin/ufw status numbered 2>/dev/null || true; }

orb7_ufw_shapes() {
  sed -n -E 's/^ *\[ *[0-9]+\] +//p' <<<"$(orb7_ufw_numbered)"
}

orb7_arm() {
  local action="$1"
  local record="$ORB7_CLEANUP_ROOT/$action"
  sudo test ! -e "$record" || fail "cleanup record already exists: $record"
  sudo install -d -o root -g root -m 0700 -- "$record/paths"
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
    sudo tar --acls --xattrs --numeric-owner -C / \
      -cpf "$record/paths/$label.tar.pending" "${path#/}"
    sudo mv -- "$record/paths/$label.tar.pending" "$record/paths/$label.tar"
    printf '%s\t%s\t1\n' "$label" "$path" | sudo tee -a "$record/paths.tsv" >/dev/null
  else
    printf '%s\t%s\t0\n' "$label" "$path" | sudo tee -a "$record/paths.tsv" >/dev/null
  fi
}

orb7_record_docker_resource() {
  local action="$1"
  local kind="$2"
  local name="$3"
  [[ "$kind" =~ ^(container|volume)$ ]] || fail "unknown Docker resource kind: $kind"
  if [[ "$kind" == container ]]; then
    ! docker container inspect "$name" >/dev/null 2>&1 || fail "container already exists: $name"
  else
    ! docker volume inspect "$name" >/dev/null 2>&1 || fail "volume already exists: $name"
  fi
  printf '%s\t%s\n' "$kind" "$name" \
    | sudo tee -a "$ORB7_CLEANUP_ROOT/$action/docker.tsv" >/dev/null
}

orb7_capture_addresses() {
  local action="$1"
  local record="$ORB7_CLEANUP_ROOT/$action"
  sudo ip -4 -o addr show dev orbit | sudo tee "$record/addresses.before.pending" >/dev/null
  sudo mv -- "$record/addresses.before.pending" "$record/addresses.before"
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

orb7_record_ufw_rule() {
  local action="$1"
  local comment="$2"
  local record="$ORB7_CLEANUP_ROOT/$action"
  local matching
  matching=$(grep "# $comment\$" <<<"$(orb7_ufw_numbered)" || true)
  [[ -z "$matching" ]] || fail "UFW cleanup identity already exists: $comment"
  printf '%s\n' "$comment" | sudo tee -a "$record/rules.tsv" >/dev/null
}

orb7_mark_active() {
  local action="$1"
  printf 'active\n' | sudo tee "$ORB7_CLEANUP_ROOT/$action/state" >/dev/null
}

orb7_checkpoint() {
  local action="$1"
  local window="$2"
  if [[ "${ORBIT_E2E_ORB7_MODE:-}" == signal \
    && "${ORBIT_E2E_ORB7_CASE:-}" == "$action" \
    && "${ORBIT_E2E_ORB7_WINDOW:-}" == "$window" ]]; then
    printf 'ready\n' | sudo tee "${ORBIT_E2E_ORB7_CHECKPOINT:?}" >/dev/null
    if [[ "${ORBIT_E2E_ORB7_EVENT:-}" == EXIT ]]; then
      until sudo test -f "${ORBIT_E2E_ORB7_CHECKPOINT:?}.continue"; do sleep 0.1; done
      exit 0
    fi
    while true; do sleep 1; done
  fi
}

orb7_timeout_checkpoint() {
  local action="$1"
  if [[ "${ORBIT_E2E_ORB7_MODE:-}" == timeout && "${ORBIT_E2E_ORB7_CASE:-}" == "$action" ]]; then
    while true; do sleep 1; done
  fi
}

orb7_restore_owned() {
  local action="$1"
  local record="$ORB7_CLEANUP_ROOT/$action"
  sudo test -e "$record" || return 0
  sudo mkdir "$record/restoring" 2>/dev/null || return 0

  local kind name owner
  if sudo test -f "$record/docker.tsv"; then
    while IFS=$'\t' read -r kind name; do
      if [[ "$kind" == container ]]; then
        if docker container inspect "$name" >/dev/null 2>&1; then
          owner=$(docker container inspect \
            --format '{{ index .Config.Labels "com.orbit.e2e.cleanup" }}' "$name")
          [[ "$owner" == "$action" ]] || return 1
          docker container rm --force --volumes "$name" >/dev/null
        fi
      else
        if docker volume inspect "$name" >/dev/null 2>&1; then
          owner=$(docker volume inspect \
            --format '{{ index .Labels "com.orbit.e2e.cleanup" }}' "$name")
          [[ "$owner" == "$action" ]] || return 1
          docker volume rm "$name" >/dev/null
        fi
      fi
    done < <(tac < <(sudo cat "$record/docker.tsv"))
  fi

  local comment numbered matching number reread
  if sudo test -f "$record/rules.tsv"; then
    while IFS= read -r comment; do
      numbered=$(orb7_ufw_numbered)
      matching=$(grep "# $comment\$" <<<"$numbered" || true)
      [[ "$(grep -c . <<<"$matching")" -le 1 ]] || return 1
      [[ -n "$matching" ]] || continue
      number=$(sed -E 's/^ *\[ *([0-9]+)\].*/\1/' <<<"$matching")
      reread=$(orb7_ufw_numbered | sed -n -E "s/^ *\\[ *$number\\] +//p")
      [[ "$reread" == *"# $comment" ]] || return 1
      sudo /usr/sbin/ufw --force delete "$number" >/dev/null
    done < <(tac < <(sudo cat "$record/rules.tsv"))
  fi

  if sudo test -f "$record/addresses.before"; then
    orb7_restore_addresses "$action"
  fi

  local label path existed
  if sudo test -f "$record/paths.tsv"; then
    while IFS=$'\t' read -r label path existed; do
      sudo rm -rf -- "$path"
      if [[ "$existed" -eq 1 ]]; then
        sudo tar --acls --xattrs --numeric-owner -C / -xpf "$record/paths/$label.tar"
      fi
    done < <(tac < <(sudo cat "$record/paths.tsv"))
  fi

  printf 'restored\n' | sudo tee "$record/state" >/dev/null
  sudo rm -rf -- "$record"
}

orb7_cleanup_exit() {
  local status="$1"
  local action="$2"
  trap - EXIT INT TERM
  local cleanup_status=0
  orb7_restore_owned "$action" || cleanup_status=$?
  if [[ "$cleanup_status" -eq 0 && "${ORBIT_E2E_ORB7_MODE:-}" == timeout \
    && "${ORBIT_E2E_ORB7_CASE:-}" == "$action" ]]; then
    printf 'restored\n' | sudo tee -a "$ORB7_TIMEOUT_WITNESS" >/dev/null
  fi
  if [[ "$status" -eq 0 && "$cleanup_status" -ne 0 ]]; then
    exit "$cleanup_status"
  fi
  exit "$status"
}

orb7_term_exit() {
  local action="$1"
  if [[ "${ORBIT_E2E_ORB7_MODE:-}" == timeout && "${ORBIT_E2E_ORB7_CASE:-}" == "$action" ]]; then
    printf 'term\n' | sudo tee -a "$ORB7_TIMEOUT_WITNESS" >/dev/null
  fi
  exit 143
}

orb7_traps() {
  local action="$1"
  trap 'orb7_cleanup_exit "$?" '"'"$action"'"'' EXIT
  trap 'exit 130' INT
  trap 'orb7_term_exit '"'"$action"'"'' TERM
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

orb7_restore_timeout_seed() {
  if ! sudo test -e "$ORB7_TIMEOUT_BASELINE_RECORD"; then
    orb7_restore_owned "$ORB7_TIMEOUT_SEED_ACTION"
    return 0
  fi

  local before exporter_number address
  before=$(sudo cat "$ORB7_TIMEOUT_BASELINE_RECORD/ufw.before")
  exporter_number=$(sudo cat "$ORB7_TIMEOUT_BASELINE_RECORD/exporter.number" 2>/dev/null || true)
  orb7_restore_owned "$ORB7_TIMEOUT_SEED_ACTION"

  if [[ -n "$exporter_number" ]] && ! firewall_rule_exists "$EXPORTER_RULE_COMMENT"; then
    address=$(this_address)
    sudo /usr/sbin/ufw allow in on orbit proto tcp \
      from 10.44.0.2 to "$address" port 9100 comment "$EXPORTER_RULE_COMMENT" >/dev/null
  fi

  [[ "$(orb7_ufw_shapes)" == "$before" ]]
  sudo rm -rf -- "$ORB7_TIMEOUT_BASELINE_RECORD"
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
