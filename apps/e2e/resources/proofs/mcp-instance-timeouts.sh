#!/usr/bin/env bash
# Reproduce MCP timeouts for instance-create and instance-destroy.
#
# Usage: bash apps/e2e/resources/proofs/mcp-instance-timeouts.sh
#
# Calls the Gateway MCP endpoint from the task topology's app-dev Node.
# The sample app's create finishes in well under a minute, so this proof
# adds setup and teardown steps that sleep longer than an MCP client's
# default wait. That is the same shape as a live create or destroy that
# runs past the client and still finishes on the Gateway. The sleep is
# disposable project configuration, not a change to product code.
#
# curl --max-time 60 models a client that stops waiting. It is not an MCP
# limit: the specification requires configurable timeouts, not 60 seconds.
# The Gateway command deadline is 570 seconds, and PHP-FPM and Caddy allow
# 600. A second identical call waits on the Instance environment lock, which
# gives up after 30 seconds.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
cd "$root"
topology=TASK-807
client_timeout=60
hold_seconds=80
step_timeout=180
stamp=$(date -u +%H%M%S)
slug="m807${stamp}"
project_id=
instance_id=
failures=0

guest_mcp=$(cat <<'EOS'
max_time=$1
body=$2
outfile=$3
resp="$outfile.resp"
err="$outfile.err"
meta="$outfile.meta"
code=0
curl -sS -m "$max_time" -o "$resp" -w 'http:%{http_code} time:%{time_total} bytes:%{size_download}' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  https://gateway.orbit/mcp \
  --data-binary "$body" >"$meta" 2>"$err" || code=$?
{
  printf '%s\n' __BODY_START__
  cat "$resp"
  printf '\n%s\n' __BODY_END__
  printf '__META__ exit=%s %s\n' "$code" "$(cat "$meta")"
  printf '%s\n' __ERR_START__
  cat "$err"
  printf '\n%s\n' __ERR_END__
} >"$outfile"
rm -f "$resp" "$err" "$meta"
EOS
)

on_node() {
  local node=$1 timeout=$2
  shift 2
  local label=()
  if [[ ${1:-} == rec:* ]]; then
    label=(--record="${1#rec:}")
    shift
  fi
  bin/e2e-topology exec "$topology" "$node" --timeout="$timeout" --argv="$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' "$@")" "${label[@]}"
}

json_value() {
  python3 -c 'import json,sys
raw=sys.stdin.read()
data=json.loads(raw[raw.find("{"):raw.rfind("}")+1])
path=sys.argv[1].split(".")
value=data
for part in path:
    value=value[int(part) if part.isdigit() else part]
print(value)' "$1"
}

fail() {
  echo "FAIL $*" >&2
  failures=$((failures + 1))
}

gateway_busy() {
  local json
  json=$(on_node gateway 30 orbit activity:list --limit=40 --json) || return 2
  NAME="$slug" ID="$instance_id" python3 -c '
import json, os, sys
raw = sys.stdin.read()
try:
    data = json.loads(raw[raw.find("{"):raw.rfind("}")+1])
except Exception:
    sys.exit(2)
name = os.environ.get("NAME", "")
ident = os.environ.get("ID", "")
for row in data.get("activities") or []:
    if row.get("status") != "running":
        continue
    incoming = (row.get("properties") or {}).get("input") or {}
    if not isinstance(incoming, dict):
        incoming = {}
    command = row.get("command")
    if command == "instance:create" and incoming.get("name") == name:
        sys.exit(0)
    if command == "instance:destroy" and ident and (
        str(incoming.get("instance") or "") == ident or str(row.get("subject_id") or "") == ident
        or (not incoming and row.get("subject_id") is None)
    ):
        sys.exit(0)
sys.exit(1)
' <<<"$json"
}

wait_for_gateway_idle() {
  local attempt state
  for attempt in $(seq 1 48); do
    gateway_busy
    state=$?
    if [[ $state -eq 1 ]]; then
      return 0
    fi
    sleep 5
  done
  return 1
}

