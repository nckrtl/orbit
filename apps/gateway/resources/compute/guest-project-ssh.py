"""Install only the Gateway public bootstrap key in an owned Project guest."""
import base64
import fcntl
import json
import os
from pathlib import Path
import pwd
import re
import shlex
import stat
import subprocess
import sys
import tempfile
import time
import uuid


def align_clock(epoch, started, state=Path('/run/orbit-project-clock'),
                boot=Path('/proc/sys/kernel/random/boot_id'), clock=Path('/usr/bin/date'), owner=0):
    parent = state.parent
    details = parent.lstat()
    if (parent.resolve() != parent or not stat.S_ISDIR(details.st_mode)
            or details.st_uid != owner or details.st_mode & 0o022):
        raise ValueError('Unsafe clock receipt directory')
    descriptor = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        identity = boot.read_text().strip()
        if str(uuid.UUID(identity)) != identity:
            raise ValueError('Invalid guest boot identity')
        if os.path.lexists(state):
            details = state.lstat()
            if (not stat.S_ISREG(details.st_mode) or details.st_uid != owner or details.st_nlink != 1
                    or stat.S_IMODE(details.st_mode) != 0o600 or details.st_size != 37):
                raise ValueError('Unsafe clock receipt')
            previous = state.read_text()
            if previous != str(uuid.UUID(previous.strip())) + '\n':
                raise ValueError('Invalid clock receipt')
            if previous == identity + '\n':
                return
        link = clock.lstat()
        if link.st_uid != 0:
            raise ValueError('Unsafe clock program path')
        executable = clock.resolve(strict=True)
        details = executable.lstat()
        if (not stat.S_ISREG(details.st_mode) or details.st_uid != 0
                or details.st_mode & 0o022 or not os.access(clock, os.X_OK)):
            raise ValueError('Unsafe clock program')
        for clock_parent in {*clock.parents, *executable.parents}:
            details = clock_parent.lstat()
            if not stat.S_ISDIR(details.st_mode) or details.st_uid != 0 or details.st_mode & 0o022:
                raise ValueError('Unsafe clock program directory')
        elapsed = time.monotonic() - started
        if not 0 <= elapsed <= 60:
            raise ValueError('Clock request has expired')
        subprocess.run([str(clock), '--utc', '--set', '@' + format(epoch + elapsed, '.6f')],
                       stdin=subprocess.DEVNULL, capture_output=True, check=True, timeout=10)
        handle, candidate = tempfile.mkstemp(dir=parent, prefix='.orbit-project-clock-')
        try:
            with os.fdopen(handle, 'w') as output:
                output.write(identity + '\n')
                output.flush()
                os.fsync(output.fileno())
            os.replace(candidate, state)
        finally:
            if os.path.lexists(candidate):
                os.unlink(candidate)
    finally:
        os.close(descriptor)


