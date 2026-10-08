"""Verify pinned offline inputs without extracting files or changing Incus state."""
import hashlib
import json
import os
from pathlib import Path
import posixpath
import re
import stat
import sys
import tarfile

MAX_BYTES = 20 * 1024 ** 3
MAX_MEMBERS = 1_000_000
TOOL_FILES = {'usr/local/bin/bun', 'usr/local/bin/orbit-pi-server', 'usr/local/bin/orbit-agent'}
TOOL_TREES = ('opt/orbit-image/node', 'opt/orbit-image/vp', 'opt/orbit-image/pnpm')


def relative(value):
    if (not isinstance(value, str) or not value or value.startswith('/') or '\\' in value
            or any(ord(char) < 32 or ord(char) == 127 for char in value)
            or '..' in value.split('/')):
        raise ValueError('Invalid relative path')
    return posixpath.normpath(value)


def tool_path(value):
    return value in TOOL_FILES or any(value == tree or value.startswith(tree + '/') for tree in TOOL_TREES)


def inspect_archive(source, kind):
    if kind not in ('source', 'tools'):
        raise ValueError('Invalid archive kind')
    entries, explicit, links = {}, set(), {}
    report = {'members': 0, 'bytes': 0, 'symlinks': 0, 'hardlinks': 0}
    with tarfile.open(fileobj=source, mode='r|gz') as archive:
        for member in archive:
            report['members'] += 1
            report['bytes'] += member.size
            if report['members'] > MAX_MEMBERS or member.size < 0 or report['bytes'] > MAX_BYTES:
                raise ValueError('Archive limit exceeded')
            name = relative(member.name)
            if member.mode & 0o7000 or not (member.isdir() or member.isfile() or member.issym() or member.islnk()):
                raise ValueError('Unsafe archive entry')
            if name == '.':
                if not member.isdir() or name in explicit:
                    raise ValueError('Invalid archive root')
                explicit.add(name)
                continue
            if kind == 'tools' and not tool_path(name):
                raise ValueError('Unexpected tool path')
            if name in explicit or (name in entries and not member.isdir()):
                raise ValueError('Duplicate archive entry')
            parents = list(Path(name).parents)[:-1]
            for parent in parents:
                key = parent.as_posix()
                if entries.get(key, 'directory') != 'directory':
                    raise ValueError('Archive entry traverses a link or file')
                entries[key] = 'directory'
            if member.isdir():
                entries[name] = 'directory'
            elif member.isfile():
                entries[name] = 'file'
            else:
                link = member.linkname
                if not link or link.startswith('/') or '\\' in link or any(ord(char) < 32 or ord(char) == 127 for char in link):
                    raise ValueError('Unsafe archive link')
                target = posixpath.normpath(posixpath.join(posixpath.dirname(name), link) if member.issym() else relative(link))
                if target == '..' or target.startswith('../') or (kind == 'tools' and not tool_path(target)):
                    raise ValueError('Archive link escapes its root')
                for parent in list(Path(target).parents)[:-1]:
                    if member.islnk() and entries.get(parent.as_posix(), 'directory') != 'directory':
                        raise ValueError('Archive hard link traverses another link')
                if member.islnk():
                    if entries.get(target) != 'file':
                        raise ValueError('Hard link must refer to an earlier regular file')
                    entries[name] = 'file'
                    report['hardlinks'] += 1
                else:
                    entries[name] = 'symlink'
                    links[name] = link
                    report['symlinks'] += 1
            explicit.add(name)
    for name in links:
        target = resolve_link(name, links)
        if kind == 'tools' and not tool_path(target):
            raise ValueError('Tool link leaves the permitted trees')
    if not report['members']:
        raise ValueError('Empty archive')
    return report


def resolve_link(name, links):
    pending, resolved, expansions = name.split('/'), [], 0
    while pending:
        part = pending.pop(0)
        if part in ('', '.'):
            continue
        if part == '..':
            if not resolved:
                raise ValueError('Archive link escapes its root')
            resolved.pop()
            continue
        candidate = '/'.join([*resolved, part])
        if candidate in links:
            expansions += 1
            if expansions > 40:
                raise ValueError('Archive link cycle')
            pending = links[candidate].split('/') + pending
        else:
            resolved.append(part)
    return '/'.join(resolved) or '.'


def descriptor(value):
    if (not isinstance(value, dict) or set(value) != {'file', 'sha256'}
            or not isinstance(value['sha256'], str) or not re.fullmatch(r'[a-f0-9]{64}', value['sha256'])):
        raise ValueError('Invalid input descriptor')
    name = relative(value['file'])
    if name == '.' or name != value['file']:
        raise ValueError('Input path must be canonical')
    return name


def verify(request):
    if not isinstance(request, dict) or set(request) != {'root', 'packages', 'tools', 'source', 'composer'}:
        raise ValueError('Invalid input request')
    if not isinstance(request['root'], str):
        raise ValueError('Invalid input root')
    root = Path(request['root'])
    if not root.is_absolute() or root.resolve(strict=True) != root or not root.is_dir():
        raise ValueError('Input root must be an absolute local directory')
    packages = request['packages']
    if not isinstance(packages, list) or not 1 <= len(packages) <= 1000:
        raise ValueError('Invalid package set')
    items = [('package', item) for item in packages] + [(kind, request[kind]) for kind in ('tools', 'source', 'composer')]
    names = set()
    for kind, item in items:
        name = descriptor(item)
        if name in names or (kind == 'package' and (not name.endswith('.deb') or ':' in name)):
            raise ValueError('Invalid or duplicate input file')
        names.add(name)
    report = {'verified': True, 'packages': len(packages), 'inputs': []}
    for kind, item in items:
        path = root / item['file']
        if path.resolve(strict=True) != path:
            raise ValueError('Input must not traverse a symlink')
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        details = os.fstat(fd)
        if not stat.S_ISREG(details.st_mode) or not 0 < details.st_size <= MAX_BYTES:
            os.close(fd)
            raise ValueError('Input is not a bounded regular file')
        with os.fdopen(fd, 'rb') as source:
            digest = hashlib.file_digest(source, 'sha256').hexdigest()
            if digest != item['sha256']:
                raise ValueError('Input hash mismatch')
            result = {'kind': kind, 'sha256': digest, 'bytes': details.st_size}
            if kind in ('tools', 'source'):
                source.seek(0)
                result['archive'] = inspect_archive(source, kind)
                source.seek(0)
                if hashlib.file_digest(source, 'sha256').hexdigest() != digest:
                    raise ValueError('Input changed during validation')
            report['inputs'].append(result)
    return report


def main():
    if sys.argv[1:] != ['--check']:
        raise ValueError('Use --check')
    raw = sys.stdin.buffer.read(1024 * 1024 + 1)
    if len(raw) > 1024 * 1024:
        raise ValueError('Input request is too large')
    print(json.dumps(verify(json.loads(raw))))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, EOFError, RecursionError, tarfile.TarError):
        print(json.dumps({'error': 'sandbox_template_inputs_invalid'}))
        sys.exit(1)
