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


WORKLOAD_ROLES = ('app-dev', 'app-prod', 'app-prod-2')


def tool_runtime_prerequisites(home=Path('/home/orbit'), system=Path('/')):
    vp = home / '.local/share/vite-plus'
    for binary in ('vp', 'node', 'pnpm', 'npm', 'npx'):
        path = system / 'usr/local/bin' / binary
        details = path.lstat()
        expected = '#!/bin/sh\nexport VP_HOME="' + str(vp) + '"\nexec "' + str(vp / 'bin' / binary) + '" "$@"\n'
        if (not stat.S_ISREG(details.st_mode) or details.st_uid != 0 or details.st_mode & 0o777 != 0o755
                or path.read_text() != expected or not os.access(vp / 'bin' / binary, os.X_OK)):
            raise ValueError('The image runtime is incompatible with native role convergence')
    bun = system / 'usr/local/bin/bun'
    if not bun.is_symlink() or os.readlink(bun) != str(system / 'opt/orbit/bun/bin/bun') or not os.access(bun, os.X_OK):
        raise ValueError('The image Bun entry point is incompatible with native role convergence')
    for binary in ('vp', 'node', 'pnpm', 'npm', 'npx', 'bun'):
        subprocess.run(['sudo', '-n', '-u', 'orbit', '-H', str(system / 'usr/local/bin' / binary), '--version'], capture_output=True, check=True, timeout=30, cwd=home)


def workload_prerequisites(home=Path('/home/orbit'), system=Path('/')):
    if home.resolve() != home or not home.is_dir():
        raise ValueError('Workload home is not local')
    for relative in ('.orbit/config.json', '.orbit/gateway.sqlite', '.orbit/ssh', '.orbit/ca',
                     '.orbit/sandbox-workload-identity', '.orbit/sandbox-workload.lock', '.ssh/authorized_keys'):
        path = home
        for part in Path(relative).parts:
            path = path / part
            if path.is_symlink():
                raise ValueError('Workload identity path is not local')
        if path.exists():
            raise ValueError('Workload image contains enrollment state')
    wireguard = system / 'etc/wireguard'
    agent = system / 'etc/orbit/agent'
    if wireguard.is_symlink() or agent.is_symlink() or any(wireguard.glob('*.conf')) or agent.exists():
        raise ValueError('Workload image contains fleet state')
    for package in ('docker.io', 'containerd', 'runc', 'openssh-server', 'wireguard-tools'):
        result = subprocess.run(['dpkg-query', '-W', '-f=${Status}', package], capture_output=True, text=True, check=True, timeout=15)
        if result.stdout.strip() != 'install ok installed':
            raise ValueError('Workload package prerequisite is unavailable')
    subprocess.run(['systemctl', 'is-active', '--quiet', 'ssh'], capture_output=True, check=True, timeout=15)
    subprocess.run(['systemctl', 'is-active', '--quiet', 'docker'], capture_output=True, check=True, timeout=15)


def audit(roots=None, processes=Path('/proc'), prerequisites=True, role=None):
    if role is not None and role not in WORKLOAD_ROLES:
        raise ValueError('Invalid workload audit role')
    if roots is None:
        roots = [Path('/home/orbit'), Path('/root'), Path('/etc')]
    if prerequisites:
        if os.geteuid() != 0:
            raise ValueError('Guest audit requires root')
        pwd.getpwnam('orbit')
        tool_runtime_prerequisites()
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
    if role is not None:
        workload_prerequisites()
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
    return {'ready': True, 'scanned_files': scanned, **({'role': role} if role is not None else {})}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(4097)
        if len(raw) > 4096:
            raise ValueError('Audit request is too large')
        request = json.loads(raw) if raw else {}
        if not isinstance(request, dict) or (request and set(request) != {'role'}):
            raise ValueError('Invalid audit request')
        if request and request['role'] not in WORKLOAD_ROLES:
            raise ValueError('Invalid workload audit role')
        print(json.dumps(audit(role=request.get('role'))))
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print('Sandbox template guest audit failed.', file=sys.stderr)
        sys.exit(1)
