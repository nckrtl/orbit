#!/usr/bin/env bash
# Shared helpers for the NCK-111 proof fixtures. Sourced, never executed.
set -euo pipefail

gateway_address=10.44.0.1

fail() { echo "FAIL: $*" >&2; exit 1; }

# The certificate the running Caddy actually hands out, which is the only thing a user experiences.
# It is deliberately not read from disk: the whole defect was that every file on disk was correct
# while the process kept serving the certificate it had loaded before.
served_pem() {
  local pem
  pem=$(echo | openssl s_client -connect "${gateway_address}:443" -servername metrics.orbit 2>/dev/null \
        | openssl x509 2>/dev/null) || true
  [[ "$pem" == *"BEGIN CERTIFICATE"* ]] || fail "metrics.orbit presented no certificate"
  printf '%s\n' "$pem"
}

pem_serial() { printf '%s\n' "$1" | openssl x509 -noout -serial | cut -d= -f2; }

pem_is_expired() {
  if printf '%s\n' "$1" | openssl x509 -noout -checkend 0 >/dev/null 2>&1; then
    return 1
  fi
  return 0
}

pem_remaining_days() {
  local end
  end=$(printf '%s\n' "$1" | openssl x509 -noout -enddate | cut -d= -f2)
  echo $(( ( $(date -d "$end" +%s) - $(date +%s) ) / 86400 ))
}

published_leaf() { echo "$HOME/.orbit/ca/metrics-current/gateway.pem"; }

# Re-converge the existing Metrics role assignment. This is the idempotent convergence entry point;
# it never removes the assignment, so the certificate under test survives into the run.
converge_metrics() {
  orbit node:role:add app-dev metrics --converge --json >/dev/null \
    || fail "metrics convergence failed"
}

# A real client fetching https://metrics.orbit through the gateway, trusting only the Orbit root CA
# already installed as a system anchor. --resolve stands in for private DNS, which is not wired up
# on every node in the topology. Prints 000 when TLS or the connection fails.
https_status() {
  local code
  code=$(curl -sS -o /dev/null -w '%{http_code}' \
      --resolve "metrics.orbit:443:${gateway_address}" https://metrics.orbit/ 2>/dev/null) || true
  [[ -n "$code" ]] || code=000
  echo "$code"
}
