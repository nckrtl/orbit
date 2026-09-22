"""Prepare one Orbit task checkout before the scheduler starts an agent."""
import base64
import fcntl
import gzip
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile

PROJECTS = ('apps-cli', 'apps-docs', 'apps-gateway', 'apps-e2e', 'packages-php-sdk')
PUBLICATION_PATHS = {path for project in PROJECTS for path in (
    f'published/{project}.json', f'quality/{project}/pint.json', f'quality/{project}/phpstan.json')}


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args], stderr=subprocess.DEVNULL,
                                   text=True, timeout=10).strip()


def prepare(root, branch, commit, publications, timeout=840):
    os.umask(0o077)
    if not root.is_absolute() or root.resolve() != root or not (root / '.git').is_dir() or (root / '.git').is_symlink():
        raise ValueError('Setup requires the allocated independent checkout.')
    if git(root, 'rev-parse', '--absolute-git-dir') != str(root / '.git'):
        raise ValueError('Setup Git ownership differs.')
    if not branch or git(root, 'branch', '--show-current') != branch or git(root, 'rev-parse', 'HEAD') != commit:
        raise ValueError('Setup source identity differs.')
    if not (root / 'bin/bootstrap').is_file() or (root / 'bin/bootstrap').resolve() != root / 'bin/bootstrap':
        raise ValueError('Orbit bootstrap is unavailable.')
    state = root / '.git/orbit-task-setup'
    state.mkdir(mode=0o700, exist_ok=True)
    if state.resolve() != state:
        raise ValueError('Setup state traverses a symlink.')
    for name in ('setup.lock', 'bootstrap.log', 'runtime'):
        if (state / name).is_symlink():
            raise ValueError('Setup state contains a symlink.')
    with (state / 'setup.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='main-cache-', dir=state) as temporary:
            store = Path(temporary)
            if not isinstance(publications, dict) or set(publications) - PUBLICATION_PATHS:
                raise ValueError('Unexpected cache publication.')
            for relative, contents in publications.items():
                if not isinstance(contents, str) or len(contents.encode()) > 32_000_000:
                    raise ValueError('Invalid cache publication.')
                path = store / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(contents)
            environment = {name: value for name, value in os.environ.items()
                           if not name.startswith(('APP_', 'DB_', 'ORBIT_'))
                           and name not in ('DATABASE_URL', 'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER')}
            environment.update(ORBIT_HOME=str(state / 'runtime'), ORBIT_MAIN_CACHE_STORE=str(store),
                               COMPOSER_PROCESS_TIMEOUT='0')
            with (state / 'bootstrap.log').open('w') as log:
                child = subprocess.Popen([str(root / 'bin/bootstrap')], cwd=root, env=environment,
                                         stdin=subprocess.DEVNULL, stdout=log, stderr=log, start_new_session=True)
                try:
                    code = child.wait(timeout=timeout)
                finally:
                    for sig in (signal.SIGTERM, signal.SIGKILL):
                        try:
                            os.killpg(child.pid, sig)
                        except ProcessLookupError:
                            break
                    child.wait()
            if code != 0:
                raise ValueError('Bootstrap failed; inspect the private setup log.')
            if git(root, 'branch', '--show-current') != branch or git(root, 'rev-parse', 'HEAD') != commit:
                raise ValueError('Source identity changed during setup.')
    return {'prepared': True, 'checkout': str(root), 'commit': commit, 'cache_files': len(publications)}


if __name__ == '__main__':
    def interrupted(signum, frame):
        raise InterruptedError('Task setup interrupted.')
    for termination in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
        signal.signal(termination, interrupted)
    try:
        raw = gzip.decompress(base64.b64decode(PUBLICATIONS, validate=True))
        if len(raw) > 66_000_000:
            raise ValueError('Cache bundle is too large.')
        publications = json.loads(raw)
        if publications == []:
            publications = {}
        print(json.dumps(prepare(Path(sys.argv[1]), sys.argv[2], sys.argv[3], publications)))
    except Exception:
        print(json.dumps({'prepared': False, 'error': 'tasks.workspace_setup_failed'}))
        sys.exit(1)
