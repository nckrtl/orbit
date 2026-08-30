#!/usr/bin/env sh
# NCK-82 proof fixture. Staged by `prove` from apps/e2e/resources/proof/NCK-82/
# to /var/lib/orbit-e2e/proof/ on every role, including app-prod, which has no
# checkout. Runs as the orbit user and proves the staging contract on the role
# named by its first argument: it exits non-zero when any expectation fails.
set -eu

expected_role="${1:?usage: fixture-check.sh ROLE}"
directory=/var/lib/orbit-e2e/proof
self="$directory/fixture-check.sh"

user="$(id -un)"
if [ "$user" != orbit ]; then
  echo "fixture-check: runs as $user, not orbit" >&2
  exit 65
fi

host="$(hostname)"
case "$host" in
  *-"$expected_role") ;;
  *)
    echo "fixture-check: host $host is not role $expected_role" >&2
    exit 66
    ;;
esac

ownership="$(stat -c '%U:%G %a' "$self")"
if [ "$ownership" != 'root:root 755' ]; then
  echo "fixture-check: unexpected fixture ownership [$ownership]" >&2
  exit 67
fi

if [ -w "$self" ]; then
  echo 'fixture-check: fixture is writable by orbit' >&2
  exit 68
fi

if [ ! -f "$directory/plan.json" ]; then
  echo 'fixture-check: plan.json was not staged beside the fixture' >&2
  exit 69
fi

printf '%s: fixture staged root-owned at %s and executed as orbit\n' "$expected_role" "$self"
