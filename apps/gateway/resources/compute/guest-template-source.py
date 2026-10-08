"""Normalize an explicitly marked disposable source copy; never publish an image."""
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import uuid


def git(root, *args):
    environment = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    environment.update(GIT_CONFIG_GLOBAL='/dev/null', GIT_CONFIG_NOSYSTEM='1', GIT_TERMINAL_PROMPT='0',
                       GIT_OPTIONAL_LOCKS='0', GIT_NO_REPLACE_OBJECTS='1')
    return subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                           '-c', 'credential.helper=', '-C', str(root), *args],
                          env=environment, stdin=subprocess.DEVNULL, capture_output=True,
                          text=True, timeout=120, check=True).stdout.strip()


def read_marker(path, template):
    if (path.is_symlink() or not path.is_file() or path.stat().st_size > 8192
            or json.loads(path.read_text()) != template):
        raise ValueError('Template identity does not match')


def validate_metadata(metadata):
    # Check before invoking repository-aware Git: includes, fsmonitor and filters can execute code.
    for directory, dirs, files in os.walk(metadata, followlinks=False):
        for name in dirs + files:
            path = Path(directory) / name
            mode = path.lstat()
            if (path.is_symlink() or mode.st_uid != os.geteuid()
                    or not (stat.S_ISDIR(mode.st_mode) or stat.S_ISREG(mode.st_mode))
                    or (stat.S_ISREG(mode.st_mode) and mode.st_nlink != 1)):
                raise ValueError('Git metadata must be private and local')
    for name in ('commondir', 'gitdir', 'worktrees', 'shallow', 'info/grafts', 'objects/info/alternates',
                 'objects/info/http-alternates', 'orbit-sandbox-source.json', 'index.lock', 'config.lock'):
        if (metadata / name).exists():
            raise ValueError('Source is not an independent candidate')
    if not (metadata / 'config').is_file() or not (metadata / 'objects').is_dir():
        raise ValueError('Missing Git metadata')


def checked_configuration(root, template):
    raw = git(root, 'config', '--file', str(root / '.git/config'), '--no-includes', '--null', '--list')
    entries = [entry.split('\n', 1) for entry in raw.split('\0') if entry]
    allowed = {'core.repositoryformatversion', 'core.filemode', 'core.bare', 'core.logallrefupdates',
               'core.ignorecase', 'core.precomposeunicode', 'extensions.objectformat', 'user.name', 'user.email',
               'remote.origin.url', 'remote.origin.fetch'}
    values = {}
    for entry in entries:
        if len(entry) != 2:
            raise ValueError('Invalid Git configuration')
        key, value = entry
        if key not in allowed and not re.fullmatch(r'branch\.[^\n]+\.(remote|merge)', key):
            raise ValueError('Unsafe Git configuration')
        if key in values:
            raise ValueError('Ambiguous Git configuration')
        values[key] = value
    if values.get('core.bare') != 'false' or values.get('remote.origin.url') != template['repository']:
        raise ValueError('Unexpected repository')
    object_format = values.get('extensions.objectformat', 'sha1')
    if object_format not in ('sha1', 'sha256') or values.get('core.repositoryformatversion') != ('1' if object_format == 'sha256' else '0'):
        raise ValueError('Unsupported object format')
    return object_format


def prepare(request):
    if not isinstance(request, dict) or set(request) != {'checkout', 'source_template'}:
        raise ValueError('Invalid request')
    template = request['source_template']
    if (not isinstance(template, dict) or set(template) != {'id', 'repository', 'base', 'commit'}
            or not all(isinstance(value, str) for value in template.values())
            or str(uuid.UUID(template['id'])) != template['id']
            or not re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git', template['repository'])
            or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', template['commit'])
            or not template['base'] or template['base'].startswith('-')):
        raise ValueError('Invalid source template')
    root = Path(request['checkout'])
    if not root.is_absolute() or root.resolve() != root or not root.is_dir() or root.stat().st_uid != os.geteuid():
        raise ValueError('Candidate directory must belong to the managed user')
    metadata = root / '.git'
    if metadata.is_symlink() or not metadata.is_dir() or metadata.stat().st_uid != os.geteuid():
        raise ValueError('Candidate must have a local Git directory')
    descriptor = os.open(metadata, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        validate_metadata(metadata)
        read_marker(metadata / 'orbit-template-candidate.json', template)
        object_format = checked_configuration(root, template)
        base = template['base']
        git(root, 'check-ref-format', 'refs/heads/' + base)
        if (git(root, 'rev-parse', '--show-toplevel') != str(root)
                or git(root, 'rev-parse', '--verify', 'HEAD^{commit}') != template['commit']
                or git(root, 'status', '--porcelain', '--untracked-files=all')
                or git(root, 'for-each-ref', '--format=%(refname)', 'refs/replace')):
            raise ValueError('Candidate source is changed')
        marker = metadata / 'orbit-sandbox-template.json'
        refs = {'refs/heads/' + base, 'refs/remotes/origin/' + base, 'refs/remotes/origin/HEAD'}
        configuration = ('[core]\nrepositoryformatversion = ' + ('1' if object_format == 'sha256' else '0')
                         + '\nfilemode = true\nbare = false\nlogallrefupdates = true\n'
                         + ('[extensions]\nobjectformat = sha256\n' if object_format == 'sha256' else '')
                         + '[remote "origin"]\nurl = ' + template['repository'] + '\n')
        if marker.exists():
            read_marker(marker, template)
            if ((metadata / 'config').read_text() != configuration
                    or set(git(root, 'for-each-ref', '--format=%(refname)').splitlines()) != refs
                    or git(root, 'symbolic-ref', 'HEAD') != 'refs/heads/' + base
                    or git(root, 'symbolic-ref', 'refs/remotes/origin/HEAD') != 'refs/remotes/origin/' + base
                    or any(git(root, 'rev-parse', '--verify', ref + '^{commit}') != template['commit'] for ref in refs)):
                raise ValueError('Published source changed')
            return {'head': template['commit'], 'source_template': template}
        temporary = metadata / ('orbit-template-' + str(uuid.uuid4()))
        try:
            with temporary.open('x') as output:
                output.write(configuration)
            os.replace(temporary, metadata / 'config')
            git(root, 'checkout', '--quiet', '-B', base, template['commit'])
            for ref in git(root, 'for-each-ref', '--format=%(refname)').splitlines():
                if ref != 'refs/heads/' + base:
                    git(root, 'update-ref', '--no-deref', '-d', ref)
            git(root, 'update-ref', 'refs/remotes/origin/' + base, template['commit'])
            git(root, 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/' + base)
            with temporary.open('x') as output:
                json.dump(template, output)
            os.link(temporary, marker)
        finally:
            temporary.unlink(missing_ok=True)
        return {'head': template['commit'], 'source_template': template}
    finally:
        os.close(descriptor)


if __name__ == '__main__':
    try:
        print(json.dumps(prepare(json.loads(sys.stdin.buffer.read(65537)))))
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print('Sandbox template source preparation failed.', file=sys.stderr)
        sys.exit(1)
