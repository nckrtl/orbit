#!/usr/bin/env bash
# NCK-85 proof fixture. Runs as the orbit user on a checkout role and proves
# that `orbit` resolves by name on the guest PATH to the bundled checkout's CLI
# entrypoint, and that `orbit doctor --json` answers with Doctor JSON. Doctor's
# own exit code is reported, not asserted: prepared-state drift is a Doctor
# finding, not a PATH failure.
set -euo pipefail

role="${1:?usage: orbit-on-path.sh ROLE}"
entrypoint=/home/orbit/orbit/apps/cli/orbit
link=/usr/local/bin/orbit

resolved="$(command -v orbit || true)"
if [[ "$resolved" != "$link" ]]; then
    echo "orbit-on-path: orbit resolves to [$resolved], not $link" >&2
    exit 65
fi
if [[ "$(readlink -f "$link")" != "$entrypoint" ]]; then
    echo "orbit-on-path: $link does not point at $entrypoint" >&2
    exit 66
fi

set +e
output="$(orbit doctor --json 2>/dev/null)"
doctor_exit=$?
set -e
if ! printf '%s' "$output" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert isinstance(d.get("healthy"), bool)' 2>/dev/null; then
    echo "orbit-on-path: orbit doctor --json did not print Doctor JSON (exit $doctor_exit)" >&2
    exit 67
fi

printf '%s: orbit resolves on the PATH to %s; doctor JSON received (doctor exit %s)\n' "$role" "$entrypoint" "$doctor_exit"
