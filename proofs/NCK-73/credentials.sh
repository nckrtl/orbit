#!/usr/bin/env bash
# metrics:credentials returns a password that logs in; --reset rotates it; secrets never printed.
source /var/lib/orbit-e2e/proof/lib.sh

mkdir -p "$STATE"
before=$(orbit metrics:credentials --json)
[[ "$(echo "$before" | json_get username)" == admin ]] || fail "unexpected username"
password=$(echo "$before" | json_get password)
[[ ${#password} -ge 16 ]] || fail "password too short"

login=$(metrics_curl --user "admin:$password" https://metrics.orbit/api/user)
[[ "$(echo "$login" | json_get login)" == admin ]] || fail "active password rejected by Grafana"

datasources=$(metrics_curl --user "admin:$password" https://metrics.orbit/api/datasources)
[[ "$datasources" == *'"type":"prometheus"'* ]] || fail "Prometheus datasource missing: $datasources"
dashboards=$(metrics_curl --user "admin:$password" 'https://metrics.orbit/api/search?query=Orbit%20Node%20Resources')
[[ "$dashboards" == *'"title":"Orbit Node Resources"'* ]] || fail "dashboard missing: $dashboards"

reset=$(orbit metrics:credentials --reset --json)
rotated=$(echo "$reset" | json_get password)
[[ -n "$rotated" && "$rotated" != "$password" ]] || fail "reset did not rotate the password"
[[ "$(metrics_curl --user "admin:$rotated" https://metrics.orbit/api/user | json_get login)" == admin ]] || fail "rotated password rejected"
old=$(metrics_curl --output /dev/null --write-out '%{http_code}' --user "admin:$password" https://metrics.orbit/api/user)
[[ "$old" == 401 ]] || fail "old password still accepted: HTTP $old"

again=$(orbit metrics:credentials --json | json_get password)
[[ "$again" == "$rotated" ]] || fail "credentials read does not return the promoted password"
printf '%s' "$rotated" | sha256sum | cut -d ' ' -f 1 > "$STATE/credential.sha256"
echo "credentials: active login ok, datasource+dashboard provisioned, reset rotated and verified, old password 401"
