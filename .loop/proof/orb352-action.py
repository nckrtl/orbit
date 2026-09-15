#!/usr/bin/env python3
"""Bind every ORB-352 proof action to one guest, source candidate and output root."""
import hashlib
import json
import os
import runpy
from pathlib import Path
import socket
import subprocess
import sys
import tarfile
import uuid

SOURCE = Path('/home/orbit/orbit')
STAGED = Path('/var/lib/orbit-e2e/proof')
STATE = Path('/home/orbit/.local/state/orbit-cli-ux/ORB-352')
SESSION = STATE / 'session.json'
PYTHON = STATE / 'venv/bin/python'
action = sys.argv[1]
identity_helper = runpy.run_path(str(Path(__file__).with_name('orb352-identity.py')))
source_identity = identity_helper['verify_source'](SOURCE)
candidate = source_identity['candidate']
host = socket.gethostname()
if action == 'setup':
    root = STATE / host / candidate / uuid.uuid4().hex
    root.mkdir(parents=True, exist_ok=False)
    identity_helper['retain_identity'](root, source_identity)
    identity = {'issue': 'ORB-352', 'host': host, 'candidate': candidate,
                'source': str(SOURCE), 'source_identity': source_identity, 'output_root': str(root),
                'php': subprocess.check_output(['php', '-r', 'echo PHP_BINARY;'], text=True),
                'python': str(PYTHON),
                'scope': 'Shared primitives and common entry points; not family adoption'}
    SESSION.write_text(json.dumps(identity, indent=2) + '\n')
    (root / 'identity.json').write_text(json.dumps(identity, indent=2) + '\n')
    print(json.dumps(identity))
    sys.exit(0)
identity = json.loads(SESSION.read_text())
assert identity['candidate'] == candidate and identity['host'] == host
root = Path(identity['output_root'])
if action == 'layout':
    argv = [str(PYTHON), str(STAGED / 'orb352-layout.py'), str(root / 'layout'), candidate]
elif action == 'prompts':
    argv = [str(PYTHON), str(STAGED / 'orb352-prompts.py'), str(root), candidate]
elif action in ('liveness', 'lifecycle'):
    argv = [str(PYTHON), str(STAGED / 'orb352-runtime.py'), action, str(root), candidate]
elif action in ('modes', 'contracts'):
    argv = [str(PYTHON), str(STAGED / 'orb352-contracts.py'), action, str(root / action), candidate]
else:
    raise ValueError('Unknown proof action')
commands = [argv]
if action == 'layout':
    commands.append([str(PYTHON), str(STAGED / 'orb352-states.py'), str(root), candidate])
for command in commands:
    code = subprocess.run(command, cwd=SOURCE).returncode
    if code != 0:
        break
identity_helper['verify_source'](SOURCE, candidate)
(root / (action + '-action.json')).write_text(json.dumps({
    'action': action, 'candidate': candidate, 'host': host, 'exit_code': code,
    'commands': commands,
    'runner_sha256': {command[1]: hashlib.sha256(Path(command[1]).read_bytes()).hexdigest() for command in commands},
}, indent=2) + '\n')
files = [{'path': str(p.relative_to(root)), 'bytes': p.stat().st_size,
          'sha256': hashlib.sha256(p.read_bytes()).hexdigest()}
         for p in sorted(root.rglob('*')) if p.is_file() and p.name != 'complete-manifest.json']
manifest = root / 'complete-manifest.json'
manifest.write_text(json.dumps({'candidate': candidate, 'host': host, 'files': files}, indent=2) + '\n')
print(json.dumps({'action': action, 'exit_code': code, 'output_root': str(root),
                  'manifest': str(manifest), 'files': len(files),
                  'manifest_bytes': manifest.stat().st_size,
                  'manifest_sha256': hashlib.sha256(manifest.read_bytes()).hexdigest()}))
required_actions = ['layout', 'modes', 'prompts', 'liveness', 'lifecycle', 'contracts']
complete = all((root / (name + '-action.json')).is_file() and
               json.loads((root / (name + '-action.json')).read_text())['exit_code'] == 0
               for name in required_actions)
if action == 'contracts' and code == 0 and complete:
    archive = root.parent / (root.name + '.tar.xz')
    with archive.open('xb') as target:
        with tarfile.open(fileobj=target, mode='w:xz', preset=9) as bundle:
            for entry in [*files, {'path': 'complete-manifest.json'}]:
                path = root / entry['path']
                assert path.is_file() and not path.is_symlink()
                bundle.add(path, arcname=entry['path'], recursive=False)
    print(json.dumps({'archive': str(archive), 'bytes': archive.stat().st_size,
                      'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
                      'candidate': candidate, 'host': host, 'files': len(files) + 1}))
sys.exit(code)
