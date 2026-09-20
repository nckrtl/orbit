"""Change only Orbit's status directives in one recorded production master."""
import base64
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile


def read(path):
    for parent in [path, *path.parents]:
        if parent.is_symlink():
            raise ValueError('symlink in runtime path')
        stat = parent.stat()
        if stat.st_uid != 0 or stat.st_mode & 0o022:
            raise ValueError('runtime ownership mismatch')
    return path.read_text()


def write(path, text, mode=0o644):
    fd, name = tempfile.mkstemp(prefix='.metrics-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(text)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, mode)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def run(args, check=True):
    return subprocess.run(args, check=check, text=True, capture_output=True, timeout=30)


def main():
    request = json.loads(base64.b64decode(sys.argv[1]))
    user, version = request['user'], request['version']
    if not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', user) or not re.fullmatch(r'8\.[0-9]+', version):
        raise ValueError('invalid runtime identity')
    root = Path('/etc/orbit/php-fpm') / user
    pool = root / 'generated/pool.conf'
    main_config = root / 'generated/php-fpm.conf'
    service = 'orbit-' + user + '-php' + version + '-fpm.service'
    status = 'pm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/' + user + '.sock.status\n'
    lock_path = Path('/run/lock/orbit') / ('production-php-' + user + '.lock')
    fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if read(root / 'orbit.identity') != request['marker']:
            raise ValueError('runtime identity mismatch')
        journal = root / '.metrics-pool-recovery.json'
        if journal.exists() or journal.is_symlink():
            recovery = json.loads(read(journal))
            if read(pool) not in [recovery['before'], recovery['candidate']]:
                raise ValueError('pool changed during interrupted monitoring update')
            write(pool, recovery['before'])
            if run(['systemctl', 'is-active', '--quiet', service], False).returncode == 0:
                run(['systemctl', 'reload', service])
            journal.unlink()
        before = read(pool)
        def report():
            fingerprint = hashlib.sha256((read(main_config) + read(pool) + read(root / 'local.conf')).encode()).hexdigest()
            print(json.dumps({'pool': read(pool), 'fingerprint': fingerprint}))
        if request['operation'] == 'snapshot':
            report()
            return
        if request['operation'] == 'restore':
            candidate = request['state']['pool']
            if candidate.replace(status, '') != before.replace(status, ''):
                raise ValueError('pool changed after monitoring snapshot')
        else:
            candidate = before.replace(status, '')
            if request['enabled']:
                local = read(root / 'local.conf')
                if re.search(r'^\s*pm\.status_(?:path|listen)\s*=', candidate + '\n' + local, re.M | re.I):
                    raise ValueError('operator status configuration conflicts with monitoring')
                candidate += status
        if candidate == before:
            report()
            return
        with tempfile.TemporaryDirectory(prefix='.metrics-', dir=root) as directory:
            check_pool = Path(directory) / 'pool.conf'
            check_main = Path(directory) / 'php-fpm.conf'
            check_pool.write_text(candidate)
            main_text = read(main_config)
            include = 'include = ' + str(pool)
            if main_text.count(include + '\n') != 1:
                raise ValueError('unexpected generated pool include')
            check_main.write_text(main_text.replace(include + '\n', 'include = ' + str(check_pool) + '\n'))
            run(['/usr/sbin/php-fpm' + version, '--test', '--fpm-config', str(check_main)])
        active = run(['systemctl', 'is-active', '--quiet', service], False).returncode == 0
        write(journal, json.dumps({'before': before, 'candidate': candidate}), 0o600)
        write(pool, candidate)
        try:
            if active:
                run(['systemctl', 'reload', service])
                run(['systemctl', 'is-active', '--quiet', service])
        except Exception:
            write(pool, before)
            if active:
                run(['systemctl', 'reload', service])
            journal.unlink()
            raise
        journal.unlink()
        report()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('FPM monitoring operation failed: ' + type(error).__name__, file=sys.stderr)
        sys.exit(1)