instance_state() {
  local json
  json=$(on_node gateway 30 orbit instance:list --json) || { echo unknown; return; }
  NAME="$slug" ID="$instance_id" python3 -c '
import json, os, sys
raw = sys.stdin.read()
try:
    data = json.loads(raw[raw.find("{"):raw.rfind("}")+1])
except Exception:
    print("unknown")
    sys.exit(0)
name = os.environ.get("NAME", "")
ident = os.environ.get("ID", "")
rows = data.get("instances") or data.get("data") or []
for row in rows:
    if (name and row.get("name") == name) or (ident and str(row.get("id")) == ident):
        print("present")
        sys.exit(0)
print("absent")
' <<<"$json"
}

project_state() {
  local json
  json=$(on_node gateway 30 orbit project:list --json) || { echo unknown; return; }
  ID="$project_id" SLUG="$slug" python3 -c '
import json, os, sys
raw = sys.stdin.read()
try:
    data = json.loads(raw[raw.find("{"):raw.rfind("}")+1])
except Exception:
    print("unknown")
    sys.exit(0)
ident = os.environ.get("ID", "")
slug = os.environ.get("SLUG", "")
rows = data.get("projects") or data.get("data") or []
for row in rows:
    if (ident and str(row.get("id")) == ident) or (slug and row.get("slug") == slug):
        print("present")
        sys.exit(0)
print("absent")
' <<<"$json"
}

remove_instance() {
  local attempt json state
  state=$(instance_state)
  if [[ $state == absent ]]; then
    return 0
  fi
  if [[ $state == unknown ]]; then
    echo "CLEANUP cannot list instances before removal" >&2
    return 1
  fi
  if [[ -z $instance_id ]]; then
    echo "CLEANUP instance $slug is present without an id" >&2
    return 1
  fi
  for attempt in 1 2 3; do
    json=$(on_node gateway 220 orbit instance:destroy "$instance_id" --yes --json)
    if [[ $? -eq 0 ]]; then
      return 0
    fi
    if [[ $json == *env.operation_busy* ]]; then
      wait_for_gateway_idle || return 1
      continue
    fi
    if [[ $json == *http.404* ]]; then
      return 0
    fi
    echo "CLEANUP instance destroy failed: $json" >&2
    return 1
  done
  return 1
}

