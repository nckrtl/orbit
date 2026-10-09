#!/usr/bin/env bash
# Installs the static task VM filter on the WireGuard hub. Idempotent. Runs as root.
# Usage: hub.sh <range> <dns-ip> <gateway-ip> <pi-port> <reverb-ip:port> <model-ip:port> <router-ip>...
#   <range> is the reserved task VM WireGuard range. <dns-ip> is the VPN DNS address on this hub.
# Every rule that can end in `drop` matches a source or destination inside <range>.
# A packet with neither address in <range> leaves the table at its first IPv4 rule. An `accept` in this
# table only ends this table; ufw and every other table still see the packet, so the table can only
# remove traffic, and only traffic of the reserved range.
# Prints one JSON line {"ok":true} on success. Everything else goes to stderr.
set -euo pipefail

fail() {
    printf 'hub: %s\n' "$1" >&2
    exit 1
}

candidate=''
trap 'if [ -n "$candidate" ]; then rm -f -- "$candidate"; fi' EXIT

# The script arrives on stdin (`bash -s`). Bash reads all of main() before it runs it, so main can close
# stdin and no command can read the rest of the script.
main() {
    exec 3>&1 1>&2 </dev/null

    [ "$#" -ge 7 ] || fail 'usage: hub.sh <range> <dns-ip> <gateway-ip> <pi-port> <reverb-ip:port> <model-ip:port> <router-ip>...'

    local ipv4='(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])){3}'
    local port='([1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])'
    local range=$1 dns=$2 gateway=$3 pi_port=$4 reverb=$5 model=$6
    shift 6
    local routers=("$@") router

    [[ $range =~ ^$ipv4/(1[6-9]|2[0-9]|30)$ ]] || fail "invalid range [$range]"
    [[ $dns =~ ^$ipv4$ ]] || fail "invalid DNS address [$dns]"
    [[ $gateway =~ ^$ipv4$ ]] || fail "invalid gateway address [$gateway]"
    [[ $pi_port =~ ^$port$ ]] || fail "invalid Pi port [$pi_port]"
    [[ $reverb =~ ^$ipv4:$port$ ]] || fail "invalid Reverb endpoint [$reverb]"
    [[ $model =~ ^$ipv4:$port$ ]] || fail "invalid model proxy endpoint [$model]"
    for router in "${routers[@]}"; do
        [[ $router =~ ^$ipv4$ ]] || fail "invalid router address [$router]"
    done

    local numeric=0 octet octets
    IFS=. read -r -a octets <<<"${range%/*}"
    for octet in "${octets[@]}"; do
        numeric=$(((numeric << 8) | octet))
    done
    local mask=$(((0xFFFFFFFF << (32 - ${range#*/})) & 0xFFFFFFFF))
    [ "$((numeric & mask))" -eq "$numeric" ] || fail "range is not a network address [$range]"

    local nft
    nft=$(command -v nft) || fail 'nft is not installed'

    local directory=/etc/orbit/task-vms
    local rules="$directory/hub.nft"
    local unit_name=orbit-task-vms-hub.service
    local unit="/etc/systemd/system/$unit_name"
    local router_set
    router_set=$(IFS=,; printf '%s' "${routers[*]}")

    candidate=$(mktemp)

    # The first two commands make each load an atomic replace: create the table when it is absent, then
    # delete it and define it again in the same transaction.
    cat >"$candidate" <<NFT
# Managed by Orbit (task VMs). Filters only the reserved range $range.
table inet orbit_task_vms
delete table inet orbit_task_vms

table inet orbit_task_vms {
    chain forward {
        type filter hook forward priority -5; policy accept;
        meta nfproto != ipv4 accept
        ip saddr != $range ip daddr != $range accept
        ct state established,related accept
        ip saddr $range ip daddr $gateway tcp dport 443 accept
        ip saddr $range ip daddr ${reverb%:*} tcp dport ${reverb##*:} accept
        ip saddr $range ip daddr ${model%:*} tcp dport ${model##*:} accept
        ip saddr $gateway ip daddr $range tcp dport { 22, $pi_port } accept
        ip saddr { $router_set } ip daddr $range tcp dport { 80, 443, 5173 } accept
        ip saddr $range drop
        ip daddr $range drop
    }

    chain input {
        type filter hook input priority -5; policy accept;
        meta nfproto != ipv4 accept
        ip saddr != $range accept
        ct state established,related accept
        ip daddr $gateway tcp dport 443 accept
        ip daddr ${reverb%:*} tcp dport ${reverb##*:} accept
        ip daddr ${model%:*} tcp dport ${model##*:} accept
        ip daddr $dns udp dport 53 accept
        ip daddr $dns tcp dport 53 accept
        drop
    }
}
NFT

    "$nft" -c -f "$candidate"

    install -d -o root -g root -m 0755 "$directory"
    cmp -s -- "$candidate" "$rules" || install -o root -g root -m 0644 -- "$candidate" "$rules"

    # The unit loads the table before the tunnel starts, so the filter is in place whenever the hub
    # forwards. The tunnel pulls it in but does not require it, so a failed load never stops the fleet VPN.
    # It loads after nftables.service, whose boot-time `flush ruleset` would otherwise remove the table.
    local unit_text="[Unit]
Description=Orbit task VM filter on the WireGuard hub
DefaultDependencies=no
After=local-fs.target nftables.service
Before=wg-quick@orbit.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=$nft -f $rules

[Install]
WantedBy=wg-quick@orbit.service multi-user.target"

    if [ "$(cat "$unit" 2>/dev/null)" != "$unit_text" ]; then
        printf '%s\n' "$unit_text" >"$unit.tmp"
        chmod 0644 "$unit.tmp"
        mv -f -- "$unit.tmp" "$unit"
        systemctl daemon-reload
    fi
    systemctl enable --quiet "$unit_name"
    systemctl restart "$unit_name"
    "$nft" list table inet orbit_task_vms >/dev/null || fail 'table inet orbit_task_vms is not loaded'

    printf '{"ok":true}\n' >&3
}

main "$@"
