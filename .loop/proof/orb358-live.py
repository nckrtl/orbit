#!/usr/bin/env python3
"""Record ORB-358 process/schedule CLI UX on a disposable proof topology."""
from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import shutil
import signal
import subprocess
import sys
import termios
from pathlib import Path

CANDIDATE = '29e29051a21bfb0a4d9823b0abed83f6c7ff9b63'
REQUEST_ID = re.compile(
    r'\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z',
    re.I,
)
SENTINEL = 'ux358-secret-not-for-output'

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    stderr_file = open(os.environ['ORB358_CAPTURE_STDERR'], 'wb') if os.environ.get('ORB358_CAPTURE_STDERR') else None
    child = subprocess.Popen(sys.argv[3:], stderr=stderr_file)

    def forward(signum, _frame):
        try:
            child.send_signal(signum)
        except ProcessLookupError:
            pass

    signal.signal(signal.SIGINT, forward)
    signal.signal(signal.SIGTERM, forward)
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
    'prepare', 'processes', 'preset', 'schedules', 'consent', 'coverage-report',
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


def redacted_argv(command):
    return [str(part).replace(SENTINEL, '[REDACTED]') for part in command]


def read(*argv, expected=0, error=None):
    command = ['php', str(launcher), *map(str, argv), '--json']
    result = subprocess.run(command, cwd=source, env=env, capture_output=True, input=b'', timeout=240)
    assert result.returncode == expected, (argv, result.returncode, result.stdout, result.stderr)
    assert result.stderr == b'' and b'\x1b' not in result.stdout, (argv, result.stderr)
    payload = json.loads(result.stdout)
    if error:
        assert payload['error']['code'] == error, (argv, payload)
    return payload


