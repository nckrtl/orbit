"""Set permitted public upstreams only on an owned isolated test Gateway."""
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import sys
import uuid

ROOT_UID = 0
CHECKOUT = Path('/home/orbit/orbit')
DATABASE = Path('/home/orbit/.orbit/gateway.sqlite')
DIRECTORY = Path('/etc/dnsmasq.d')


def regular(path, uid):
    details = path.lstat()
    if (not stat.S_ISREG(details.st_mode) or details.st_uid != uid
            or details.st_nlink != 1 or details.st_mode & 0o022):
        raise ValueError('Unsafe file ownership')


def run(arguments):
    return subprocess.run(arguments, capture_output=True, text=True, check=True, timeout=30).stdout


def active():
    try:
        run(['systemctl', 'is-active', '--quiet', 'dnsmasq'])
        return True
    except subprocess.CalledProcessError as error:
        if error.returncode != 3:
            raise
        return False


def install(request):
    if (os.geteuid() != ROOT_UID or not isinstance(request, dict)
            or set(request) - {'source_template'} != {'operation', 'sandbox_id', 'checkout', 'repository', 'branch', 'base'}
            or request['operation'] != 'pair_dns'):
        raise ValueError('Invalid pair DNS request')
    identity = request['sandbox_id']
    if str(uuid.UUID(identity)) != identity or request['checkout'] != str(CHECKOUT):
        raise ValueError('Invalid source identity')
    uid = pwd.getpwnam('orbit').pw_uid
    if CHECKOUT.resolve() != CHECKOUT or not CHECKOUT.is_dir() or CHECKOUT.stat().st_uid != uid:
        raise ValueError('Foreign source directory')
    metadata = CHECKOUT / '.git'
    if metadata.is_symlink() or not metadata.is_dir() or metadata.stat().st_uid != uid:
        raise ValueError('Foreign Git directory')
    marker = metadata / 'orbit-sandbox-source.json'
    regular(marker, uid)
    if marker.stat().st_size > 8192:
        raise ValueError('Invalid source marker')
    owner = json.loads(marker.read_text())
    expected = {key: request[key] for key in ('sandbox_id', 'repository', 'branch', 'base')}
    expected['source_template'] = request.get('source_template')
    if not isinstance(owner, dict) or any(owner.get(key) != value for key, value in expected.items()):
        raise ValueError('Foreign source marker')
    regular(DATABASE, uid)
    addresses = json.loads(run(['ip', '-json', '-4', 'address', 'show']))
    owners = [row['ifname'] for row in addresses if any(address.get('local') == '10.44.0.1' for address in row.get('addr_info', []))]
    if owners != ['orbit']:
        raise ValueError('Not an isolated Gateway')
    if DIRECTORY.resolve() != DIRECTORY:
        raise ValueError('Unsafe DNS directory')
    details = DIRECTORY.stat()
    if not DIRECTORY.is_dir() or details.st_uid != ROOT_UID or details.st_mode & 0o022:
        raise ValueError('Unsafe DNS directory')
    target = DIRECTORY / 'orbit-sandbox-upstream.conf'
    content = '# Orbit sandbox ' + identity + '\nno-resolv\nserver=1.1.1.1\nserver=9.9.9.9\n'
    if target.exists() or target.is_symlink():
        regular(target, ROOT_UID)
        if target.read_text() != content:
            raise ValueError('Foreign upstream configuration')
        run(['dnsmasq', '--test'])
        active()
        return {'ready': True}
    was_active = active()
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
    created = os.fstat(descriptor)
    try:
        with os.fdopen(descriptor, 'w') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        run(['dnsmasq', '--test'])
        if was_active:
            run(['systemctl', 'restart', 'dnsmasq'])
            run(['systemctl', 'is-active', '--quiet', 'dnsmasq'])
    except BaseException:
        regular(target, ROOT_UID)
        details = target.stat()
        if (details.st_dev, details.st_ino) == (created.st_dev, created.st_ino):
            target.unlink()
            if was_active:
                try:
                    run(['systemctl', 'restart', 'dnsmasq'])
                except Exception:
                    pass
        raise
    return {'ready': True}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(16385)
        if len(raw) > 16384:
            raise ValueError('Oversized pair DNS request')
        print(json.dumps(install(json.loads(raw))))
    except Exception:
        print(json.dumps({'error': 'sandbox_pair_dns_failed'}))
        sys.exit(1)
