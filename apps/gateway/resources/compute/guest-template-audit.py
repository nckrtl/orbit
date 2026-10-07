"""Read-only prerequisite and known-credential audit for an isolated image candidate."""
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys

TOKEN = re.compile(rb'(?:gh[pousr]_[A-Za-z0-9]{36,255}|github_pat_[A-Za-z0-9_]{60,255})')
SECRET_ENV = {b'GH_TOKEN', b'GITHUB_TOKEN', b'GH_ENTERPRISE_TOKEN', b'GITHUB_ENTERPRISE_TOKEN', b'COMPOSER_AUTH'}


def audit(roots=None, processes=Path('/proc'), prerequisites=True):
    if roots is None:
        roots = [Path('/home/orbit'), Path('/root'), Path('/etc')]
    if prerequisites:
        if os.geteuid() != 0:
            raise ValueError('Guest audit requires root')
        pwd.getpwnam('orbit')
        try:
            pwd.getpwnam('orbit-worker')
        except KeyError:
            pass
        else:
            raise ValueError('Shared worker account is present')
        for binary in ('orbit-agent', 'orbit-pi-server'):
            path = Path('/usr/local/bin') / binary
            details = path.lstat()
            if not stat.S_ISREG(details.st_mode) or details.st_uid != 0 or details.st_mode & 0o022 or not os.access(path, os.X_OK):
                raise ValueError('Image binary is unavailable')
        subprocess.run(['sudo', '-n', '-u', 'orbit', 'sudo', '-n', 'true'], capture_output=True, check=True, timeout=15)
        subprocess.run(['php', '-r', 'exit(extension_loaded("gd") && extension_loaded("pcov") && in_array("sqlite",PDO::getAvailableDrivers(),true) ? 0 : 1);'], capture_output=True, check=True, timeout=15)
        for path in (Path('/etc/systemd/system/orbit-sandbox-pi.service'), Path('/etc/systemd/system/orbit-sandbox-model.socket')):
            if path.exists() or path.is_symlink():
                raise ValueError('Task runtime is present')
    scanned = 0
    def fail_walk(error):
        raise error
    for root in roots:
        for relative in ('.config/gh', '.config/composer/auth.json', '.composer/auth.json', '.git-credentials', '.netrc', '.pi', '.codex', '.claude', '.gemini', '.orbit-sandbox-pi'):
            path = root
            for part in Path(relative).parts:
                path = path / part
                if path.is_symlink():
                    raise ValueError('Credential path is not local')
        for directory, dirs, files in os.walk(root, followlinks=False, onerror=fail_walk):
            base = Path(directory)
            if base.parent in roots and base.name in ('.orbit-sandbox-pi', '.pi', '.codex', '.claude', '.gemini') and any(base.iterdir()):
                raise ValueError('Agent state does not belong in a template')
            for name in files:
                path = base / name
                if name == 'orbit-sandbox-source.json':
                    raise ValueError('Task-owned source is present')
                if path.is_symlink():
                    continue
                details = path.stat()
                if not stat.S_ISREG(details.st_mode):
                    continue
                if (name in ('.git-credentials', '.netrc') or (name == 'hosts.yml' and base.name == 'gh')
                        or (name == 'auth.json' and base.name in ('composer', '.composer', '.pi', '.orbit-sandbox-pi'))):
                    if details.st_size:
                        raise ValueError('Credential file is present')
                if name == '.npmrc' and re.search(rb'(?:_authToken|_password|_auth)\s*=\s*\S', path.read_bytes()):
                    raise ValueError('Package credential is present')
                scanned += 1
                with path.open('rb') as source:
                    previous = b''
                    while chunk := source.read(1024 * 1024):
                        if TOKEN.search(previous + chunk):
                            raise ValueError('GitHub credential is present')
                        previous = chunk[-512:]
    for path in processes.glob('[0-9]*/environ'):
        try:
            data = path.read_bytes()
        except (FileNotFoundError, ProcessLookupError):
            continue
        if TOKEN.search(data) or any(key in SECRET_ENV and value for key, separator, value in
                                    (entry.partition(b'=') for entry in data.split(b'\0')) if separator):
            raise ValueError('Credential environment is present')
    return {'ready': True, 'scanned_files': scanned}


if __name__ == '__main__':
    try:
        print(json.dumps(audit()))
    except (ValueError, KeyError, OSError, subprocess.SubprocessError):
        print('Sandbox template guest audit failed.', file=sys.stderr)
        sys.exit(1)
