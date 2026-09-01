#!/usr/bin/env bash
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

ufw_numbered() { sudo /usr/sbin/ufw status numbered 2>/dev/null || true; }

ufw_shapes() {
  local numbered
  numbered=$(ufw_numbered)
  sed -n -E 's/^ *\[ *[0-9]+\] +//p' <<<"$numbered"
}

firewall_rule_exists() {
  local numbered
  numbered=$(ufw_numbered)
  grep -q "# $1\$" <<<"$numbered"
}

delete_firewall_rule() {
  local comment="$1"
  local numbered matching count number reread
  numbered=$(ufw_numbered)
  matching=$(awk -v suffix="# $comment" '
    length($0) >= length(suffix) && substr($0, length($0) - length(suffix) + 1) == suffix { print }
  ' <<<"$numbered")
  count=$(awk 'NF { count++ } END { print count + 0 }' <<<"$matching")
  [[ "$count" -le 1 ]] || fail "more than one firewall rule matches $comment"
  [[ "$count" -eq 1 ]] || return 0
  number=$(sed -E 's/^ *\[ *([0-9]+)\].*/\1/' <<<"$matching")
  reread=$(ufw_numbered)
  matching=$(sed -n -E "s/^ *\\[ *$number\\] +(.*)$/\\1/p" <<<"$reread")
  [[ "$matching" == *"# $comment" ]] || fail "firewall rule $number changed before cleanup"
  sudo /usr/sbin/ufw --force delete "$number" >/dev/null
}

capture_firewall_shapes() {
  local destination="$1"
  ufw_shapes | sort >"$destination"
}

assert_firewall_shapes() {
  local expected="$1"
  local current
  current=$(mktemp)
  capture_firewall_shapes "$current"
  diff -u "$expected" "$current" || fail "firewall state did not return to its exact baseline"
  rm -f -- "$current"
}

wireguard_address() {
  local addresses
  addresses=$(hostname -I | tr -s ' ' '\n')
  awk '/^10\.44\./ { print; exit }' <<<"$addresses"
}
