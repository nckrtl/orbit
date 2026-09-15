<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final class DependencyFilesProgram
{
    /** @var list<string> */
    public const array FILES = ['composer.json', 'composer.lock', 'package.json', 'npm-shrinkwrap.json', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb', 'yarn.lock', 'pnpm-workspace.yaml', '.yarnrc.yml', '.pnpmfile.cjs', 'pnpmfile.cjs', 'bunfig.toml', 'yarn.config.cjs'];

    public static function render(): string
    {
        return <<<'PYTHON'
import os, sys, stat, json, hashlib, base64
FILES = ['composer.json', 'composer.lock', 'package.json', 'npm-shrinkwrap.json', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb', 'yarn.lock', 'pnpm-workspace.yaml', '.yarnrc.yml', '.pnpmfile.cjs', 'pnpmfile.cjs', 'bunfig.toml', 'yarn.config.cjs']
CONTENT = set(FILES)
class Refusal(Exception):
    pass
def fail(code):
    raise Refusal('dependencies.' + code)
def signature(s):
    return [s.st_dev, s.st_ino, s.st_mode, s.st_size, s.st_mtime_ns, s.st_ctime_ns]
def directory(path):
    if not path.startswith('/') or path == '/' or os.path.normpath(path) != path or os.path.realpath(path) != path:
        fail('unsafe_source')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
    try:
        for component in path.split('/')[1:]:
            next_fd = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        return fd
    except BaseException:
        os.close(fd)
        raise

def source(environment, path):
    fd = directory(path)
    if environment == 'development':
        return path, None, fd, [os.fstat(fd).st_dev, os.fstat(fd).st_ino]
    try:
        try:
            pointer = os.stat('current', dir_fd=fd, follow_symlinks=False)
        except FileNotFoundError:
            fail('source_unavailable')
        if not stat.S_ISLNK(pointer.st_mode):
            fail('unsafe_source')
        target = os.readlink('current', dir_fd=fd)
        root = os.path.normpath(os.path.join(path, target))
        prefix = path + '/releases/'
        name = root.removeprefix(prefix)
        if not root.startswith(prefix) or '/' in name or not name or len(name) > 128 or not all(c.isascii() and (c.isalnum() or c in '._-') for c in name) or not name[0].isalnum():
            fail('unsafe_source')
        release_fd = directory(root)
        identity = [os.fstat(fd).st_dev, os.fstat(fd).st_ino, signature(pointer), target, os.fstat(release_fd).st_dev, os.fstat(release_fd).st_ino]
        return root, name, release_fd, identity
    finally:
        os.close(fd)

def read_file(fd, name):
    try:
        before = os.stat(name, dir_fd=fd, follow_symlinks=False)
    except FileNotFoundError:
        return {'content': None, 'hash': None, 'error': None, 'state': None}
    except OSError:
        return {'content': None, 'hash': None, 'error': 'dependencies.unreadable_source', 'state': None}
    entry = {'content': None, 'hash': None, 'error': None, 'state': signature(before)}
    if not stat.S_ISREG(before.st_mode):
        entry['error'] = 'dependencies.unsafe_source'
        return entry
    limit = 8 * 1024 * 1024 if name.endswith(('.lock', '.lockb')) or name in ('package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml') else 1024 * 1024
    if name in CONTENT and before.st_size > limit:
        entry['error'] = 'dependencies.source_too_large'
        return entry
    try:
        file_fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
        with os.fdopen(file_fd, 'rb') as handle:
            if signature(os.fstat(handle.fileno())) != signature(before):
                fail('source_changed')
            data = handle.read(limit + 1) if name in CONTENT else b''
            if signature(os.fstat(handle.fileno())) != signature(before):
                fail('source_changed')
        if len(data) > limit:
            fail('source_changed')
        if signature(os.stat(name, dir_fd=fd, follow_symlinks=False)) != signature(before):
            fail('source_changed')
        entry['content'] = base64.b64encode(data).decode('ascii')
        entry['hash'] = hashlib.sha256(data).hexdigest()
    except OSError:
        entry['error'] = 'dependencies.unreadable_source'
    return entry

try:
    environment, path = sys.argv[1:]
    if environment not in ('development', 'production'):
        fail('unsafe_source')
    root, reference, fd, identity = source(environment, path)
    try:
        files = {name: read_file(fd, name) for name in FILES}
        if sum(v['state'][3] for v in files.values() if v['hash'] is not None) > 32 * 1024 * 1024:
            fail('source_too_large')
        again = {name: read_file(fd, name) for name in FILES}
        if files != again:
            fail('source_changed')
        next_root, next_reference, next_fd, next_identity = source(environment, path)
        os.close(next_fd)
        if (root, reference, identity) != (next_root, next_reference, next_identity):
            fail('source_changed')
        digest = hashlib.sha256(json.dumps([identity, files], sort_keys=True).encode()).hexdigest()
        for entry in files.values():
            del entry['state']
        print(json.dumps({'root': root, 'reference': reference, 'identity': digest, 'files': files}))
    finally:
        os.close(fd)
except Refusal as error:
    print(json.dumps({'error': str(error)}))
except (OSError, ValueError):
    print(json.dumps({'error': 'dependencies.unreadable_source'}))
PYTHON;
    }
}
