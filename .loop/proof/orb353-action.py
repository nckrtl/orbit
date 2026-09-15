#!/usr/bin/env python3
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tarfile

SOURCE = Path('/home/orbit/orbit')
STATE = Path('/home/orbit/.local/state/orbit-cli-ux/ORB-353')
ROOT = STATE / 'results'
PYTHON = STATE / 'venv/bin/python'
CANDIDATE = '1affee761a2612deb64056a81565d86a6b7ec134'
os.umask(0o077)
assert subprocess.check_output(['git', '-C', str(SOURCE), 'rev-parse', 'HEAD'], text=True).strip() == CANDIDATE
assert not subprocess.check_output(['git', '-C', str(SOURCE), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()
action = sys.argv[1]
if action == 'setup':
    ROOT.mkdir(mode=0o700)
    identity = {'candidate': CANDIDATE, 'issue': 'ORB-353', 'host': socket.gethostname(), 'source': str(SOURCE),
                'launcher': str(SOURCE / 'apps/cli/orbit'), 'python': str(PYTHON),
                'php': subprocess.check_output(['php', '-v'], text=True),
                'scope': 'Six actual public commands; synthetic local profiles plus real disposable Gateway status'}
    (ROOT / 'identity.json').write_text(json.dumps(identity, indent=2) + '\n')
    print(json.dumps(identity))
    sys.exit(0)
command = [str(PYTHON), '/var/lib/orbit-e2e/proof/orb353-commands.py', '--candidate', CANDIDATE, '--group', action, '--state', str(ROOT)]
result = subprocess.run(command, cwd=SOURCE)
assert result.returncode == 0, (action, result.returncode)
if action == 'adoption':
    files = []
    for p in sorted(ROOT.rglob('*')):
        relative = p.relative_to(ROOT)
        if not p.is_file() or p.is_symlink() or any(part.endswith('-home') for part in relative.parts) or p.name in ['tls.key', 'tls.pem']:
            continue
        files.append({'path': str(relative), 'bytes': p.stat().st_size, 'sha256': hashlib.sha256(p.read_bytes()).hexdigest()})
    manifest = ROOT / 'complete-manifest.json'
    manifest.write_text(json.dumps({'candidate': CANDIDATE, 'files': files}, indent=2) + '\n')
    archive = STATE / 'recordings.tar.xz'
    with archive.open('xb') as target:
        with tarfile.open(fileobj=target, mode='w:xz') as bundle:
            for entry in [*files, {'path': 'complete-manifest.json'}]:
                bundle.add(ROOT / entry['path'], arcname=entry['path'], recursive=False)
    print(json.dumps({'archive': str(archive), 'bytes': archive.stat().st_size,
                      'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'candidate': CANDIDATE, 'files': len(files) + 1}))
