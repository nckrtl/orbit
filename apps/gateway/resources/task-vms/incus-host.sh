#!/usr/bin/env bash
# Prepares one Incus host for task VMs. Idempotent. Runs as root.
# Usage: incus-host.sh <project> <network> <cidr> <pool> <image-alias>
#   <network> is a bridge under the reserved `orbittask` prefix.
#   <cidr> is the bridge network, for example 10.251.77.0/24. The bridge owns its first usable address.
# Prints one JSON line {"ok":true} on success. Everything else goes to stderr.
set -euo pipefail

fail() {
    printf 'incus-host: %s\n' "$1" >&2
    exit 1
}

exists() {
    "$@" >/dev/null 2>&1
}

# The script arrives on stdin (`bash -s`). Bash reads all of main() before it runs it, so main can close
# stdin and no command can read the rest of the script.
main() {
    exec 3>&1 1>&2 </dev/null

    [ "$#" -eq 5 ] || fail 'usage: incus-host.sh <project> <network> <cidr> <pool> <image-alias>'

    local project=$1 network=$2 cidr=$3 pool=$4 image=$5

    [[ $project =~ ^[a-z][a-z0-9-]{0,62}$ ]] || fail "invalid project [$project]"
    [[ $network =~ ^orbittask[a-z0-9]{1,6}$ ]] || fail "invalid network [$network]"
    [[ $pool =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$ ]] || fail "invalid pool [$pool]"
    [[ $image =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$ ]] || fail "invalid image alias [$image]"
    [[ $cidr =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})/([0-9]{1,2})$ ]] ||
        fail "invalid cidr [$cidr]"

    local octets=("${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}" "${BASH_REMATCH[4]}")
    local prefix=${BASH_REMATCH[5]} numeric=0 octet
    for octet in "${octets[@]}"; do
        [ "$((10#$octet))" -le 255 ] || fail "invalid cidr [$cidr]"
        numeric=$(((numeric << 8) | 10#$octet))
    done
    { [ "$prefix" -ge 16 ] && [ "$prefix" -le 28 ]; } || fail "cidr prefix must be /16 to /28 [$cidr]"
    local mask=$(((0xFFFFFFFF << (32 - prefix)) & 0xFFFFFFFF))
    [ "$((numeric & mask))" -eq "$numeric" ] || fail "cidr is not a network address [$cidr]"
    local bridge=$((numeric + 1))
    local bridge_ip="$((bridge >> 24 & 255)).$((bridge >> 16 & 255)).$((bridge >> 8 & 255)).$((bridge & 255))"
    local acl="$network-egress"

    command -v incus >/dev/null || fail 'incus is not installed'
    command -v ufw >/dev/null || fail 'ufw is not installed'

    # Project: images and profiles are per project; networks and ACLs stay in `default`.
    if ! exists incus project show "$project"; then
        incus project create "$project" -c features.images=true -c features.profiles=true -c features.networks=false
    fi
    [ "$(incus project get "$project" features.networks)" != true ] || fail "project [$project] has its own networks"

    # Egress ACL. `edit` replaces the whole rule set, so repeated runs converge.
    exists incus network acl show "$acl" || incus network acl create "$acl"
    incus network acl edit "$acl" <<YAML
description: Orbit task VM boundary
egress:
- action: drop
  destination: 10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,169.254.0.0/16,100.64.0.0/10,224.0.0.0/4,240.0.0.0/4
  state: enabled
- action: allow
  state: enabled
ingress:
- action: allow
  source: $bridge_ip/32
  protocol: tcp
  destination_port: "22"
  state: enabled
YAML

    # Bridge with the ACL attached at creation, so no VM ever runs on it without the boundary.
    if ! exists incus network show "$network"; then
        incus network create "$network" --type=bridge \
            "ipv4.address=$bridge_ip/$prefix" ipv4.nat=true ipv6.address=none "security.acls=$acl" \
            security.acls.default.ingress.action=reject security.acls.default.egress.action=reject
    fi
    local current
    current=$(incus network get "$network" ipv4.address)
    [ "$current" = "$bridge_ip/$prefix" ] ||
        fail "network [$network] has address [$current], expected [$bridge_ip/$prefix]"
    incus network set "$network" ipv4.nat=true ipv6.address=none "security.acls=$acl" \
        security.acls.default.ingress.action=reject security.acls.default.egress.action=reject

    # Default profile of the project: root on the pool, NIC on the bridge with L2 port isolation.
    if exists incus profile device get default root pool --project "$project"; then
        incus profile device set default root pool="$pool" path=/ --project "$project"
    else
        incus profile device add default root disk pool="$pool" path=/ --project "$project"
    fi
    if exists incus profile device get default eth0 network --project "$project"; then
        incus profile device set default eth0 network="$network" security.port_isolation=true --project "$project"
    else
        incus profile device add default eth0 nic network="$network" name=eth0 security.port_isolation=true \
            --project "$project"
    fi

    # Stock Ubuntu cloud image, copied once.
    if ! exists incus image show "$image" --project "$project"; then
        incus image copy images:ubuntu/26.04/cloud local: --vm --alias "$image" --target-project "$project"
    fi

    # Routed traffic from every Orbit task bridge. Return traffic uses ufw's ESTABLISHED accept.
    ufw route allow in on 'orbittask+' comment 'orbit-task-vms'

    printf '{"ok":true}\n' >&3
}

main "$@"
