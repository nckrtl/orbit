#!/usr/bin/env bash
# An expired metrics.orbit leaf is re-issued on convergence, and the gateway actually serves the
# replacement. Before this change convergence reported success and rewrote every file on disk while
# Caddy kept handing out the certificate it had loaded earlier, so https://metrics.orbit stayed
# broken and re-converging never repaired it.
source /var/lib/orbit-e2e/proof/lib.sh

before_pem=$(served_pem)
before=$(pem_serial "$before_pem")
pem_is_expired "$before_pem" || fail "the gateway is not serving an expired leaf; setup did not take"
status=$(https_status)
[[ "$status" == 000 ]] || fail "https://metrics.orbit answered [$status] while serving an expired leaf"

converge_metrics

after_pem=$(served_pem)
after=$(pem_serial "$after_pem")
[[ "$after" != "$before" ]] || fail "the expired leaf [$before] is still being served after convergence"
pem_is_expired "$after_pem" && fail "the gateway is still serving an expired leaf after convergence"
status=$(https_status)
[[ "$status" == 302 ]] || fail "https://metrics.orbit returned [$status] after renewal, wanted 302"

echo "expired-leaf-reissued: $before -> $after, https://metrics.orbit answers $status again"
