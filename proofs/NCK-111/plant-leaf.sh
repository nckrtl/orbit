#!/usr/bin/env bash
# Put the gateway into the state it reaches once the published metrics.orbit leaf runs out of life.
# Nothing renews these leaves in the background -- Caddy never touches a static `tls <cert> <key>`
# pair and Orbit has no scheduler -- so this is exactly what an operator finds ~397 days after
# enabling Metrics. The leaf is minted from the live private key and signed by the live root CA, so
# the validity window is the only thing wrong with it.
source /var/lib/orbit-e2e/proof/lib.sh

mode=${1:?usage: plant-leaf.sh <expired|near-expiry>}
ca="$HOME/.orbit/ca"
current="$ca/metrics-current"
[[ -f "$current/gateway.key" ]] || fail "no published metrics leaf at $current"

case "$mode" in
  # A 397 day window that closed on 2025-02-01: expired, yet still inside the 397 day ceiling, so
  # expiry is the only reason convergence can have for replacing it.
  expired)     validity=(-not_before 20240101000000Z -not_after 20250201000000Z) ;;
  # Ten days of life left: still trusted, but well inside the thirty day renewal margin.
  near-expiry) validity=(-days 10) ;;
  *) fail "unknown mode [$mode]" ;;
esac

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
cat > "$work/leaf.ext" <<EXT
[gateway]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:metrics.orbit,IP:${gateway_address}
EXT

openssl req -new -key "$current/gateway.key" -subj /CN=metrics.orbit -out "$work/leaf.csr" 2>/dev/null
openssl x509 -req -in "$work/leaf.csr" \
    -CA "$ca/root.pem" -CAkey "$ca/root.key" \
    -set_serial "0x$(openssl rand -hex 16)" \
    -out "$work/leaf.pem" -sha256 \
    -extfile "$work/leaf.ext" -extensions gateway \
    "${validity[@]}" 2>/dev/null

install -m 0644 -- "$work/leaf.pem" "$(published_leaf)"

# Publish it the way MetricsCertificatePublisher does and reload, so the gateway is genuinely
# serving this leaf rather than merely holding it on disk.
version="nck111$(openssl rand -hex 6)"
versions=/etc/caddy/orbit-metrics-cert-versions
sudo install -d -o root -g caddy -m 0750 -- "$versions/$version"
sudo install -o root -g caddy -m 0640 -- "$(published_leaf)" "$versions/$version/metrics.pem"
sudo install -o root -g caddy -m 0640 -- "$current/gateway.key" "$versions/$version/metrics.key"
sudo ln -s -- "$versions/$version" /etc/caddy/orbit-metrics-cert-current.candidate
sudo mv -fT -- /etc/caddy/orbit-metrics-cert-current.candidate /etc/caddy/orbit-metrics-cert-current
sudo systemctl reload-or-restart caddy

planted=$(openssl x509 -in "$(published_leaf)" -noout -serial | cut -d= -f2)
for _ in $(seq 1 20); do
  [[ "$(pem_serial "$(served_pem)")" == "$planted" ]] && break
  sleep 1
done
[[ "$(pem_serial "$(served_pem)")" == "$planted" ]] \
  || fail "the gateway is not serving the planted [$mode] leaf"

echo "plant-leaf: metrics.orbit serves the [$mode] leaf $planted, $(printf '%s\n' "$(served_pem)" | openssl x509 -noout -enddate)"
