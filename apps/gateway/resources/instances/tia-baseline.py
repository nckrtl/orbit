import base64
import fcntl
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile


def checked_directory(path):
    if not os.path.isabs(path) or os.path.realpath(path) != path:
        raise ValueError('invalid path')
    current = '/'
    for part in path.strip('/').split('/'):
        current = os.path.join(current, part)
        if os.path.lexists(current):
            info = os.lstat(current)
            if not stat.S_ISDIR(info.st_mode):
                raise ValueError('invalid directory')
        else:
            os.mkdir(current, 0o700)
    if os.stat(path).st_uid != os.getuid():
        raise ValueError('invalid owner')


stage = None
try:
    files = json.load(sys.stdin)
    if not isinstance(files, dict) or 'graph.json' not in files or set(files) - {'graph.json', 'js-module-graph.cache.json'}:
        raise ValueError('invalid files')
    checkout = os.getcwd()
    result = subprocess.run(['php', 'vendor/bin/pest', '--baseline'], cwd=checkout,
                            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                            stderr=subprocess.DEVNULL, timeout=15, check=True)
    if len(result.stdout) > 4096:
        raise ValueError('invalid cache path')
    target = result.stdout.decode().strip()
    home = os.path.realpath(os.path.expanduser('~'))
    if not any(target.startswith(root + '/') for root in (home, checkout)):
        raise ValueError('cache outside home and checkout')
    parent = os.path.dirname(target)
    checked_directory(parent)
    if os.path.realpath(target) != target:
        raise ValueError('invalid cache path')
    lock_path = os.path.join(parent, '.orbit-tia-' + hashlib.sha256(target.encode()).hexdigest() + '.lock')
    fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        if os.fstat(lock.fileno()).st_uid != os.getuid() or not stat.S_ISREG(os.fstat(lock.fileno()).st_mode):
            raise ValueError('invalid lock')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SystemExit(75)
        if os.path.lexists(target):
            checked_directory(target)
            if os.listdir(target):
                raise SystemExit(0)
        stage = tempfile.mkdtemp(prefix='.orbit-tia-stage-', dir=parent)
        total = 0
        for name, encoded in files.items():
            data = base64.b64decode(encoded, validate=True)
            total += len(data)
            if total > 4 * 1024 * 1024 or not isinstance(json.loads(data), dict):
                raise ValueError('invalid cache contents')
            with open(os.path.join(stage, name), 'xb') as output:
                os.chmod(output.name, 0o600)
                output.write(data)
                output.flush()
                os.fsync(output.fileno())
        # rename replaces an empty directory in one operation; a nonempty target fails safely.
        os.replace(stage, target)
        stage = None
except SystemExit:
    raise
except Exception:
    sys.stderr.write('TIA baseline installation failed.\n')
    raise SystemExit(1)
finally:
    if stage is not None:
        shutil.rmtree(stage)
