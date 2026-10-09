"""Verify an immutable Caddy repository snapshot against the native signing pins."""
import datetime
import email.utils
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile


def command(*arguments):
    return subprocess.run(arguments, capture_output=True, text=True, check=True, timeout=30)


def fields(body):
    result = {}
    for line in body.splitlines():
        if not line or line.startswith((' ', '\t')):
            continue
        if ':' not in line:
            raise ValueError('Invalid repository field')
        name, value = line.split(':', 1)
        value = value.lstrip(' ')
        if name in result:
            raise ValueError('Repeated repository field')
        result[name] = value
    return result


def protected(path, owner, directory=False):
    details = path.lstat()
    expected = stat.S_ISDIR if directory else stat.S_ISREG
    if (not expected(details.st_mode) or details.st_uid != owner
            or stat.S_IMODE(details.st_mode) != (0o755 if directory else 0o644)
            or (not directory and details.st_nlink != 1)):
        raise ValueError('Snapshot ownership or mode differs')


def read(path, owner, limit):
    protected(path, owner)
    if path.stat().st_size > limit:
        raise ValueError('Snapshot file is too large')
    return path.read_bytes()


def verify(root, key_sha256, fingerprint, minimum, owner=0, architecture=None):
    if (root.resolve() != root or not root.is_absolute()
            or not re.fullmatch('[a-f0-9]{64}', key_sha256)
            or not re.fullmatch('[A-F0-9]{40}', fingerprint)
            or not re.fullmatch(r'[0-9]+(?:\.[0-9]+){2}', minimum)):
        raise ValueError('Invalid snapshot scope')
    protected(root, owner, directory=True)
    found = set()
    directory_count = 0
    for directory, directories, files in os.walk(root, followlinks=False):
        directory_count += 1
        if directory_count > 32 or len(found) + len(files) > 8:
            raise ValueError('Snapshot tree is too large')
        protected(Path(directory), owner, directory=True)
        for name in directories:
            protected(Path(directory) / name, owner, directory=True)
        for name in files:
            path = Path(directory) / name
            protected(path, owner)
            found.add(str(path.relative_to(root)))
    key = read(root / 'gpg.key', owner, 256 * 1024)
    if hashlib.sha256(key).hexdigest() != key_sha256:
        raise ValueError('Snapshot signing key differs')
    repository = root / 'repository'
    release_path = repository / 'dists/any-version/InRelease'
    architecture = architecture or command('dpkg', '--print-architecture').stdout.strip()
    if architecture not in ('amd64', 'arm64'):
        raise ValueError('Unsupported snapshot architecture')
    index_path = repository / ('dists/any-version/main/binary-' + architecture + '/Packages')
    read(release_path, owner, 256 * 1024)
    index = read(index_path, owner, 4 * 1024 * 1024)
    with tempfile.TemporaryDirectory(prefix='orbit-caddy-verify-') as temporary:
        home = Path(temporary)
        keyring = home / 'caddy.gpg'
        plaintext = home / 'Release'
        command('gpg', '--homedir', str(home), '--batch', '--dearmor', '--output', str(keyring), str(root / 'gpg.key'))
        result = command('gpgv', '--homedir', str(home), '--keyring', str(keyring), '--status-fd', '1',
                         '--output', str(plaintext), str(release_path))
        signatures = [line.split() for line in result.stdout.splitlines() if line.startswith('[GNUPG:] VALIDSIG ')]
        if (len(signatures) != 1 or len(signatures[0]) != 12 or signatures[0][-1] != fingerprint
                or signatures[0][9] not in ('8', '9', '10')
                or any(tag in result.stdout for tag in (' BADSIG ', ' EXPSIG ', ' EXPKEYSIG ', ' REVKEYSIG '))):
            raise ValueError('Snapshot signature differs')
        release = plaintext.read_text()
    metadata = fields(release)
    if (metadata.get('Origin') != 'cloudsmith/caddy/stable' or metadata.get('Suite') != 'any-version'
            or metadata.get('Codename') != 'any-version' or 'main' not in metadata.get('Components', '').split()
            or architecture not in metadata.get('Architectures', '').split()):
        raise ValueError('Snapshot repository identity differs')
    now = datetime.datetime.now(datetime.timezone.utc)
    issued = email.utils.parsedate_to_datetime(metadata['Date'])
    if issued.tzinfo is None or issued > now + datetime.timedelta(minutes=5):
        raise ValueError('Snapshot date is invalid')
    if 'Valid-Until' in metadata:
        expiry = email.utils.parsedate_to_datetime(metadata['Valid-Until'])
        if expiry.tzinfo is None or expiry <= now or expiry < issued:
            raise ValueError('Snapshot has expired')
    relative_index = 'main/binary-' + architecture + '/Packages'
    checksums = release.split('SHA256:\n', 1)
    if len(checksums) != 2:
        raise ValueError('Snapshot has no SHA-256 index')
    section = []
    for line in checksums[1].splitlines():
        if line and not line.startswith((' ', '\t')):
            break
        section.append(line.split())
    expected_index = [hashlib.sha256(index).hexdigest(), str(len(index)), relative_index]
    if [row for row in section if len(row) == 3 and row[2] == relative_index] != [expected_index]:
        raise ValueError('Snapshot package index differs')
    candidates = []
    for paragraph in index.decode().split('\n\n'):
        row = fields(paragraph)
        if row.get('Package') == 'caddy' and row.get('Architecture') == architecture:
            if not re.fullmatch('[0-9][A-Za-z0-9.+:~_-]{0,99}', row.get('Version', '')):
                raise ValueError('Invalid snapshot version')
            candidates.append(row)
    if not candidates:
        raise ValueError('Snapshot has no Caddy candidate')
    if len({row['Version'] for row in candidates}) != len(candidates):
        raise ValueError('Snapshot has ambiguous Caddy candidates')
    candidate = candidates[0]
    for row in candidates[1:]:
        if subprocess.run(['dpkg', '--compare-versions', row['Version'], 'gt', candidate['Version']],
                          capture_output=True, timeout=30).returncode == 0:
            candidate = row
    command('dpkg', '--compare-versions', candidate['Version'], 'ge', minimum)
    filename = candidate['Filename']
    if (not re.fullmatch('[A-Za-z0-9_./+~-]+', filename) or filename.startswith('/')
            or any(part in ('', '.', '..') for part in filename.split('/')) or not filename.endswith('.deb')):
        raise ValueError('Invalid snapshot package path')
    package_path = repository / filename
    package = read(package_path, owner, 256 * 1024 * 1024)
    if (not re.fullmatch('[a-f0-9]{64}', candidate.get('SHA256', ''))
            or hashlib.sha256(package).hexdigest() != candidate['SHA256']
            or candidate.get('Size') != str(len(package))):
        raise ValueError('Snapshot package differs')
    package_identity = command('dpkg-deb', '--show', '--showformat=${Package}\n${Version}\n${Architecture}\n', str(package_path)).stdout.splitlines()
    if package_identity != ['caddy', candidate['Version'], architecture]:
        raise ValueError('Snapshot package identity differs')
    expected_files = {'gpg.key', str(release_path.relative_to(root)), str(index_path.relative_to(root)),
                      str(package_path.relative_to(root))}
    if found != expected_files:
        raise ValueError('Snapshot contains unexpected files')
    return {'verified': True, 'version': candidate['Version'], 'architecture': architecture,
            'package_sha256': candidate['SHA256'], 'source': 'file:' + str(repository)}


