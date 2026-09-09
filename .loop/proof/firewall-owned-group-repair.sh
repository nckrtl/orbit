#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" != "--isolated" ]]; then
    exec unshare --net -- "$0" --isolated
fi

helper=/home/orbit/orbit/apps/e2e/resources/host/reconcile-firewall.py
network=oe-orb152
other_network=oe-other
shared_prefix_network=oe-orb152x
administrator_chain=ORB152-ADMIN

ensure() {
    printf '{"operation":"ensure","network":"%s","managed_interface_pattern":"oe+","owner":"orbit-e2e"}\n' "$network" | "$helper"
}

assert_changed() {
    local expected=$1
    local output
    output=$(ensure)
    [[ "$output" == "{\"changed\": $expected}" ]]
}

assert_preserved() {
    iptables -w 5 -C "$administrator_chain" -j RETURN
    iptables -w 5 -C FORWARD -j "$administrator_chain"
    iptables -w 5 -C FORWARD -i "$network" -o "$network" -m comment --comment "orbit-e2e:${other_network}:intra" -j ACCEPT
    iptables -w 5 -C FORWARD -i "$shared_prefix_network" -m comment --comment "orbit-e2e:${shared_prefix_network}:egress" -j ACCEPT
}

assert_desired_order() {
    mapfile -t forward_rules < <(iptables -w 5 -S FORWARD | sed -n '/^-A FORWARD /p')
    [[ "${forward_rules[0]//\"/}" == "-A FORWARD -i $network -o $network -m comment --comment orbit-e2e:$network:intra -j ACCEPT" ]]
    [[ "${forward_rules[1]//\"/}" == "-A FORWARD -i $network -o oe+ -m comment --comment orbit-e2e:$network:isolate -j DROP" ]]
    [[ "${forward_rules[2]//\"/}" == "-A FORWARD -i $network -m conntrack --ctstate NEW,RELATED,ESTABLISHED -m comment --comment orbit-e2e:$network:egress -j ACCEPT" ]]
    [[ "${forward_rules[3]//\"/}" == "-A FORWARD -o $network -m conntrack --ctstate RELATED,ESTABLISHED -m comment --comment orbit-e2e:$network:return -j ACCEPT" ]]
}

assert_repaired() {
    assert_desired_order
    assert_preserved
    assert_changed false
}

iptables -w 5 -N "$administrator_chain"
iptables -w 5 -A "$administrator_chain" -j RETURN
iptables -w 5 -A FORWARD -i "$network" -o "$network" -m comment --comment "orbit-e2e:${other_network}:intra" -j ACCEPT
iptables -w 5 -A FORWARD -i "$shared_prefix_network" -m comment --comment "orbit-e2e:${shared_prefix_network}:egress" -j ACCEPT
iptables -w 5 -A FORWARD -j "$administrator_chain"

assert_changed true
assert_repaired

iptables -w 5 -I FORWARD 2 -i "$network" -m comment --comment "orbit-e2e:${network}:stale" -j ACCEPT
assert_changed true
assert_repaired

iptables -w 5 -D FORWARD -i "$network" -o oe+ -m comment --comment "orbit-e2e:${network}:isolate" -j DROP
iptables -w 5 -I FORWARD 2 -i "$network" -o oe+ -m comment --comment "orbit-e2e:${network}:isolate" -j ACCEPT
assert_changed true
assert_repaired

iptables -w 5 -I FORWARD 1 -i "$network" -o "$network" -m comment --comment "orbit-e2e:${network}:intra" -j ACCEPT
assert_changed true
assert_repaired

iptables -w 5 -D FORWARD -i "$network" -o "$network" -m comment --comment "orbit-e2e:${network}:intra" -j ACCEPT
iptables -w 5 -I FORWARD 4 -i "$network" -o "$network" -m comment --comment "orbit-e2e:${network}:intra" -j ACCEPT
assert_changed true
assert_repaired

printf '%s\n' 'firewall owned group repair passed in an isolated network namespace'
