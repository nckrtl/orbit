#!/usr/bin/env bash
# Removes the identity of a task VM base image builder, right before Incus publishes its disk. Runs as
# root inside the builder. Every VM from the image then gets its own SSH host keys, machine ID and DHCP
# address, and cloud-init runs again on its first boot. The builder never enrolled, so it holds no
# WireGuard key, agent secret or Gateway record. Package lists stay, so enrollment's apt-get update
# fetches only changes.
# Prints one JSON line {"ok":true} on success. Everything else goes to stderr.
set -euo pipefail

fail() {
    printf 'base-image-clean: %s\n' "$1" >&2
    exit 1
}

# The script arrives on stdin (`bash -s`). Bash reads all of main() before it runs it, so main can close
# stdin and no command can read the rest of the script.
main() {
    exec 3>&1 1>&2 </dev/null

    apt-get clean
    rm -f /etc/ssh/ssh_host_* /var/lib/systemd/random-seed /root/.bash_history /home/*/.bash_history
    local keys
    for keys in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys; do
        if [ -e "$keys" ]; then : >"$keys"; fi
    done
    # Running services keep their private temporary directories until the VM stops.
    find /tmp /var/tmp -mindepth 1 -maxdepth 1 ! -name 'systemd-private-*' -exec rm -rf -- {} +
    journalctl --rotate
    journalctl --vacuum-time=1s
    find /var/log -type f \( -name '*.gz' -o -name '*.[0-9]' \) -delete
    find /var/log -type f -exec truncate -s 0 {} +
    # Instance state, seed, logs and generated network and SSH configuration; machine-id becomes "uninitialized".
    cloud-init clean --logs --seed --machine-id --configs all

    # The identity must be gone before the disk becomes an image.
    if compgen -G '/etc/ssh/ssh_host_*' >/dev/null; then fail 'an SSH host key remains'; fi
    [ "$(cat /etc/machine-id)" = uninitialized ] || fail 'the machine ID remains'
    [ ! -e /var/lib/cloud/instance ] || fail 'the cloud-init instance state remains'
    for keys in /root/.ssh/authorized_keys /home/*/.ssh/authorized_keys; do
        if [ -s "$keys" ]; then fail "an authorized key remains in [$keys]"; fi
    done

    printf '{"ok":true}\n' >&3
}

main "$@"
