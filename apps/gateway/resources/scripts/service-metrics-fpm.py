"""Read production PHP-FPM pool state; the Gateway renderer owns pool.conf writes."""
import base64
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import sys


def read(path):
    for parent in [path, *path.parents]:
        if parent.is_symlink():
            raise ValueError('symlink in runtime path')
        stat = parent.stat()
        if stat.st_uid != 0 or stat.st_mode & 0o022:
            raise ValueError('runtime ownership mismatch')
    return path.read_text()


def main():
    request = json.loads(base64.b64decode(sys.argv[1]))
    if request.get('operation') != 'snapshot':
        raise ValueError('unsupported FPM monitoring operation')
    user, version = request['user'], request['version']
    if not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', user) or not re.fullmatch(r'8\.[0-9]+', version):
        raise ValueError('invalid runtime identity')
    root = Path('/etc/orbit/php-fpm') / user
    lock_path = Path('/run/lock/orbit') / ('production-php-' + user + '.lock')
    fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_SH)
        if read(root / 'orbit.identity') != request['marker']:
            raise ValueError('runtime identity mismatch')
        main_config = read(root / 'generated/php-fpm.conf')
        pool = read(root / 'generated/pool.conf')
        local = read(root / 'local.conf')
        fingerprint = hashlib.sha256((main_config + pool + local).encode()).hexdigest()
        print(json.dumps({'pool': pool, 'fingerprint': fingerprint}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('FPM monitoring inspection failed: ' + type(error).__name__, file=sys.stderr)
        sys.exit(1)
