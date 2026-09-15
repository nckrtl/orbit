#!/usr/bin/env python3
"""Exact source identity for Git checkouts and harness-mounted discovery.

Host generation (read-only Git; root owns generation/staging):
  python3 orb352-identity.py generate SOURCE SHA NEW_MANIFEST.json
Stage as /var/lib/orbit-e2e/proof/orb352-source-manifest.json.
The manifest comes exclusively from committed objects, never working files.
"""
import base64
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys


MARKER = Path('/var/lib/orbit-e2e/source-state')
MANIFEST = Path('/var/lib/orbit-e2e/proof/orb352-source-manifest.json')
INVENTORY_ROOTS = [
    'apps/cli/app', 'apps/cli/bootstrap', 'apps/cli/config', 'apps/cli/tests/Fixtures',
    'packages/php-sdk/src', '.agents/skills/verifying-cli-output/scripts',
]


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def git(source, *args):
    result = subprocess.run(['git', '-C', str(source), *args], capture_output=True)
    require(result.returncode == 0, 'Git identity command failed: ' + ' '.join(args))
    return result.stdout


def object_hash(kind, data):
    return hashlib.sha1(kind.encode() + b' ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def tree_hash(entries):
    root = {}
    for item in entries:
        parts = PurePosixPath(item['path']).parts
        require(parts and not item['path'].startswith('/') and '..' not in parts
                and str(PurePosixPath(item['path'])) == item['path'], 'Unsafe manifest path')
        node = root
        for part in parts[:-1]:
            require(not isinstance(node.get(part), tuple), 'Manifest file/directory collision')
            node = node.setdefault(part, {})
        require(parts[-1] not in node, 'Duplicate manifest path')
        require(item['mode'] in ['100644', '100755', '120000'], 'Unsupported source entry mode')
        require(re.fullmatch('[0-9a-f]{40}', item['blob']) is not None, 'Invalid blob identity')
        node[parts[-1]] = (item['mode'], item['blob'])

    def calculate(node):
        records = []
        for name, value in node.items():
            encoded = name.encode('utf-8')
            if isinstance(value, dict):
                records.append((encoded + b'/', b'40000 ' + encoded + b'\0' + bytes.fromhex(calculate(value))))
            else:
                mode, oid = value
                records.append((encoded, mode.encode() + b' ' + encoded + b'\0' + bytes.fromhex(oid)))
        return object_hash('tree', b''.join(data for _, data in sorted(records)))
    return calculate(root)


def validate_manifest(manifest, candidate):
    require(manifest['schema'] == 1 and manifest['candidate'] == candidate, 'Manifest candidate/schema differs')
    require(manifest['inventory_roots'] == INVENTORY_ROOTS, 'Manifest runtime inventory boundary differs')
    commit = base64.b64decode(manifest['commit_base64'], validate=True)
    require(object_hash('commit', commit) == candidate, 'Manifest commit object does not hash to candidate')
    tree = tree_hash(manifest['files'])
    require(commit.split(b'\n', 1)[0] == b'tree ' + tree.encode(), 'Manifest full file tree differs from candidate commit')
    require(manifest['tree_oid'] == tree, 'Manifest tree identity differs')
    require(manifest['effective_tree_hash'] == hashlib.sha256(tree.encode()).hexdigest(), 'Manifest effective tree hash differs')
    return tree


def actual_entry(source, item):
    path = source / item['path']
    for parent in path.parents:
        if parent == source:
            break
        require(not parent.is_symlink(), 'Tracked source parent is a symlink: ' + item['path'])
    metadata = path.lstat()
    if item['mode'] == '120000':
        require(stat.S_ISLNK(metadata.st_mode), 'Expected tracked symlink: ' + item['path'])
        data = os.fsencode(os.readlink(path))
    else:
        require(stat.S_ISREG(metadata.st_mode), 'Expected tracked regular file: ' + item['path'])
        executable = bool(metadata.st_mode & 0o111)
        require(executable == (item['mode'] == '100755'), 'Tracked executable mode differs: ' + item['path'])
        data = path.read_bytes()
    require(len(data) == item['bytes'] and hashlib.sha256(data).hexdigest() == item['sha256']
            and object_hash('blob', data) == item['blob'], 'Tracked source content differs: ' + item['path'])


def check_inventory(source, entries):
    expected = {entry['path'] for entry in entries
                if any(entry['path'] == root or entry['path'].startswith(root + '/') for root in INVENTORY_ROOTS)}
    actual = set()
    for root in INVENTORY_ROOTS:
        base = source / root
        require(base.is_dir() and not base.is_symlink(), 'Runtime inventory root missing or symlinked: ' + root)
        for directory, dirs, files in os.walk(base, followlinks=False):
            # Python creates this ignored cache when the restored recorder is
            # imported. Dependency/cache integrity remains the harness's scope.
            if Path(directory) == source / '.agents/skills/verifying-cli-output/scripts':
                dirs[:] = [name for name in dirs if name != '__pycache__']
            for name in list(dirs):
                path = Path(directory) / name
                if path.is_symlink():
                    actual.add(path.relative_to(source).as_posix())
                    dirs.remove(name)
            for name in files:
                actual.add((Path(directory) / name).relative_to(source).as_posix())
    require(actual == expected, 'Runtime file inventory differs: added=' + repr(sorted(actual - expected))
            + ' missing=' + repr(sorted(expected - actual)))
    return len(actual)


def generate(source, candidate, output):
    require(git(source, 'rev-parse', 'HEAD').decode().strip() == candidate, 'Host HEAD is not candidate')
    require(git(source, 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignore-submodules=none') == b'',
            'Host tree is dirty; commit or restore it before manifest generation')
    raw = git(source, 'ls-tree', '-rz', '--full-tree', candidate)
    entries = []
    for record in raw.split(b'\0'):
        if not record:
            continue
        prefix, name = record.split(b'\t', 1)
        mode, kind, oid = prefix.decode().split(' ')
        require(kind == 'blob', 'Manifest does not support submodules')
        entries.append({'path': name.decode('utf-8'), 'mode': mode, 'blob': oid})
    require(entries, 'Candidate has no source entries')
    result = subprocess.run(['git', '-C', str(source), 'cat-file', '--batch'],
         input=''.join(entry['blob'] + '\n' for entry in entries).encode(), capture_output=True)
    require(result.returncode == 0, 'Cannot read committed source objects')
    position = 0
    for entry in entries:
        end = result.stdout.index(b'\n', position)
        oid, kind, size = result.stdout[position:end].decode().split(' ')
        size = int(size)
        require(oid == entry['blob'] and kind == 'blob', 'Committed source object differs')
        data = result.stdout[end + 1:end + 1 + size]
        require(result.stdout[end + 1 + size:end + 2 + size] == b'\n', 'Incomplete source object')
        entry.update({'bytes': size, 'sha256': hashlib.sha256(data).hexdigest()})
        position = end + size + 2
    require(position == len(result.stdout), 'Unexpected object output suffix')
    tree = git(source, 'rev-parse', candidate + '^{tree}').decode().strip()
    manifest = {'schema': 1, 'candidate': candidate, 'tree_oid': tree,
        'effective_tree_hash': hashlib.sha256(tree.encode()).hexdigest(),
        'commit_base64': base64.b64encode(git(source, 'cat-file', 'commit', candidate)).decode(),
        'inventory_roots': INVENTORY_ROOTS, 'files': entries}
    validate_manifest(manifest, candidate)
    for entry in entries:
        actual_entry(source, entry)
    check_inventory(source, entries)
    require(git(source, 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignore-submodules=none') == b''
            and git(source, 'rev-parse', 'HEAD').decode().strip() == candidate, 'Host changed during generation')
    with output.open('x') as stream:
        json.dump(manifest, stream, indent=2, ensure_ascii=False)
        stream.write('\n')
    print(json.dumps({'candidate': candidate, 'files': len(entries), 'manifest': str(output),
         'bytes': output.stat().st_size, 'sha256': hashlib.sha256(output.read_bytes()).hexdigest()}))


def verify_source(source, candidate=None, evidence_root=None):
    source = Path(source)
    head = subprocess.run(['git', '-C', str(source), 'rev-parse', 'HEAD'], capture_output=True)
    manifest_bytes = None
    if head.returncode == 0:
        actual = head.stdout.decode().strip()
        candidate = candidate or actual
        require(actual == candidate, 'Guest Git candidate differs')
        require(git(source, 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignore-submodules=none') == b'',
                'Guest Git checkout is dirty')
        raw = git(source, 'ls-tree', '-rz', '--full-tree', candidate)
        entries = [{'path': record.split(b'\t', 1)[1].decode()} for record in raw.split(b'\0') if record]
        inventory = check_inventory(source, entries)
        result = {'candidate': candidate, 'method': 'usable-git-clean-checkout', 'source': str(source),
                  'tree_oid': git(source, 'rev-parse', candidate + '^{tree}').decode().strip(),
                  'runtime_inventory_files': inventory, 'full_git_status_clean': True}
    else:
        marker_bytes = MARKER.read_bytes()
        marker = json.loads(marker_bytes)
        manifest_bytes = MANIFEST.read_bytes()
        manifest = json.loads(manifest_bytes)
        candidate = candidate or manifest['candidate']
        require(re.fullmatch('[0-9a-f]{40}', candidate) is not None, 'Exact candidate SHA required')
        tree = validate_manifest(manifest, candidate)
        require(marker.get('mounted') is True and marker.get('sha') == candidate, 'Mounted discovery marker candidate differs')
        require(marker.get('tree') == manifest['effective_tree_hash'], 'Mounted source marker represents a dirty or different tree')
        pointer = source / '.git'
        require(pointer.is_file() and not pointer.is_symlink(), 'Mounted discovery needs a regular .git pointer file')
        require(hashlib.sha256(pointer.read_bytes()).hexdigest() == marker.get('git_pointer_sha256'), 'Mounted Git pointer differs from synchronized source')
        for entry in manifest['files']:
            actual_entry(source, entry)
        inventory = check_inventory(source, manifest['files'])
        require(MARKER.read_bytes() == marker_bytes and MANIFEST.read_bytes() == manifest_bytes, 'Identity inputs changed during verification')
        result = {'candidate': candidate, 'method': 'harness-mounted-discovery', 'source': str(source),
                  'tree_oid': tree, 'effective_tree_hash': marker['tree'], 'source_marker': marker,
                  'source_marker_sha256': hashlib.sha256(marker_bytes).hexdigest(),
                  'manifest': str(MANIFEST), 'manifest_bytes': len(manifest_bytes),
                  'manifest_sha256': hashlib.sha256(manifest_bytes).hexdigest(),
                  'tracked_files_verified': len(manifest['files']), 'runtime_inventory_files': inventory,
                  'scope': 'Exact committed tracked content and runtime inventory on mounted discovery; not isolated proof acceptance.'}
    require(re.fullmatch('[0-9a-f]{40}', candidate) is not None, 'Exact candidate SHA required')
    if evidence_root is not None:
        retain_identity(Path(evidence_root), result, manifest_bytes)
    return result


def retain_identity(root, identity, manifest_bytes=None):
    if identity['method'] == 'harness-mounted-discovery':
        manifest_bytes = manifest_bytes if manifest_bytes is not None else Path(identity['manifest']).read_bytes()
        require(hashlib.sha256(manifest_bytes).hexdigest() == identity['manifest_sha256'], 'Manifest changed before retention')
        (root / 'source-manifest.json').write_bytes(manifest_bytes)
    (root / 'source-identity.json').write_text(json.dumps(identity, indent=2) + '\n')


if __name__ == '__main__':
    require(len(sys.argv) == 5 and sys.argv[1] == 'generate', __doc__)
    generate(Path(sys.argv[2]).resolve(), sys.argv[3], Path(sys.argv[4]).resolve())