def install(source, destination, key_sha256, fingerprint, minimum, owner=1002):
    verify(source, key_sha256, fingerprint, minimum, owner=owner)
    if destination.exists() or destination.is_symlink() or destination.parent.resolve() != destination.parent:
        raise ValueError('Snapshot destination is not empty and local')
    destination.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    if destination.parent.stat().st_uid != 0 or destination.parent.stat().st_mode & 0o022:
        raise ValueError('Snapshot parent is not protected')
    descriptor = os.open(destination.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        if destination.exists() or destination.is_symlink():
            raise ValueError('Snapshot destination already exists')
        with tempfile.TemporaryDirectory(dir=destination.parent, prefix='.orbit-caddy-source-') as temporary:
            candidate = Path(temporary) / 'snapshot'
            shutil.copytree(source, candidate)
            for directory, _, files in os.walk(candidate):
                os.chown(directory, 0, 0)
                Path(directory).chmod(0o755)
                for filename in files:
                    path = Path(directory) / filename
                    os.chown(path, 0, 0)
                    path.chmod(0o644)
            verify(candidate, key_sha256, fingerprint, minimum)
            if destination.exists() or destination.is_symlink():
                raise ValueError('Snapshot destination changed')
            candidate.rename(destination)
    finally:
        os.close(descriptor)
    return verify(destination, key_sha256, fingerprint, minimum)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 5:
            raise ValueError('Invalid snapshot request')
        print(json.dumps(verify(Path(sys.argv[1]), *sys.argv[2:])))
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print('Authenticated Caddy snapshot verification failed.', file=sys.stderr)
        sys.exit(1)
