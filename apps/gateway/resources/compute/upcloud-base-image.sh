#!/bin/sh
# Prepares an UpCloud VM, created from the pinned public Ubuntu template on a 20 GB disk,
# to become the Orbit sandbox base template (ADR 0204). Run it as root.
#   install  installs the packages.
#   clean    removes the VM's identity; stop the VM and templatize its disk right after it.
# Without an argument it runs both. The nightly build warms the caches between the two.
set -eu
export DEBIAN_FRONTEND=noninteractive

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this script as root." >&2
    exit 1
fi

install_packages() {
    apt-get update -q
    apt-get dist-upgrade -y -q
    # Sandbox cloud-init prerequisites, every Node's bootstrap packages, the app-dev role packages, and ZFS.
    apt-get install -y -q \
        wireguard-tools git gh curl python3 acl ripgrep \
        ca-certificates gnupg libnss-resolve openssh-client sudo ufw wireguard \
        attr caddy composer docker.io openssl php-curl php-xml unzip \
        zfsutils-linux gdisk parted

    # A 2 GB sandbox runs PHP, Node, Docker, and an agent; keep the ZFS cache small.
    printf 'options zfs zfs_arc_max=134217728\n' > /etc/modprobe.d/orbit-zfs.conf

    if [ ! -e /swapfile ]; then
        fallocate -l 1G /swapfile
        chmod 600 /swapfile
        mkswap /swapfile
    fi
    grep -q '^/swapfile ' /etc/fstab || printf '/swapfile none swap sw 0 0\n' >> /etc/fstab
}

clean_identity() {
    if id orbit-worker >/dev/null 2>&1; then
        echo "The base template must not contain orbit-worker." >&2
        exit 1
    fi

    # Remove everything that identifies this VM or the person who built it, and the build's own files.
    apt-get clean
    rm -rf /var/tmp/orbit-warm /root/orbit-image-*.sh
    rm -f /etc/ssh/ssh_host_*
    rm -f /etc/hostid /var/lib/dbus/machine-id
    truncate -s 0 /etc/machine-id
    rm -f /root/.bash_history /home/*/.bash_history
    for keys in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys; do
        if [ -e "$keys" ]; then : > "$keys"; fi
    done
    cloud-init clean --logs --seed

    # Audit: the identity must be gone before the disk becomes a template.
    if ls /etc/ssh/ssh_host_* >/dev/null 2>&1 || [ -s /etc/machine-id ] || [ -e /etc/hostid ]; then
        echo "The VM identity is still present." >&2
        exit 1
    fi
    for keys in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys; do
        if [ -s "$keys" ]; then
            echo "An authorized key is still present." >&2
            exit 1
        fi
    done
    echo "orbit-image: clean"
}

case "${1:-all}" in
    install) install_packages ;;
    clean) clean_identity ;;
    all) install_packages; clean_identity ;;
    *) echo "Usage: $0 [install|clean]" >&2; exit 2 ;;
esac
