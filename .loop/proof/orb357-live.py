#!/usr/bin/env python3
"""Record ORB-357 database/env CLI UX on a disposable proof topology."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import termios
from pathlib import Path

CANDIDATE = '61bd0637ae5d156bf415b1294d4c82a3678da201'
REQUEST_ID = re.compile(
    r'\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z',
    re.I,
)
SENTINEL = 'ux357-db-secret-not-for-output'

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    stderr_file = open(os.environ['ORB357_CAPTURE_STDERR'], 'wb') if os.environ.get('ORB357_CAPTURE_STDERR') else None
    child = subprocess.Popen(sys.argv[3:], stderr=stderr_file)
    code = child.wait()
    if stderr_file is not None:
        stderr_file.close()
    after = termios.tcgetattr(0)
    Path(sys.argv[2]).write_text(json.dumps({'before': repr(before), 'after': repr(after), 'equal': before == after, 'exit': code}))
    sys.exit(code)

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--stage', required=True, choices=[
    'prepare', 'registry', 'query-sqlite', 'consent', 'env', 'coverage-report',
])
parser.add_argument('--visible', action='store_true')
args = parser.parse_args()
assert args.candidate == CANDIDATE, args.candidate
os.umask(0o077)
source = Path('/home/orbit/orbit')
assert subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() == args.candidate
root = args.state / args.stage
root.mkdir(parents=True)
private = root / 'gateway-home'
private.mkdir()
shutil.copyfile(Path.home() / '.orbit/config.json', private / 'config.json')
env = dict(os.environ, ORBIT_HOME=str(private), TERM='xterm-256color', LC_ALL='C.UTF-8', PAO_DISABLE='1')
for key in ['NO_COLOR', 'FORCE_COLOR', 'CLICOLOR', 'COLUMNS', 'LINES']:
    env.pop(key, None)
launcher = source / 'apps/cli/orbit'
recorder = source / '.agents/skills/verifying-cli-output/scripts'
python = sys.executable
records = []


def save(path: Path, value) -> None:
    path.write_text(json.dumps(value, indent=2) + '\n')


def read(*argv, expected=0, error=None):
    command = ['php', str(launcher), *map(str, argv), '--json']
    result = subprocess.run(command, cwd=source, env=env, capture_output=True, input=b'', timeout=240)
    assert result.returncode == expected, (argv, result.returncode, result.stdout, result.stderr)
    assert result.stderr == b'' and b'\x1b' not in result.stdout, (argv, result.stderr)
    payload = json.loads(result.stdout)
    if error:
        assert payload['error']['code'] == error, (argv, payload)
    return payload


def record_pty(label, argv, *, expected=0, contains=None, absent=None, error_code=None, actual_gateway=True, input_actions=None):
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--ansi']
    capture = [python, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
               '--candidate', args.candidate, '--label', label, '--columns', '100', '--rows', '40',
               '--timeout', '240', '--idle-timeout', '60']
    if not args.visible:
        capture.append('--no-live')
    if input_actions is not None:
        save(out / 'inputs.json', input_actions)
        capture += ['--input-plan', str(out / 'inputs.json')]
    capture += ['--', python, str(Path(__file__).resolve()), '--child', str(out / 'terminal.json'), *command]
    print('$ ' + shlex.join(command), flush=True)
    capture_env = dict(env)
    if '--json' in argv:
        capture_env['ORB357_CAPTURE_STDERR'] = str(out / 'stderr.bin')
    result = subprocess.run(capture, cwd=source, env=capture_env, capture_output=not args.visible, timeout=255)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal'], label
    raw = (out / 'capture/raw.bin').read_bytes()
    text = raw.decode('utf-8', 'replace')
    assert SENTINEL not in text, (label, 'secret leaked')
    if '--json' in argv:
        assert (out / 'stderr.bin').read_bytes() == b'', label
        assert b'\x1b' not in raw, label
        machine = json.loads(raw)
        if error_code:
            assert machine['error']['code'] == error_code, (label, machine)
        else:
            assert 'error' not in machine, (label, machine)
            assert REQUEST_ID.fullmatch(machine.get('request_id') or ''), (label, machine)
        save(out / 'payload.json', machine)
    if contains:
        for token in contains:
            assert token in text, (label, token, text[-800:])
    if absent:
        for token in absent:
            assert token not in text, (label, token)
    expectation = {'candidate': args.candidate, 'label': label, 'exit_code': expected, 'max_first_output_seconds': 3}
    if contains:
        expectation['contains'] = contains
    if '--json' in argv:
        expectation['absent'] = ['Request ID:']
    save(out / 'expectation.json', expectation)
    verified = subprocess.run(
        [python, str(recorder / 'verify.py'), '--capture', str(out / 'capture'), '--expect', str(out / 'expectation.json')],
        capture_output=True, text=True,
    )
    (out / 'verify.json').write_text(verified.stdout)
    assert verified.returncode == 0, (label, verified.stdout, verified.stderr)
    record = {'label': label, 'argv': command, 'candidate': args.candidate, 'exit': expected, 'passed': True}
    records.append(record)
    save(root / 'records.json', records)
    print(json.dumps({'label': label, 'passed': True}), flush=True)
    return text


def app_dev():
    nodes = read('node:list')['nodes']
    return next(n for n in nodes if 'app-dev' in n.get('roles', []))


def sqlite_path() -> Path:
    instances = read('instance:list')['app_instances']
    dev = next(i for i in instances if i['name'] == 'e2e-dev')
    path = Path(dev['checkout_path']) / 'database' / 'database.sqlite'
    return path


if args.stage == 'prepare':
    node = app_dev()
    path = sqlite_path()
    created = read(
        'database:create', 'ux357-sqlite',
        '--driver=sqlite',
        f'--path={path}',
        f'--node={node["id"]}',
    )
    assert created['slug'] == 'ux357-sqlite' and created['driver'] == 'sqlite'
    assert SENTINEL not in json.dumps(created)
    save(root / 'created-sqlite.json', created)
    listed = read('database:list')
    assert any(c['slug'] == 'ux357-sqlite' for c in listed['connections'])
    shown = read('database:show', 'ux357-sqlite')
    assert shown['path'] == str(path)

elif args.stage == 'registry':
    record_pty('list-human', ['database:list'], contains=['ux357-sqlite', 'sqlite', 'Request ID:'], absent=[SENTINEL])
    record_pty('show-human', ['database:show', 'ux357-sqlite'], contains=['ux357-sqlite', 'sqlite', 'Request ID'], absent=[SENTINEL])
    record_pty('list-json', ['database:list', '--json'], absent=[SENTINEL])
    updated = read('database:update', 'ux357-sqlite', '--username=orbit')
    assert updated['username'] == 'orbit'
    record_pty('show-after-update', ['database:show', 'ux357-sqlite'], contains=['orbit'])

elif args.stage == 'query-sqlite':
    before_payload = read(
        'database:query', 'ux357-sqlite',
        "SELECT COUNT(*) AS c FROM ux357_items WHERE name = 'post-state'",
    )
    before = int(next(iter(before_payload.get('rows') or [{'c': 0}])).get('c') or 0)
    record_pty(
        'query-empty-read',
        ['database:query', 'ux357-sqlite', "SELECT name FROM ux357_items WHERE name = 'missing-row'"],
        contains=['Write permission', 'No matching records found.'],
        absent=['Wrote', 'Rows affected'],
    )
    record_pty(
        'query-select-write',
        ['database:query', 'ux357-sqlite', "SELECT name FROM ux357_items LIMIT 1", '--write'],
        contains=['Write permission', 'yes'],
        absent=['Statement completed.'],
    )
    record_pty(
        'query-insert',
        ['database:query', 'ux357-sqlite', "INSERT INTO ux357_items (name) VALUES ('post-state')", '--write'],
        contains=['Write permission', 'Statement completed.'],
        absent=['No matching records found.', 'Wrote', 'Rows affected'],
    )
    after_payload = read(
        'database:query', 'ux357-sqlite',
        "SELECT COUNT(*) AS c FROM ux357_items WHERE name = 'post-state'",
    )
    after = int(next(iter(after_payload.get('rows') or [{'c': 0}])).get('c') or 0)
    assert after == before + 1, (before, after, after_payload)
    save(root / 'sqlite-post-state.json', {'before': before, 'after': after, 'count_payload': after_payload})
    record_pty(
        'query-insert-json',
        ['database:query', 'ux357-sqlite', "INSERT INTO ux357_items (name) VALUES ('json-row')", '--write', '--json'],
        absent=[SENTINEL],
    )

elif args.stage == 'consent':
    record_pty(
        'destroy-json-no-force',
        ['database:destroy', 'ux357-sqlite', '--json'],
        expected=1,
        error_code='database.confirmation_required',
        actual_gateway=False,
    )
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    record_pty(
        'destroy-default-no',
        ['database:destroy', 'ux357-sqlite'],
        expected=1,
        contains=['ux357-sqlite', 'physical database is not dropped'],
        input_actions=[{'wait_for': 'No', 'send': '\r'}],
    )
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    record_pty(
        'destroy-force',
        ['database:destroy', 'ux357-sqlite', '--force'],
        contains=['destroyed'],
    )
    missing = read('database:show', 'ux357-sqlite', expected=1, error='http.404')
    save(root / 'destroyed.json', missing)

elif args.stage == 'env':
    instances = read('instance:list')['app_instances']
    dev = next(i for i in instances if i['name'] == 'e2e-dev')
    record_pty(
        'env-update',
        ['env:update', f'--instance={dev["id"]}', '--key=UX357_FLAG', '--value=true'],
        contains=['AppInstance ID', 'Operation', 'Changed', 'Stored keys'],
        absent=[SENTINEL],
    )
    record_pty(
        'env-update-json',
        ['env:update', f'--instance={dev["id"]}', '--key=UX357_FLAG', '--value=true', '--json'],
        absent=[SENTINEL],
    )
    payload = json.loads((root / 'env-update-json' / 'payload.json').read_text())
    assert payload['operation'] == 'update'
    assert payload.get('workload_file_changed') is False
    assert 'value' not in payload

elif args.stage == 'coverage-report':
    expected = [
        'list-human', 'show-human', 'query-empty-read', 'query-select-write',
        'query-insert', 'destroy-json-no-force', 'destroy-default-no', 'env-update',
    ]
    found = []
    for name in expected:
        matches = list(args.state.rglob(name + '/verify.json'))
        assert matches, name
        verify = json.loads(matches[0].read_text())
        assert verify.get('passed') is True or verify.get('ok') is True or 'passed' in json.dumps(verify).lower(), (name, verify)
        found.append(name)
    save(root / 'coverage-aggregate.json', {'expected': expected, 'found': found, 'complete': True})

save(root / 'records.json', records)
print(json.dumps({'stage': args.stage, 'passed': True, 'records': len(records)}), flush=True)
