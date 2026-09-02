#!/usr/bin/env bash
# A second Metrics claim is refused by the CLI pre-flight and by the Gateway singleton claim.
source /var/lib/orbit-e2e/proof/lib.sh

cli=$(orbit metrics:enable app-prod --json || true)
grep -q '"code":"metrics.assignment_exists"' <<<"$cli" || fail "CLI accepted a second enable: $cli"

gateway=$(orbit node:role:add app-prod metrics --json || true)
grep -q '"code":"validation.failed"' <<<"$gateway" || fail "Gateway accepted a second metrics claim: $gateway"

roles=$(orbit node:list --json | php -r '
  foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] as $node) { if ($node["name"] === "app-prod") { echo implode(",", $node["roles"]); } }
')
[[ "$roles" != *metrics* ]] || fail "app-prod carries a metrics role: $roles"
echo "singleton: second claim refused (cli=metrics.assignment_exists, gateway=validation.failed, app-prod roles=$roles)"
