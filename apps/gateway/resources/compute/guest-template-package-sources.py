"""Use TLS for standard Ubuntu archives in an owned cold candidate."""
import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile

ARCHIVE = re.compile(r'(?<!\S)http://(archive\.ubuntu\.com|security\.ubuntu\.com)/ubuntu(?=[/\s]|$)')


def secure_sources(root=Path('/etc/apt')):
    directories = [root, root / 'sources.list.d']
    for directory in directories:
        if directory.exists() or directory.is_symlink():
            metadata = directory.lstat()
            if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != os.geteuid() or metadata.st_mode & 0o022:
                raise ValueError('Unsafe package source directory')
    files = [root / 'sources.list']
    if directories[1].exists():
        files += sorted(path for path in directories[1].iterdir() if path.suffix in ('.list', '.sources'))
    changes = []
    for path in files:
        if not path.exists() and not path.is_symlink():
            continue
        metadata = path.lstat()
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid() or metadata.st_mode & 0o022 or metadata.st_size > 1024 * 1024:
            raise ValueError('Unsafe package source file')
        original = path.read_text()
        candidate = ''.join(line if line.lstrip().startswith('#') else ARCHIVE.sub(r'https://\1/ubuntu', line)
                            for line in original.splitlines(keepends=True))
        if candidate != original:
            changes.append((path, metadata, original, candidate))
    for path, metadata, original, candidate in changes:
        observed = path.lstat()
        fields = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns')
        if any(getattr(observed, field) != getattr(metadata, field) for field in fields) or path.read_text() != original:
            raise ValueError('Package source changed during preparation')
        temporary = None
        try:
            with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, prefix='.orbit-template-', delete=False) as output:
                temporary = Path(output.name)
                output.write(candidate)
                output.flush()
                os.fchown(output.fileno(), metadata.st_uid, metadata.st_gid)
                os.fchmod(output.fileno(), stat.S_IMODE(metadata.st_mode))
                os.fsync(output.fileno())
            os.replace(temporary, path)
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)
    return {'sources_https': True, 'changed_files': len(changes)}


if __name__ == '__main__':
    try:
        print(json.dumps(secure_sources()))
    except (ValueError, OSError, UnicodeError):
        print(json.dumps({'error': 'sandbox_template_package_sources_failed'}))
        sys.exit(1)