def record_pty(label, argv, *, expected=0, contains=None, absent=None, error_code=None, input_actions=None, columns=100, rows=40, plain=False):
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--no-ansi' if plain else '--ansi']
    capture = [python, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
               '--candidate', args.candidate, '--label', label, '--columns', str(columns), '--rows', str(rows),
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
        capture_env['ORB358_CAPTURE_STDERR'] = str(out / 'stderr.bin')
    result = subprocess.run(capture, cwd=source, env=capture_env, capture_output=not args.visible, timeout=255)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal'], label
    raw = (out / 'capture/raw.bin').read_bytes()
    text = raw.decode('utf-8', 'replace')
    assert SENTINEL not in text, label
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
    frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
    assert frames[-1]['cursor']['hidden'] is False, label
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
    records.append({'label': label, 'argv': redacted_argv(command), 'candidate': args.candidate, 'exit': expected, 'passed': True})
    save(root / 'records.json', records)
    print(json.dumps({'label': label, 'passed': True}), flush=True)
    return text


def pipe(label, argv, *, expected=0, contains=None, error=None):
    command = ['php', str(launcher), *map(str, argv)]
    print('$ ' + shlex.join(command) + ' [pipe]', flush=True)
    result = subprocess.run(command, cwd=source, env=env, input=b'', capture_output=True, timeout=240)
    text = result.stdout.decode()
    assert result.returncode == expected, (label, result.returncode, text, result.stderr)
    assert result.stderr == b'', (label, result.stderr)
    assert SENTINEL not in text
    if '--json' in argv:
        payload = json.loads(result.stdout)
        if error:
            assert payload['error']['code'] == error, (label, payload)
        save(root / (label + '.json'), payload)
    elif contains:
        for token in contains:
            assert token in text, (label, token, text)
    (root / (label + '.stdout')).write_bytes(result.stdout)
    (root / (label + '.stderr')).write_bytes(result.stderr)
    records.append({'label': label, 'argv': redacted_argv(command), 'exit': expected, 'mode': 'pipe', 'passed': True})
    save(root / 'records.json', records)
    return result


def e2e_dev():
    return next(i for i in read('instance:list')['app_instances'] if i['name'] == 'e2e-dev')


def app():
    return read('app:list')['apps'][0]


if args.stage == 'prepare':
    dev = e2e_dev()
    save(root / 'instance.json', {'id': dev['id'], 'name': dev['name']})
    listed = read('process:list', f'--instance={dev["id"]}')
    save(root / 'process-list.json', listed)

elif args.stage == 'processes':
    dev = e2e_dev()
    created = read(
        'process:create', 'ux358-sleep',
        f'--instance={dev["id"]}',
        '--runtime=systemd',
        '--command=/bin/sleep',
        '--command=3600',
    )
    assert created['name'] == 'ux358-sleep'
    save(root / 'created.json', created)
    record_pty(
        'process-create-human',
        ['process:create', 'ux358-sleep-b', f'--instance={dev["id"]}', '--runtime=systemd', '--command=/bin/true'],
        contains=['ux358-sleep-b'],
    )
    definition = read(
        'process:create', 'ux358-def',
        f'--app={dev["app_id"]}',
        '--for=development',
        '--runtime=systemd',
        '--command=/bin/true',
    )
    save(root / 'definition.json', definition)
    record_pty('process-show-definition', ['process:show', 'ux358-def', f'--app={dev["app_id"]}'], contains=['ux358-def'])
    record_pty(
        'process-update-definition',
        ['process:update', 'ux358-def', f'--app={dev["app_id"]}', '--for=development', '--command=/bin/false'],
        contains=['ux358-def'],
    )
    record_pty('process-list', ['process:list', f'--instance={dev["id"]}'], contains=['ux358-sleep', 'Request ID:'])
    pipe('process-list-pipe', ['process:list', f'--instance={dev["id"]}', '--no-ansi'], contains=['ux358-sleep'])
    record_pty('process-list-json', ['process:list', f'--instance={dev["id"]}', '--json'])
    record_pty('process-start', ['process:start', str(created['id'])], contains=['ux358-sleep', 'started'])
    record_pty('process-restart', ['process:restart', str(created['id'])], contains=['ux358-sleep', 'restarted'])
    record_pty('process-logs', ['process:logs', str(created['id']), '--lines=20'], contains=['Request ID:'])
    pipe('process-logs-pipe', ['process:logs', str(created['id']), '--lines=20', '--no-ansi'], contains=['Request ID:'])
    record_pty('process-stop', ['process:stop', str(created['id'])], contains=['ux358-sleep', 'stopped'])
    record_pty(
        'process-destroy-json-no-yes',
        ['process:destroy', str(created['id']), '--json'],
        expected=1,
        error_code='input.confirmation_required',
    )
    still = read('process:list', f'--instance={dev["id"]}')
    assert any(p['id'] == created['id'] for p in still['processes'])

elif args.stage == 'preset':
    dev = e2e_dev()
    record_pty(
        'process-preset-invalid-option',
        ['process:create', 'ux358-assets', f'--instance={dev["id"]}', '--preset=vp-dev', '--runtime=docker', '--json'],
        expected=1,
        error_code='process.preset_option_invalid',
    )
    created = read('process:create', 'ux358-assets', f'--instance={dev["id"]}', '--preset=vp-dev')
    assert created['name'] == 'ux358-assets'
    save(root / 'preset.json', created)
    record_pty('process-preset-create', ['process:create', 'ux358-assets-b', f'--instance={dev["id"]}', '--preset=vp-dev'], contains=['ux358-assets-b'])
    record_pty('process-show-missing-app', ['process:show', 'ux358-assets', '--json'], expected=1, error_code='process.target_invalid')

elif args.stage == 'schedules':
    dev = e2e_dev()
    created = read(
        'schedule:create', 'ux358-tick',
        f'--instance={dev["id"]}',
        '--calendar=minutely',
        '--command=/bin/true',
        '--no-start',
    )
    assert created['name'] == 'ux358-tick'
    save(root / 'created-schedule.json', created)
    record_pty(
        'schedule-create-human',
        ['schedule:create', 'ux358-tick-b', f'--instance={dev["id"]}', '--calendar=minutely', '--command=/bin/true', '--no-start'],
        contains=['ux358-tick-b'],
    )
    read(
        'schedule:create', 'ux358-sdef',
        f'--app={dev["app_id"]}',
        '--for=development',
        '--calendar=hourly',
        '--command=/bin/true',
    )
    record_pty(
        'schedule-update-definition',
        ['schedule:update', 'ux358-sdef', f'--app={dev["app_id"]}', '--for=development', '--calendar=hourly', '--command=/bin/false'],
        contains=['ux358-sdef'],
    )
    record_pty('schedule-list', ['schedule:list'], contains=['ux358-tick', 'Request ID:'])
    pipe('schedule-list-pipe', ['schedule:list', '--no-ansi'], contains=['ux358-tick'])
    record_pty('schedule-show', ['schedule:show', created['id']], contains=['ux358-tick'])
    record_pty('schedule-run', ['schedule:run', created['id']], contains=['ux358-tick'])
    record_pty('schedule-logs', ['schedule:logs', created['id'], '--lines=20'], contains=['Request ID:'])
    record_pty('schedule-enable', ['schedule:enable', created['id']], contains=['ux358-tick'])
    record_pty(
        'schedule-destroy-json-no-yes',
        ['schedule:destroy', created['id'], '--json'],
        expected=1,
        error_code='input.confirmation_required',
    )

elif args.stage == 'consent':
    dev = e2e_dev()
    processes = read('process:list', f'--instance={dev["id"]}')['processes']
    sleep = next(p for p in processes if p['name'] == 'ux358-sleep')
    record_pty(
        'process-destroy-default-no',
        ['process:destroy', str(sleep['id'])],
        expected=1,
        contains=['cancelled'],
        input_actions=[{'wait_for': 'No', 'send': '\r'}],
    )
    still = read('process:list', f'--instance={dev["id"]}')
    assert any(p['id'] == sleep['id'] for p in still['processes'])
    record_pty(
        'process-destroy-cancel',
        ['process:destroy', str(sleep['id'])],
        expected=1,
        contains=['cancelled'],
        input_actions=[{'wait_for': 'No', 'send': '\x03'}],
    )
    record_pty(
        'process-destroy-eof',
        ['process:destroy', str(sleep['id'])],
        expected=1,
        contains=['cancelled'],
        input_actions=[{'wait_for': 'No', 'send': '\x04'}],
    )
    pipe('process-destroy-pipe', ['process:destroy', str(sleep['id']), '--no-ansi'], expected=1, contains=['--yes'])
    record_pty('process-destroy-yes', ['process:destroy', str(sleep['id']), '--yes'], contains=['removed'])
    missing = read('process:list', f'--instance={dev["id"]}')
    assert all(p['id'] != sleep['id'] for p in missing['processes'])
    schedules = read('schedule:list')['schedules']
    tick = next(s for s in schedules if s['name'] == 'ux358-tick')
    record_pty(
        'schedule-destroy-default-no',
        ['schedule:destroy', tick['id']],
        expected=1,
        contains=['Destroy Schedule'],
        input_actions=[{'wait_for': 'No', 'send': '\r'}],
    )
    record_pty('schedule-destroy-yes', ['schedule:destroy', tick['id'], '--yes'], contains=['ux358-tick'])

elif args.stage == 'coverage-report':
    expected = {
        'process:create': ['process-create-human', 'process-preset-create', 'process-preset-invalid-option'],
        'process:list': ['process-list', 'process-list-pipe', 'process-list-json'],
        'process:show': ['process-show-missing-app', 'process-show-definition'],
        'process:start': ['process-start'],
        'process:stop': ['process-stop'],
        'process:restart': ['process-restart'],
        'process:logs': ['process-logs', 'process-logs-pipe'],
        'process:destroy': ['process-destroy-json-no-yes', 'process-destroy-default-no', 'process-destroy-cancel', 'process-destroy-eof', 'process-destroy-pipe', 'process-destroy-yes'],
        'process:update': ['process-update-definition'],
        'schedule:create': ['schedule-create-human'],
        'schedule:list': ['schedule-list', 'schedule-list-pipe'],
        'schedule:show': ['schedule-show'],
        'schedule:run': ['schedule-run'],
        'schedule:logs': ['schedule-logs'],
        'schedule:enable': ['schedule-enable'],
        'schedule:destroy': ['schedule-destroy-json-no-yes', 'schedule-destroy-default-no', 'schedule-destroy-yes'],
        'schedule:update': ['schedule-update-definition'],
    }
    found = {}
    missing = []
    for command, labels in expected.items():
        found[command] = []
        for name in labels:
            matches = list(args.state.rglob(name + '/verify.json')) + list(args.state.rglob(name + '.stdout'))
            if not matches:
                missing.append(name)
                continue
            verify_files = [p for p in matches if p.name == 'verify.json']
            if verify_files:
                verify = json.loads(verify_files[0].read_text())
                assert verify.get('passed') is True, (name, verify)
                assert verify.get('failures') == [], (name, verify)
            found[command].append(name)
    assert missing == [], missing
    save(root / 'coverage-aggregate.json', {
        'commands': sorted(expected),
        'found': found,
        'missing': missing,
        'complete': missing == [] and set(expected) == set(found),
    })

save(root / 'records.json', records)
print(json.dumps({'stage': args.stage, 'passed': True, 'records': len(records)}), flush=True)
