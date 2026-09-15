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
STATE = Path('/home/orbit/.local/state/orbit-cli-ux/ORB-354')
ROOT = STATE / 'results'
PYTHON = STATE / 'venv/bin/python'
FIXTURES = Path('/var/lib/orbit-e2e/proof')
CANDIDATE = '369a5e39c5e3f19a5582672df4f3c35fb15bfabd'
os.umask(0o077)
assert subprocess.check_output(['git', '-C', str(SOURCE), 'rev-parse', 'HEAD'], text=True).strip() == CANDIDATE
assert not subprocess.check_output(['git', '-C', str(SOURCE), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()
action = sys.argv[1]
counts = {'rendering': 69, 'modes': 46, 'liveness': 23, 'errors': 52, 'consent': 36, 'empty': 8, 'invalid': 85, 'actual': 27}
if action == 'setup':
    ROOT.mkdir(mode=0o700)
    identity = {'candidate': CANDIDATE, 'issue': 'ORB-354', 'host': socket.gethostname(), 'source': str(SOURCE),
                'launcher': str(SOURCE / 'apps/cli/orbit'), 'python': str(PYTHON),
                'php': subprocess.check_output(['php', '-v'], text=True),
                'scope': '23 public commands; actual Gateway state transitions plus controlled TLS rendering/mode/failure fixtures'}
    (ROOT / 'identity.json').write_text(json.dumps(identity, indent=2) + '\n')
    print(json.dumps(identity))
    sys.exit(0)
if action != 'adoption':
    command = [str(PYTHON), str(FIXTURES / ('orb354-actual.py' if action == 'actual' else 'orb354-commands.py')),
               '--candidate', CANDIDATE, '--state', str(ROOT)]
    if action != 'actual':
        command += ['--group', action]
    result = subprocess.run(command, cwd=SOURCE)
    assert result.returncode == 0, (action, result.returncode)
    sys.exit(0)
for group, count in counts.items():
    result = json.loads((ROOT / group / 'result.json').read_text())
    assert result['candidate'] == CANDIDATE and result['passed'] is True, group
    assert len(result.get('records', result.get('cases'))) == count, (group, result)
expected_commands = {c['argv'][0] for c in json.loads((FIXTURES / 'orb354-cases.json').read_text())}
actual = json.loads((ROOT / 'actual/result.json').read_text())
assert set(actual['commands']) == expected_commands and len(expected_commands) == 23
(ROOT / 'adoption.json').write_text(json.dumps({'candidate': CANDIDATE, 'commands': sorted(expected_commands),
    'counts': counts, 'automatic_cases': sum(counts.values()), 'verdict': 'Observed; independent review and native Solo verification pending'}, indent=2) + '\n')
files = []
for path in sorted(ROOT.rglob('*')):
    relative = path.relative_to(ROOT)
    if not path.is_file() or path.is_symlink() or any(part.endswith('-home') for part in relative.parts) or path.name in ['tls.key', 'tls.pem']:
        continue
    files.append({'path': str(relative), 'bytes': path.stat().st_size, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()})
manifest = ROOT / 'complete-manifest.json'
manifest.write_text(json.dumps({'candidate': CANDIDATE, 'files': files}, indent=2) + '\n')
archive = STATE / 'recordings.tar.xz'
with archive.open('xb') as target:
    with tarfile.open(fileobj=target, mode='w:xz') as bundle:
        for entry in [*files, {'path': 'complete-manifest.json'}]:
            bundle.add(ROOT / entry['path'], arcname=entry['path'], recursive=False)
print(json.dumps({'archive': str(archive), 'bytes': archive.stat().st_size,
                  'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'candidate': CANDIDATE, 'files': len(files) + 1}))
