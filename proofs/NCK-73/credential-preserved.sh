#!/usr/bin/env bash
# The credential promoted before disable is the one returned after recovery.
source /var/lib/orbit-e2e/proof/lib.sh

expected=$(cat "$STATE/credential.sha256")
actual=$(orbit metrics:credentials --json | json_get password | tr -d '\n' | sha256sum | cut -d ' ' -f 1)
[[ "$actual" == "$expected" ]] || fail "credential changed across disable and recovery"
echo "credential-preserved: same password after disable and recovery"
