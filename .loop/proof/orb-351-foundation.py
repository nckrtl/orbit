#!/usr/bin/env python3
"""Issue-owned proof actions. The harness independently binds the guest candidate."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tarfile

parser = argparse.ArgumentParser()
parser.add_argument('action', choices=['setup', 'standard-binding', 'skill-forward-check', 'recorder-contracts', 'verifier-contracts', 'registry-reconciliation', 'full-artifact-manifest'])
parser.add_argument('--root', type=Path, default=Path('/home/orbit/orbit'))
parser.add_argument('--base', type=Path)
args = parser.parse_args()
root = args.root.resolve()
fixtures = Path(__file__).resolve().parent
skill = root / '.agents/skills/verifying-cli-output'

def run(argv, **kwargs):
    return subprocess.run([str(item) for item in argv], check=True, **kwargs)

def git(*argv):
    return subprocess.check_output(['git', '-C', str(root), *argv], text=True).strip()

candidate, tree = git('rev-parse', 'HEAD'), git('rev-parse', 'HEAD^{tree}')
base = args.base or Path.home() / '.local/state/orbit-cli-ux/ORB-351' / socket.gethostname() / candidate / 'acceptance'
python = base / 'venv/bin/python'
identity = {'candidate': candidate, 'tree': tree, 'host': socket.gethostname(), 'root': str(root),
            'launcher': str(skill / 'scripts/capture.py'), 'phase': 'acceptance'}

def write(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')

if args.action == 'setup':
    os.umask(0o077)
    base.mkdir(parents=True, exist_ok=False)
    write(base / 'identity.json', identity)
    ensurepip = subprocess.run([sys.executable, '-c', 'import ensurepip'], capture_output=True)
    if ensurepip.returncode != 0:
        if sys.platform != 'linux':
            raise RuntimeError('The selected Python runtime requires ensurepip for isolated setup.')
        package = f'python{sys.version_info.major}.{sys.version_info.minor}-venv'
        run(['sudo', 'apt-get', 'install', '-y', package])
    run([sys.executable, '-m', 'venv', base / 'venv'])
    run([python, '-m', 'pip', 'install', '--disable-pip-version-check', '-r', skill / 'scripts/requirements.txt', '--report', base / 'dependency-install.json'])
    result = run([python, '-m', 'pip', 'freeze', '--all'], capture_output=True, text=True)
    (base / 'python-packages.txt').write_text(result.stdout)
    requirements = skill / 'scripts/requirements.txt'
    write(base / 'requirements.json', {'path': str(requirements.relative_to(root)), 'sha256': hashlib.sha256(requirements.read_bytes()).hexdigest()})
    print(json.dumps({'base': str(base), **identity}))
    raise SystemExit(0)

assert json.loads((base / 'identity.json').read_text()) == identity, 'runtime identity changed'

def capture(name, large=False):
    directory = base / name
    plan = base / f'{name}-input.json'
    write(plan, [{'wait_for': 'Continue with disposable fixture?', 'send': 'y\n'}])
    argv = [python, skill / 'scripts/capture.py', '--output-dir', directory, '--candidate', candidate,
            '--label', name, '--columns', '87', '--rows', '23', '--timeout', '20', '--idle-timeout', '5',
            '--input-plan', plan, '--', python, fixtures / 'orb-351-terminal.py']
    if large:
        argv.append('--large')
    write(base / f'{name}-case.json', {'argv': [str(item) for item in argv], **identity})
    run(argv)
    return directory

if args.action == 'standard-binding':
    paths = ['docs/reference/cli-ux.md', '.agents/skills/designing-cli-commands/SKILL.md',
             '.agents/skills/designing-cli-commands/templates/adoption-record.md',
             '.agents/skills/verifying-cli-output/SKILL.md',
             '.agents/skills/verifying-cli-output/scripts/capture.py',
             '.agents/skills/verifying-cli-output/scripts/verify.py']
    for relative in paths:
        content = (root / relative).read_bytes()
        committed = subprocess.check_output(['git', '-C', str(root), 'show', f'{candidate}:{relative}'])
        assert content == committed, f'uncommitted input: {relative}'
        print(json.dumps({'path': relative, 'sha256': hashlib.sha256(content).hexdigest(), 'candidate': candidate}))
elif args.action == 'skill-forward-check':
    directory = capture('skill-forward-check')
    expected = {'candidate': candidate, 'label': 'skill-forward-check', 'exit_code': 0,
                'contains': ['Terminal 87x23', 'Continue with disposable fixture?', 'Unicode: café 界 ┌─┐'],
                'final_contains': ['Work: complete', 'FINAL-MARKER'], 'max_first_output_seconds': 2,
                'state_rows': [{'name': 'work', 'pattern': r'Work: (?P<state>queued|running|complete)',
                                'states': ['queued', 'running', 'complete'],
                                'transitions': [['queued', 'running'], ['running', 'complete']],
                                'required': ['queued', 'running', 'complete']}],
                'animation_rows': [{'name': 'work', 'pattern': r'Work: running (?P<glyph>\S)',
                                    'terminal_pattern': r'Work: complete', 'minimum_changes': 4,
                                    'min_interval': .2, 'max_interval': .6}]}
    expectation = base / 'forward-expectation.json'
    write(expectation, expected)
    result = run([python, skill / 'scripts/verify.py', '--capture', directory, '--expect', expectation], capture_output=True, text=True)
    (base / 'forward-verdict.json').write_text(result.stdout)
    print(result.stdout)
    frames = [json.loads(line) for line in (directory / 'frames.jsonl').read_text().splitlines()]
    assert any(cell.get('dim') and cell['data'] == 'U' for frame in frames for cell in frame['cells']), 'dim frame missing'
    assert any(cell['fg'] == 'green' and cell['data'] == 'W' for cell in frames[-1]['cells']), 'green final row missing'
elif args.action in ('recorder-contracts', 'verifier-contracts'):
    pattern = 'test_capture*.py' if args.action == 'recorder-contracts' else 'test_verify*.py'
    result = subprocess.run([str(python), '-m', 'unittest', 'discover', '-s', 'tests', '-p', pattern, '-v'],
                            cwd=skill, capture_output=True, text=True)
    (base / f'{args.action}.stdout').write_text(result.stdout)
    (base / f'{args.action}.stderr').write_text(result.stderr)
    print(result.stdout + result.stderr)
    result.check_returncode()
elif args.action == 'registry-reconciliation':
    run(['php', fixtures / 'orb-351-registry.php', f'--root={root}', f'--expected={fixtures / "orb-351-inventory.json"}', f'--candidate={candidate}'])
elif args.action == 'full-artifact-manifest':
    directory = capture('large-final-output', large=True)
    assert (directory / 'raw.bin').stat().st_size > 4096
    assert b'FINAL-MARKER' in (directory / 'raw.bin').read_bytes()
    summary = json.loads((directory / 'summary.json').read_text())
    assert summary['drained'] and summary['capture_exit_code'] == summary['child_exit_code'] == 0
    manifest = []
    for path in sorted(base.rglob('*')):
        if not path.is_file() or 'venv' in path.relative_to(base).parts or path.name == 'manifest.json':
            continue
        data = path.read_bytes()
        manifest.append({'file': str(path.relative_to(base)), 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    write(base / 'manifest.json', {'identity': identity, 'files': manifest})
    archive = base.parent / f'{base.name}-recordings.tar.gz'
    with tarfile.open(archive, 'x:gz') as bundle:
        for entry in manifest:
            bundle.add(base / entry['file'], arcname=entry['file'])
        bundle.add(base / 'manifest.json', arcname='manifest.json')
    print(json.dumps({'archive': str(archive), 'archive_bytes': archive.stat().st_size,
                      'archive_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
                      'manifest': str(base / 'manifest.json'), 'bytes': (base / 'manifest.json').stat().st_size,
                      'sha256': hashlib.sha256((base / 'manifest.json').read_bytes()).hexdigest(), 'files': len(manifest)}))
