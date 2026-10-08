"""Bootstrap GitHub DNS only inside an owned Orbit source guest."""
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import sys
import uuid

ROOT_UID = 0
DIRECTORY = Path('/etc/systemd/resolved.conf.d')
CHECKOUT = Path('/home/orbit/orbit')
DOMAINS = ('github.com', 'githubusercontent.com', 'githubassets.com')


def regular(path, uid):
    details = path.lstat()
    if (not stat.S_ISREG(details.st_mode) or details.st_uid != uid
            or details.st_nlink != 1 or details.st_mode & 0o022):
        raise ValueError('Unsafe DNS ownership')


def control(*arguments):
    subprocess.run(['systemctl', *arguments], capture_output=True, timeout=30, check=True)


def install(request):
    if os.geteuid() != ROOT_UID or request.get('operation') != 'github_dns':
        raise ValueError('Invalid DNS bootstrap')
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
    content = ('# Orbit sandbox ' + identity + '\n[Resolve]\n'
               'DNS=1.1.1.1 9.9.9.9\nDomains=' + ' '.join('~' + domain for domain in DOMAINS) + '\n')
    if DIRECTORY.resolve() != DIRECTORY:
        raise ValueError('Unsafe resolver directory')
    parent = DIRECTORY if DIRECTORY.exists() else DIRECTORY.parent
    details = parent.stat()
    if not parent.is_dir() or details.st_uid != ROOT_UID or details.st_mode & 0o022:
        raise ValueError('Unsafe resolver directory')
    target = DIRECTORY / 'orbit-sandbox-github.conf'
    if target.exists() or target.is_symlink():
        regular(target, ROOT_UID)
        if target.read_text() != content:
            raise ValueError('Foreign resolver configuration')
        control('is-active', '--quiet', 'systemd-resolved')
        return {'ready': True}
    control('is-active', '--quiet', 'systemd-resolved')
    DIRECTORY.mkdir(mode=0o755, exist_ok=True)
    # Exclusive creation cannot overwrite an unrecorded file or link.
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
    created = os.fstat(descriptor)
    try:
        with os.fdopen(descriptor, 'w') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        control('restart', 'systemd-resolved')
        control('is-active', '--quiet', 'systemd-resolved')
    except BaseException:
        regular(target, ROOT_UID)
        details = target.stat()
        if (details.st_dev, details.st_ino) == (created.st_dev, created.st_ino):
            target.unlink()
            try:
                control('restart', 'systemd-resolved')
            except Exception:
                pass
        raise
    return {'ready': True}


if __name__ == '__main__':
    try:
        print(json.dumps(install(json.loads(sys.stdin.buffer.read(16384)))))
    except Exception:
        print(json.dumps({'error': 'sandbox_github_dns_failed'}))
        sys.exit(1)
