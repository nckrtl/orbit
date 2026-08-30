#!/usr/bin/env bash
# A leaf with plenty of life left is left exactly as it is. Renewal has to be driven by expiry, not
# by convergence running, or every converge would churn the key pair and reload Caddy for nothing.
source /var/lib/orbit-e2e/proof/lib.sh

before_pem=$(served_pem)
before=$(pem_serial "$before_pem")
pem_is_expired "$before_pem" && fail "expected a current leaf to be in place"

converge_metrics
converge_metrics

after_pem=$(served_pem)
after=$(pem_serial "$after_pem")
[[ "$after" == "$before" ]] || fail "a current leaf was re-issued: [$before] became [$after]"
status=$(https_status)
[[ "$status" == 302 ]] || fail "https://metrics.orbit returned [$status], wanted 302"

echo "current-leaf-untouched: $after survived two convergences, https://metrics.orbit answers $status"
