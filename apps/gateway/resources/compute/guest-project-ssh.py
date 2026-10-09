"""Install only the Gateway public bootstrap key in an owned Project guest."""
import base64
import json
import os
from pathlib import Path
import pwd
import re
import stat
import sys
import tempfile


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
        print(json.dumps(prepare(json.loads(sys.stdin.buffer.read(2049)), Path(account.pw_dir), account.pw_uid, account.pw_gid)))
    except Exception:
        print('Project SSH bootstrap refused.', file=sys.stderr)
        sys.exit(1)