def restore_recovery(port, ufw=Path('/usr/sbin/ufw')):
    if not os.path.lexists(ufw):
        return  # Native base-host bootstrap will install UFW before enabling it.
    details = ufw.lstat()
    if not stat.S_ISREG(details.st_mode) or details.st_uid != 0 or details.st_mode & 0o022:
        raise ValueError('Unsafe firewall program')
    comment = 'orbit:public-ssh-recovery'
    prefix = '10.233.' + str(port - 24000) + '.'
    legacy = ['ufw', 'allow', str(port) + '/tcp', 'comment', comment]
    desired = ['ufw', 'allow', 'from', prefix + '1', 'to', prefix + '10', 'port', '22', 'proto', 'tcp', 'comment', comment]

    def command(*args):
        result = subprocess.run([str(ufw), *args], stdin=subprocess.DEVNULL, text=True, capture_output=True,
                                env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C'}, timeout=10)
        if result.returncode or len(result.stdout) > 65536:
            raise ValueError('Firewall recovery failed')
        return result.stdout

    def owned():
        rows = [shlex.split(line) for line in command('show', 'added').splitlines() if comment in line]
        if any(row not in (legacy, desired) for row in rows) or any(rows.count(row) != 1 for row in rows):
            raise ValueError('Foreign recovery firewall rule')
        return rows

    rows = owned()
    if desired not in rows:
        command(*desired[1:])
    if legacy in rows:
        command('--force', 'delete', *legacy[1:])
    if owned() != [desired]:
        raise ValueError('Firewall recovery not confirmed')


def bootstrap(request, home, uid, gid):
    started = time.monotonic()
    if not isinstance(request, dict) or set(request) != {'public_key', 'recovery_port', 'gateway_time'}:
        raise ValueError('Invalid Project SSH request')
    timestamp = request['gateway_time']
    if (not isinstance(timestamp, str) or not re.fullmatch(r'[0-9]{10}\.[0-9]{6}', timestamp)
            or not 1262304000 <= float(timestamp) <= 4102444800):
        raise ValueError('Invalid Gateway UTC time')
    port = request['recovery_port']
    if port is not None and (type(port) is not int or not 24001 <= port <= 24254):
        raise ValueError('Invalid Project SSH proxy port')
    result = prepare({'public_key': request['public_key']}, home, uid, gid)
    if port is not None:
        restore_recovery(port)
    align_clock(float(timestamp), started)
    return result


def prepare(request, home, uid, gid):
    if not isinstance(request, dict) or set(request) != {'public_key'}:
        raise ValueError('Invalid bootstrap key request')
    value = request['public_key']
    if not isinstance(value, str) or len(value) > 512 or re.fullmatch(r'ssh-ed25519 [A-Za-z0-9+/]+={0,2}(?: [^\r\n]+)?', value) is None:
        raise ValueError('Invalid bootstrap key')
    key = ' '.join(value.split()[:2])
    raw = base64.b64decode(key.split()[1], validate=True)
    if len(raw) != 51 or raw[:19] != b'\x00\x00\x00\x0bssh-ed25519\x00\x00\x00\x20' or base64.b64encode(raw).decode() != key.split()[1]:
        raise ValueError('Invalid bootstrap key')
    for parent in (home, *home.parents):
        details = parent.lstat()
        if not stat.S_ISDIR(details.st_mode) or details.st_uid not in (0, uid) or details.st_mode & 0o022:
            raise ValueError('Unsafe bootstrap home')
    directory = home / '.ssh'
    if os.path.lexists(directory):
        details = directory.lstat()
        if not stat.S_ISDIR(details.st_mode) or details.st_uid != uid or details.st_mode & 0o077:
            raise ValueError('Unsafe bootstrap directory')
    path = directory / 'authorized_keys'
    if os.path.lexists(path):
        details = path.lstat()
        if not stat.S_ISREG(details.st_mode) or details.st_nlink != 1 or details.st_uid != uid or details.st_mode & 0o077 or details.st_size > 1024:
            raise ValueError('Unsafe bootstrap key file')
        lines = [line for line in path.read_text().splitlines() if line.strip()]
        if any(' '.join(line.split()[:2]) != key for line in lines):
            raise ValueError('Foreign bootstrap keys')
        if lines:
            return {'ready': True}
    else:
        directory.mkdir(mode=0o700, exist_ok=True)
    os.chown(directory, uid, gid)
    descriptor, name = tempfile.mkstemp(dir=directory, prefix='.orbit-bootstrap-')
    try:
        with os.fdopen(descriptor, 'w') as output:
            output.write(key + '\n')
            output.flush()
            os.fsync(output.fileno())
            os.fchown(output.fileno(), uid, gid)
        os.replace(name, path)
    finally:
        if os.path.lexists(name):
            os.unlink(name)
    return {'ready': True}


if __name__ == '__main__':
    try:
        account = pwd.getpwnam('orbit')
        if os.geteuid() != 0 or account.pw_dir != '/home/orbit':
            raise ValueError('Invalid bootstrap account')
        print(json.dumps(bootstrap(json.loads(sys.stdin.buffer.read(2049)), Path(account.pw_dir), account.pw_uid, account.pw_gid)))
    except Exception:
        print('Project SSH bootstrap refused.', file=sys.stderr)
        sys.exit(1)