remove_guest_files() {
  local left
  on_node app-dev 20 sh -c "rm -f /tmp/${slug}-*" || return 1
  left=$(on_node app-dev 20 sh -c "find /tmp -maxdepth 1 -name '${slug}-*' -print") || return 1
  if [[ -n ${left//[[:space:]]/} ]]; then
    echo "CLEANUP temporary files remain: $left" >&2
    return 1
  fi
  return 0
}

cleanup() {
  local status=$?
  local cleanup_error=0
  local state
  set +e
  bin/e2e-topology kill "$topology" app-dev mcp-create >/dev/null 2>&1
  bin/e2e-topology kill "$topology" app-dev mcp-destroy >/dev/null 2>&1
  if ! wait_for_gateway_idle; then
    echo "CLEANUP gateway work did not finish" >&2
    cleanup_error=1
  fi
  if ! remove_instance; then
    cleanup_error=1
  fi
  if ! wait_for_gateway_idle; then
    echo "CLEANUP removal did not finish" >&2
    cleanup_error=1
  fi
  state=$(instance_state)
  if [[ $state == absent ]]; then
    echo "CLEANUP instance absent"
  else
    echo "CLEANUP instance state=$state" >&2
    cleanup_error=1
  fi
  if [[ -n $project_id ]]; then
    state=$(project_state)
    if [[ $state == present ]]; then
      if ! on_node gateway 60 orbit project:destroy "$project_id" --yes --json >/dev/null; then
        echo "CLEANUP project destroy failed" >&2
        cleanup_error=1
      fi
    elif [[ $state != absent ]]; then
      echo "CLEANUP cannot list projects" >&2
      cleanup_error=1
    fi
    state=$(project_state)
    if [[ $state == absent ]]; then
      echo "CLEANUP project absent"
    else
      echo "CLEANUP project state=$state" >&2
      cleanup_error=1
    fi
  fi
  if remove_guest_files; then
    echo "CLEANUP temporary files absent"
  else
    cleanup_error=1
  fi
  if [[ $failures -gt 0 || $cleanup_error -ne 0 ]]; then
    echo "MCP_TIMEOUT_PROOF failures=$failures cleanup_error=$cleanup_error" >&2
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT

print_report() {
  local label=$1
  LABEL="$label" python3 -c '
import os, re, sys
text = sys.stdin.read()
label = os.environ["LABEL"]
def section(start, end):
    try:
        return text.split(start, 1)[1].split(end, 1)[0]
    except IndexError:
        return ""
body = section("__BODY_START__\n", "\n__BODY_END__").strip()
err = section("__ERR_START__\n", "\n__ERR_END__").strip()
meta = re.search(r"__META__ exit=(\d+) http:(\S+) time:(\S+) bytes:(\S+)", text)
if meta is None:
    print(f"{label} UNPARSED")
    print(text)
    sys.exit(2)
print(f"{label} exit={meta.group(1)} http={meta.group(2)} time={meta.group(3)} bytes={meta.group(4)}")
if err:
    print(f"{label}_ERR {err}")
print(f"{label}_BODY")
print(body if body else "(empty)")
'
}

expect_timeout() {
  local label=$1
  local report=$2
  print_report "$label" <<<"$report"
  LABEL="$label" python3 -c '
import os, re, sys
text = sys.stdin.read()
meta = re.search(r"__META__ exit=(\d+) http:(\S+) time:(\S+) bytes:(\S+)", text)
label = os.environ["LABEL"]
if meta is None or meta.group(1) != "28" or float(meta.group(3)) < 59 or meta.group(4) != "0":
    sys.exit(f"{label} did not time out with an empty body at the client limit")
' <<<"$report" || fail "$label client timeout"
}

expect_code() {
  local label=$1
  local code=$2
  local report=$3
  print_report "$label" <<<"$report"
  LABEL="$label" CODE="$code" python3 -c '
import os, sys
text = sys.stdin.read()
code = os.environ["CODE"]
if code not in text:
    sys.exit(f"missing {code}")
' <<<"$report" || fail "$label missing $code"
}

expect_success_body() {
  local label=$1
  local needle=$2
  local report=$3
  print_report "$label" <<<"$report"
  LABEL="$label" NEEDLE="$needle" python3 -c '
import os, re, sys
text = sys.stdin.read()
meta = re.search(r"__META__ exit=(\d+) http:\S+ time:(\S+)", text)
body = text.split("__BODY_START__\n", 1)[1].split("\n__BODY_END__", 1)[0]
if meta is None or meta.group(1) != "0":
    sys.exit("curl failed")
if "isError\":true" in body or "isError\": true" in body:
    sys.exit("tool error")
if os.environ["NEEDLE"] not in body:
    sys.exit("missing needle")
if float(meta.group(2)) > 45:
    sys.exit("too slow for a finished retry")
' <<<"$report" || fail "$label finished retry"
}

activity_row() {
  local command=$1
  local json
  json=$(on_node gateway 30 orbit activity:list --command="$command" --limit=40 --json)
  COMMAND="$command" NAME="$slug" INSTANCE="$instance_id" python3 -c '
import json, os, sys
raw = sys.stdin.read()
data = json.loads(raw[raw.find("{"):raw.rfind("}")+1])
name = os.environ["NAME"]
instance = os.environ["INSTANCE"]
command = os.environ["COMMAND"]
skip = {"env.operation_busy", "http.404"}
chosen = []
for row in data["activities"]:
    if row.get("error_code") in skip:
        continue
    incoming = (row.get("properties") or {}).get("input") or {}
    if not isinstance(incoming, dict):
        incoming = {}
    named = incoming.get("name") == name
    same = bool(instance) and (str(incoming.get("instance")) == instance or str(row.get("subject_id")) == instance)
    running_destroy = command == "instance:destroy" and row.get("status") == "running"
    if named or same or running_destroy:
        chosen.append(row)
if not chosen:
    sys.exit(1)
running = [row for row in chosen if row.get("status") == "running"]
pool = running or chosen
pool.sort(key=lambda row: ((row.get("duration_ms") or 0), row.get("id") or 0), reverse=True)
print(json.dumps(pool[0]))
' <<<"$json"
}

print_activity() {
  local label=$1
  local row=$2
  LABEL="$label" python3 -c 'import json, os, sys
row = json.loads(sys.stdin.read())
print("%s id=%s status=%s duration_ms=%s error_code=%s subject_id=%s" % (
    os.environ["LABEL"], row.get("id"), row.get("status"), row.get("duration_ms"), row.get("error_code"), row.get("subject_id")))
' <<<"$row"
}

wait_activity_finished() {
  local command=$1
  local i row status
  for i in $(seq 1 60); do
    if row=$(activity_row "$command"); then
      status=$(python3 -c 'import json,sys; print(json.loads(sys.stdin.read()).get("status",""))' <<<"$row")
      if [[ $status != running ]]; then
        printf '%s\n' "$row"
        return 0
      fi
    fi
    sleep 3
  done
  echo "$command did not finish" >&2
  return 1
}

wait_instance() {
  local i json
  for i in $(seq 1 20); do
    json=$(on_node gateway 30 orbit instance:list --json)
    if instance_id=$(NAME="$slug" python3 -c '
import json, os, sys
raw = sys.stdin.read()
data = json.loads(raw[raw.find("{"):raw.rfind("}")+1])
rows = data.get("instances") or data.get("data") or []
for row in rows:
    if row.get("name") == os.environ["NAME"]:
        print(row["id"])
        sys.exit(0)
sys.exit(1)
' <<<"$json"); then
      echo "INSTANCE id=$instance_id name=$slug"
      return 0
    fi
    sleep 2
  done
  echo "instance $slug did not appear" >&2
  return 1
}

mcp_body() {
  python3 -c 'import json,sys; print(json.dumps({"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":sys.argv[1],"arguments":json.loads(sys.argv[2])}}))' "$1" "$2"
}

spawn_mcp() {
  local unit=$1 max=$2 body=$3 outfile=$4
  local argv
  argv=$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' sh -c "$guest_mcp" mcp "$max" "$body" "$outfile")
  bin/e2e-topology kill "$topology" app-dev "$unit" >/dev/null 2>&1 || true
  bin/e2e-topology spawn "$topology" app-dev "$unit" --argv="$argv"
}

exec_mcp() {
  local harness=$1 max=$2 body=$3 label=$4
  local outfile="/tmp/${slug}-call-${RANDOM}"
  on_node app-dev "$harness" sh -c "$guest_mcp" mcp "$max" "$body" "$outfile" >/dev/null
  on_node app-dev 20 "rec:$label" cat "$outfile"
}

wait_report() {
  local outfile=$1
  local label=$2
  local i
  for i in $(seq 1 80); do
    if on_node app-dev 20 sh -c "test -s '$outfile' && grep -q __META__ '$outfile'"; then
      on_node app-dev 20 "rec:$label" cat "$outfile"
      return 0
    fi
    sleep 2
  done
  echo "missing report $outfile" >&2
  return 1
}

echo "MCP_TIMEOUT_PROOF topology=$topology candidate=$(git rev-parse --short HEAD)"
echo "LIMITS client_timeout_s=$client_timeout hold_sleep_s=$hold_seconds gateway_command_timeout_s=570 php_fpm_and_caddy_s=600 env_lock_wait_s=30"

node_json=$(on_node gateway 30 orbit node:list --json)
node_id=$(python3 -c 'import json,sys
raw=sys.stdin.read()
data=json.loads(raw[raw.find("{"):raw.rfind("}")+1])
for node in data["nodes"]:
    if node["name"]=="app-dev":
        print(node["id"])
        break
' <<<"$node_json")
echo "NODE app-dev id=$node_id"

project_json=$(on_node gateway 90 rec:"proof project" orbit project:create "$slug" laravel-package https://github.com/github/gitignore.git --default-branch=main --apps='[{"name":"web","path":".","web_root":null,"type":"laravel-package"}]' --name="MCP timeout $stamp" --task-workspace-routed=false --json --no-interaction)
project_id=$(json_value id <<<"$project_json")
echo "PROJECT id=$project_id slug=$slug"
on_node gateway 30 orbit instance:setup-step:create hold --project="$project_id" --command="sleep $hold_seconds" --timeout="$step_timeout" --json >/dev/null
on_node gateway 30 orbit instance:teardown-step:create hold --project="$project_id" --command="sleep $hold_seconds" --timeout="$step_timeout" --json >/dev/null
echo "STEPS setup=sleep $hold_seconds teardown=sleep $hold_seconds"

create_body=$(mcp_body instance-create "{\"project_id\":$project_id,\"node_id\":$node_id,\"name\":\"$slug\"}")
create_out="/tmp/${slug}-create"
spawn_mcp mcp-create "$client_timeout" "$create_body" "$create_out"
echo "CREATE_SPAWNED client_timeout_s=$client_timeout"
wait_instance
sleep 3

create_while=$(exec_mcp 70 50 "$create_body" "create while running")
expect_code CREATE_WHILE_RUNNING env.operation_busy "$create_while"

if create_during=$(activity_row instance:create); then
  print_activity CREATE_GATEWAY_WHILE_CLIENT_CONNECTED "$create_during"
  python3 -c 'import json,sys; row=json.loads(sys.stdin.read()); sys.exit(0 if row.get("status")=="running" else 1)' <<<"$create_during" \
    || fail "create activity was not still running while the client waited"
else
  fail "create activity missing while the client waited"
fi

create_client=$(wait_report "$create_out" "create client report")
expect_timeout CREATE_CLIENT "$create_client"

if create_after_client=$(activity_row instance:create); then
  print_activity CREATE_GATEWAY_AFTER_CLIENT_TIMEOUT "$create_after_client"
fi
create_finished=$(wait_activity_finished instance:create)
print_activity CREATE_GATEWAY_FINISHED "$create_finished"
python3 -c '
import json, sys
row = json.loads(sys.stdin.read())
duration = row.get("duration_ms") or 0
if row.get("status") != "succeeded" or duration <= 60000:
    sys.exit(1)
' <<<"$create_finished" || fail "create did not finish on the gateway after the client timeout"

create_after=$(exec_mcp 70 "$client_timeout" "$create_body" "create after finished")
expect_success_body CREATE_AFTER_FINISHED "$slug" "$create_after"

destroy_body=$(mcp_body instance-destroy "{\"instance\":$instance_id}")
destroy_out="/tmp/${slug}-destroy"
spawn_mcp mcp-destroy "$client_timeout" "$destroy_body" "$destroy_out"
echo "DESTROY_SPAWNED client_timeout_s=$client_timeout"
sleep 10

destroy_while=$(exec_mcp 70 50 "$destroy_body" "destroy while running")
expect_code DESTROY_WHILE_RUNNING env.operation_busy "$destroy_while"

if destroy_during=$(activity_row instance:destroy); then
  print_activity DESTROY_GATEWAY_WHILE_CLIENT_CONNECTED "$destroy_during"
fi

destroy_client=$(wait_report "$destroy_out" "destroy client report")
expect_timeout DESTROY_CLIENT "$destroy_client"

if destroy_after_client=$(activity_row instance:destroy); then
  print_activity DESTROY_GATEWAY_AFTER_CLIENT_TIMEOUT "$destroy_after_client"
fi
destroy_finished=$(wait_activity_finished instance:destroy)
print_activity DESTROY_GATEWAY_FINISHED "$destroy_finished"
python3 -c '
import json, sys
row = json.loads(sys.stdin.read())
duration = row.get("duration_ms") or 0
if row.get("status") != "succeeded" or duration <= 60000:
    sys.exit(1)
' <<<"$destroy_finished" || fail "destroy did not finish on the gateway after the client timeout"

destroy_after=$(exec_mcp 40 30 "$destroy_body" "destroy after finished")
expect_code DESTROY_AFTER_FINISHED http.404 "$destroy_after"

echo "MCP_TIMEOUT_PROOF done failures=$failures"
