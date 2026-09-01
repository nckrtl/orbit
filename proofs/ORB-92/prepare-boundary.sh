#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

groups=" $(id -nG orbit) "
if [[ "$groups" == *' docker '* ]]; then
    sudo gpasswd -d orbit docker
fi

updated_groups=" $(id -nG orbit) "
[[ "$updated_groups" != *' docker '* ]] || fail "Docker group membership remains: $updated_groups"

stat -c '%U:%G:%a' /var/run/docker.sock >"$ORB_92_SOCKET_STATE"
sudo -n -l >"$ORB_92_SUDO_STATE"

echo 'prepare-boundary: removed Docker group membership and recorded socket and effective sudo state'
