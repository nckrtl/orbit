#!/usr/bin/env bash
set -euo pipefail
umask 077
state=/home/orbit/.orbit/e2e-sample-app-state.json
script=/usr/local/bin/converge-sample-app.sh
case ${1-} in
  production-bridge-current)
    observed=$($script inspect-state)
    php -r '$v=json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR); $keys=array_keys($v); $base=["shape","app_id","node_id","name","checkout_path","effective_root"]; if(!in_array($keys, [$base,[...$base,"production"]], true) || ($v["shape"] ?? null)!=="app_instances" || ($v["name"] ?? null)!=="e2e-dev") exit(1);' <<<"$observed"
    ;;
  production-bridge-repeat)
    before=$(sha256sum -- "$state" | cut -d ' ' -f 1)
    $script create-resources app-dev app-prod 5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0 >/dev/null
    after=$(sha256sum -- "$state" | cut -d ' ' -f 1)
    [[ "$before" == "$after" ]]
    $script inspect-state >/dev/null
    ;;
  *) exit 64 ;;
esac
