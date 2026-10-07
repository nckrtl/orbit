"""Initialize only an owned task checkout. Remote objects arrive through the bundle broker."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import uuid


def git(root, *args):
    environment = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    environment.update({'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_TERMINAL_PROMPT': '0'})
    return subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                           '-c', 'credential.helper=', '-C', str(root), *args],
                          capture_output=True, stdin=subprocess.DEVNULL, env=environment, timeout=120, check=True).stdout.decode().strip()


def has_ref(root, ref):
    # rev-parse distinguishes an absent ref from a broken repository without consulting any remote.
    return ref in git(root, 'for-each-ref', '--format=%(refname)', ref).splitlines()


def prepare(request):
    if request['operation'] not in ('initialize', 'checkout', 'inspect'):
        raise ValueError('Invalid source operation')
    identity = request['sandbox_id']
    if str(uuid.UUID(identity)) != identity:
        raise ValueError('Invalid sandbox identity')
    repository = request['repository']
    branch, base = request['branch'], request['base']
    if not re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git', repository):
        raise ValueError('Invalid repository')
    if not re.fullmatch(r'task-[1-9][0-9]*', branch) or not isinstance(base, str) or not base or base.startswith('-'):
        raise ValueError('Invalid branch')
    root = Path(request['checkout'])
    if not root.is_absolute() or root.resolve() != root or not root.is_dir():
        raise ValueError('The checkout must be an existing real directory')
    if request['operation'] == 'initialize' and root.stat().st_uid != os.geteuid():
        # New Incus volumes may be root-only. Verify emptiness through a pinned directory
        # descriptor before changing ownership; never adopt a populated foreign checkout.
        claim = """import os,sys
fd=os.open(sys.argv[1],os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
try:
    if os.listdir(fd): raise ValueError('The new volume is not empty')
    os.fchown(fd,int(sys.argv[2]),int(sys.argv[3]))
finally:
    os.close(fd)
"""
        subprocess.run(['sudo', '-n', 'python3', '-I', '-c', claim, str(root), str(os.geteuid()), str(os.getegid())],
                       check=True, capture_output=True, timeout=15)
    metadata = root / '.git'
    marker = metadata / 'orbit-sandbox-source.json'
    owner = {'sandbox_id': identity, 'repository': repository, 'branch': branch}
    if request['operation'] == 'initialize' and not metadata.exists():
        if any(root.iterdir()):
            raise ValueError('Refusing a nonempty unowned checkout')
        metadata.mkdir(mode=0o700)
        with marker.open('x') as output:
            json.dump(owner, output)
    if metadata.is_symlink() or not metadata.is_dir() or marker.is_symlink() or not marker.is_file():
        raise ValueError('Source ownership is unavailable')
    recorded = json.loads(marker.read_text())
    if any(recorded.get(key) != value for key, value in owner.items()):
        raise ValueError('Source ownership does not match')
    if request['operation'] == 'initialize':
        git(root, 'init', '--quiet', '--initial-branch=' + branch)
        git(root, 'check-ref-format', 'refs/heads/' + base)
        existing = [line for line in git(root, 'config', '--local', '--list').splitlines() if line.startswith('remote.')]
        if existing and existing != ['remote.origin.url=' + repository]:
            raise ValueError('The checkout has unexpected remote configuration')
        git(root, 'config', '--local', 'remote.origin.url', repository)
        return {'initialized': True}
    git(root, 'check-ref-format', 'refs/heads/' + base)
    if not has_ref(root, 'refs/heads/' + branch):
        if request['operation'] == 'inspect' or git(root, 'status', '--porcelain'):
            raise ValueError('The checkout is not ready or contains uncommitted files')
        selected = 'refs/remotes/origin/' + branch
        if not has_ref(root, selected):
            selected = 'refs/remotes/origin/' + base
        commit = git(root, 'rev-parse', '--verify', selected + '^{commit}')
        git(root, 'checkout', '--quiet', '-b', branch, commit)
    if git(root, 'symbolic-ref', 'HEAD') != 'refs/heads/' + branch:
        raise ValueError('The checkout branch changed')
    head = git(root, 'rev-parse', '--verify', 'HEAD^{commit}')
    if 'starting_commit' not in recorded:
        recorded['starting_commit'] = head
        temporary = metadata / ('orbit-source-' + str(uuid.uuid4()))
        with temporary.open('x') as output:
            json.dump(recorded, output)
        os.replace(temporary, marker)
    if not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', recorded['starting_commit']):
        raise ValueError('Invalid recorded source commit')
    return {'head': head, 'starting_commit': recorded['starting_commit']}


if __name__ == '__main__':
    try:
        print(json.dumps(prepare(json.loads(sys.stdin.buffer.read(65537)))))
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print('Sandbox source preparation failed.', file=sys.stderr)
        sys.exit(1)
