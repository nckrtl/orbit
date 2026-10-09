#!/bin/sh
# Prepares an UpCloud VM, created from the pinned public Ubuntu template on a 20 GB disk,
# to become the Orbit sandbox base template (ADR 0204). Run it as root; it ends by removing
# the VM's identity, so stop the VM and templatize its disk right after it finishes.
set -eu
export DEBIAN_FRONTEND=noninteractive

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this script as root." >&2
    exit 1
fi

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

if id orbit-worker >/dev/null 2>&1; then
    echo "The base template must not contain orbit-worker." >&2
    exit 1
fi

# Remove everything that identifies this VM or the person who built it.
apt-get clean
rm -f /etc/ssh/ssh_host_*
rm -f /etc/hostid /var/lib/dbus/machine-id
truncate -s 0 /etc/machine-id
rm -f /root/.bash_history /home/*/.bash_history
for keys in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys; do
    [ -e "$keys" ] && : > "$keys"
done
cloud-init clean --logs --seed
