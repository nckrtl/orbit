"""Use TLS for standard Ubuntu archives in an owned cold candidate."""
import json
import os
from pathlib import Path
import re
import stat
import subprocess
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


DNS_CONTENT = '# Managed by Orbit sandbox image construction.\n[Resolve]\nDNS=1.1.1.1 9.9.9.9\nDomains=~.\n'


def public_resolver(directory=Path('/etc/systemd/resolved.conf.d')):
    parent = directory if directory.exists() else directory.parent
    metadata = parent.lstat()
    if (parent.resolve() != parent or not stat.S_ISDIR(metadata.st_mode)
            or metadata.st_uid != os.geteuid() or metadata.st_mode & 0o022):
        raise ValueError('Unsafe resolver directory')
    target = directory / 'orbit-sandbox-upstream.conf'
    if target.exists() or target.is_symlink():
        metadata = target.lstat()
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid()
                or metadata.st_nlink != 1 or metadata.st_mode & 0o022 or metadata.st_size > 8192
                or target.read_text() != DNS_CONTENT):
            raise ValueError('Foreign resolver configuration')
        subprocess.run(['systemctl', 'is-active', '--quiet', 'systemd-resolved'], capture_output=True, timeout=30, check=True)
        return {'public_dns': True}
    subprocess.run(['systemctl', 'is-active', '--quiet', 'systemd-resolved'], capture_output=True, timeout=30, check=True)
    directory.mkdir(mode=0o755, exist_ok=True)
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
    created = os.fstat(descriptor)
    try:
        with os.fdopen(descriptor, 'w') as output:
            output.write(DNS_CONTENT)
            output.flush()
            os.fsync(output.fileno())
        subprocess.run(['systemctl', 'restart', 'systemd-resolved'], capture_output=True, timeout=30, check=True)
        subprocess.run(['systemctl', 'is-active', '--quiet', 'systemd-resolved'], capture_output=True, timeout=30, check=True)
    except BaseException:
        metadata = target.lstat()
        if (metadata.st_dev, metadata.st_ino) == (created.st_dev, created.st_ino) and stat.S_ISREG(metadata.st_mode):
            target.unlink()
            try:
                subprocess.run(['systemctl', 'restart', 'systemd-resolved'], capture_output=True, timeout=30, check=True)
            except Exception:
                pass
        raise
    return {'public_dns': True}


if __name__ == '__main__':
    try:
        print(json.dumps({**secure_sources(), **public_resolver()}))
    except (ValueError, OSError, UnicodeError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_template_package_sources_failed'}))
        sys.exit(1)
