#!/usr/bin/env python3
"""Record real App instance operations and verify their resulting guest state."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import sys
import termios
import signal
import uuid
import re
import importlib.util
import time

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    stderr_file = open(os.environ['ORB356_CAPTURE_STDERR'], 'wb') if os.environ.get('ORB356_CAPTURE_STDERR') else None
    child = subprocess.Popen(sys.argv[3:], stderr=stderr_file)
    signal.signal(signal.SIGINT, signal.SIG_IGN)
    code = child.wait()
    if stderr_file is not None:
        stderr_file.close()
    after = termios.tcgetattr(0)
    Path(sys.argv[2]).write_text(json.dumps({'before': repr(before), 'after': repr(after), 'equal': before == after, 'exit': code}))
    sys.exit(code)

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--stage', choices=['register', 'retry'], required=True)
parser.add_argument('--visible', action='store_true')
parser.add_argument('--discovery-manifest', type=Path)
args = parser.parse_args()
os.umask(0o077)
source = Path('/home/orbit/orbit')
if args.discovery_manifest:
    manifest = json.loads(args.discovery_manifest.read_text())
    assert manifest['candidate'] == args.candidate
    for entry in manifest['files']:
        data = os.readlink(source / entry['path']).encode() if entry['symlink'] else (source / entry['path']).read_bytes()
        assert hashlib.sha256(data).hexdigest() == entry['sha256'], entry['path']
else:
    assert subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() == args.candidate
    assert not subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()
root = args.state / args.stage
root.mkdir(parents=True)
private = root / 'gateway-home'
private.mkdir()
shutil.copyfile(Path.home() / '.orbit/config.json', private / 'config.json')
known_hosts = root / 'known_hosts'

if (Path.home() / '.orbit/ssh/known_hosts').exists():
    shutil.copyfile(Path.home() / '.orbit/ssh/known_hosts', known_hosts)
env = dict(os.environ, ORBIT_HOME=str(private), TERM='xterm-256color', LC_ALL='C.UTF-8', PAO_DISABLE='1')
for key in ['NO_COLOR', 'FORCE_COLOR', 'CLICOLOR', 'COLUMNS', 'LINES']:
    env.pop(key, None)
launcher = source / 'apps/cli/orbit'
recorder = source / '.agents/skills/verifying-cli-output/scripts'
observations, records = [], []

def save(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')

def read(*argv, expected=0, error=None):
    command = ['php', str(launcher), *map(str, argv), '--json']
    result = subprocess.run(command, cwd=source, env=env, capture_output=True, input=b'', timeout=240)
    assert result.returncode == expected, (argv, result.returncode, result.stdout, result.stderr)
    assert result.stderr == b'' and b'\x1b' not in result.stdout, (argv, result.stderr)
    payload = json.loads(result.stdout)
    if error:
        assert payload['error']['code'] == error, (argv, payload)
    observations.append({'argv': command, 'exit': result.returncode, 'payload': payload})
    save(root / 'observations.json', observations)
    return payload

REQUEST_ID_UUID = re.compile(
    r'\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z',
    re.I,
)


def assert_request_id(value, *, actual_gateway, label):
    if actual_gateway:
        assert isinstance(value, str) and REQUEST_ID_UUID.fullmatch(value), (label, 'gateway request_id', value)
    else:
        assert value is None, (label, 'local validation request_id', value)


def command_name(argv):
    return next((part for part in argv if ':' in part and not part.startswith('-')), None)


def json_success_schema(argv):
    if command_name(argv) == 'instance:clone':
        return {
            'shape': 'clone-aggregate',
            'required': ['target_id', 'configured_branch', 'preview_domain', 'selected_release', 'request_ids'],
            'request_ids': ['clone', 'releases'],
        }
    return {'shape': 'entity'}


def assert_json_machine(machine, *, actual_gateway, error_code, json_payload, label, argv):
    assert isinstance(machine, dict), (label, machine)
    if error_code is not None:
        error = machine.get('error')
        assert isinstance(error, dict), (label, machine)
        missing = {'code', 'message', 'request_id'} - set(error)
        assert not missing, (label, 'missing error keys', missing, error)
        extra = set(error) - {'code', 'message', 'request_id', 'details'}
        assert not extra, (label, 'unexpected error keys', extra, error)
        assert error['code'] == error_code, (label, error.get('code'), error_code)
        assert isinstance(error['message'], str) and error['message'], (label, error)
        assert_request_id(error['request_id'], actual_gateway=actual_gateway, label=label)
        if 'details' in error:
            assert isinstance(error['details'], dict), (label, error)
    else:
        assert 'error' not in machine, (label, machine)
        schema = json_success_schema(argv)
        if schema['shape'] == 'clone-aggregate':
            missing = set(schema['required']) - set(machine)
            extra = set(machine) - set(schema['required'])
            assert not missing and not extra, (label, missing, extra, machine)
            assert isinstance(machine['target_id'], int) and machine['target_id'] > 0, (label, machine)
            assert isinstance(machine['configured_branch'], str) and machine['configured_branch'], (label, machine)
            assert isinstance(machine['preview_domain'], str) and machine['preview_domain'], (label, machine)
            assert machine['selected_release'] is None or isinstance(machine['selected_release'], str), (label, machine)
            ids = machine['request_ids']
            assert isinstance(ids, dict) and set(ids) == set(schema['request_ids']), (label, ids)
            for key in schema['request_ids']:
                assert_request_id(ids[key], actual_gateway=actual_gateway, label=label + '.' + key)
        else:
            assert 'request_id' in machine, (label, machine)
            assert_request_id(machine['request_id'], actual_gateway=actual_gateway, label=label)
    if json_payload is not None:
        assert without_request(machine) == without_request(json_payload), (label, machine, json_payload)


def json_frame_token_rationale(*, actual_gateway, error_code, argv):
    schema = None if error_code is not None else json_success_schema(argv)
    return {
        'frame_token_contains': 'inapplicable',
        'reason': (
            'verify.py searches newline-joined reconstructed PTY frame lines. '
            'Compact JSON longer than the 100-column capture width wraps, splitting keys such as request_id. '
            'Frame-token contains and final_contains are therefore inapplicable for JSON TTY cases. '
            'Required keys and error codes are validated on capture/raw.bin. '
            'Expectations are derived from the documented envelope and case type before looking at frames.'
        ),
        'envelope': 'GatewayFailureRenderer::json {"error":{"code","message",optional details,"request_id"}} for failures; success JSON uses the command contract (entity request_id or clone request_ids)',
        'success_schema': schema,
        'case_type': 'gateway-response' if actual_gateway else 'local-validation',
        'expected_error_code': error_code,
        'expected_request_id': None if error_code is None and schema and schema['shape'] == 'clone-aggregate' else ('uuid' if actual_gateway else None),
        'raw_payload_checks': [
            'json.loads(capture/raw.bin)',
            'stderr.bin empty',
            'no ANSI in raw.bin',
            'semantic equality with json_payload when supplied (request_id stripped)',
            'required keys and predetermined error_code on the full raw payload',
            'request_id is null for local validation and a UUID for Gateway responses',
        ],
        'verify.py': {
            'contains': [],
            'final_contains': [],
            'absent': ['Request ID:'],
            'why_absent': (
                'JSON mode uses the machine envelope, not the human Request ID: label. '
                'This satisfies verify.py observable output without selecting JSON key tokens from observed frames.'
            ),
        },
    }


def record(label, argv, contains, *, expected=0, key=None, prompt=None, columns=100, plain=False, table=None, table_headers=None, fallback=None, detail=None, json_payload=None, absent=None, animation=None, input_actions=None, max_after_input=None, wrapped_contains=None, actual_gateway=True, error_code=None):
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--no-ansi' if plain else '--ansi']
    capture = [sys.executable, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
               '--candidate', args.candidate, '--label', label, '--columns', str(columns), '--rows', '100',
               '--timeout', '240', '--idle-timeout', '240' if '--json' in argv else '60']
    if not args.visible:
        capture.append('--no-live')
    if input_actions is not None:
        save(out / 'inputs.json', input_actions)
        capture += ['--input-plan', str(out / 'inputs.json')]
    if key is not None:
        plan = [{'wait_for': prompt, 'send': 'y' if key == 'n' else key}]
        if key == 'n':
            plan += [{'wait_for': 'Yes', 'send': 'n'}, {'wait_for': 'No', 'send': '\r'}]
        elif key == 'y':
            plan.append({'wait_for': 'Yes', 'send': '\r'})
        save(out / 'inputs.json', plan)
        capture += ['--input-plan', str(out / 'inputs.json')]
    capture += ['--', sys.executable, str(Path(__file__).resolve()), '--child', str(out / 'terminal.json'), *command]
    print('$ ' + shlex.join(command), flush=True)
    capture_env = dict(env)
    if '--json' in argv:
        capture_env['ORB356_CAPTURE_STDERR'] = str(out / 'stderr.bin')
    result = subprocess.run(capture, cwd=source, env=capture_env, capture_output=not args.visible, timeout=255)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal'], label
    if max_after_input is not None:
        events = [json.loads(line) for line in (out / 'capture/input-events.jsonl').read_text().splitlines()]
        sent = next(event for event in reversed(events) if event['bytes'] > 0)
        summary = json.loads((out / 'capture/summary.json').read_text())
        elapsed = summary['duration_seconds'] - sent['elapsed']
        save(out / 'signal-response.json', {'seconds': elapsed, 'maximum': max_after_input})
        assert elapsed <= max_after_input, (label, elapsed, max_after_input)

    if '--json' in argv:
        assert (out / 'stderr.bin').read_bytes() == b'', label
        raw = (out / 'capture/raw.bin').read_bytes()
        assert b'\x1b' not in raw, label
        machine = json.loads(raw)
        if isinstance(machine, dict) and 'error' in machine:
            assert error_code is not None, (label, 'JSON error capture requires a predetermined error_code')
        elif error_code is not None:
            raise AssertionError((label, 'predetermined error_code but payload is not an error envelope', machine))
        assert_json_machine(machine, actual_gateway=actual_gateway, error_code=error_code, json_payload=json_payload, label=label, argv=argv)
        save(out / 'json-frame-tokens.json', json_frame_token_rationale(actual_gateway=actual_gateway, error_code=error_code, argv=argv))
    captured_frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
    assert captured_frames[-1]['cursor']['hidden'] is False, label
    if wrapped_contains:
        compact = ''.join(''.join(captured_frames[-1]['lines']).replace('│', '').split())
        for expected_text in wrapped_contains:
            assert ''.join(expected_text.split()) in compact, (label, expected_text, compact)
        save(out / 'expected-wrapped.json', wrapped_contains)

    expectation = {'candidate': args.candidate, 'label': label, 'exit_code': expected, 'contains': contains if table is None else ['Request ID:'], 'final_contains': contains if table is None else ['Request ID:'], 'max_first_output_seconds': 240 if '--json' in argv else 3}
    if '--json' in argv:
        del expectation['contains']
        del expectation['final_contains']
        expectation['absent'] = ['Request ID:']
        if absent:
            expectation['absent'] = list(dict.fromkeys([*absent, 'Request ID:']))
    elif absent:
        expectation['absent'] = absent
    if expectation.get('absent'):
        assert all(value not in (out / 'capture/transcript.txt').read_text() for value in expectation['absent']), label
    if animation and not plain:
        expectation['animation_rows'] = [animation]
    if table is not None:
        frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
        assert_wrapped_table(frames[-1]['lines'], table, table_headers or ['ID', 'ROLE', 'STATUS', 'FAILED STEP', 'ERROR CODE'])
        save(out / 'expected-table.json', table)
    if fallback is not None:
        compact = ''.join(''.join(captured_frames[-1]['lines']).split())
        expected_records = ''.join('Record' + str(i + 1) + ''.join(''.join(str(k).split()) + ':' + ''.join(str(v).split()) for k, v in row) for i, row in enumerate(fallback))
        assert expected_records in compact, (label, expected_records, compact)
        save(out / 'expected-records.json', fallback)
    save(out / 'expectation.json', expectation)
    verified = subprocess.run([sys.executable, str(recorder / 'verify.py'), '--capture', str(out / 'capture'), '--expect', str(out / 'expectation.json')], capture_output=True, text=True)
    (out / 'verify.json').write_text(verified.stdout)
    assert verified.returncode == 0, (label, verified.stdout, verified.stderr)
    if plain:
        assert b'\x1b' not in (out / 'capture/raw.bin').read_bytes()
    record = {'label': label, 'argv': command, 'candidate': args.candidate, 'environment': 'disposable-incus', 'request_observation': 'gateway' if actual_gateway else 'local-validation-no-request', 'actual_gateway': actual_gateway, 'exit': expected, 'columns': columns, 'passed': True}
    if '--json' in argv:
        record['json_frame_token_contains'] = 'inapplicable'
        record['error_code'] = error_code
    save(out / 'case.json', record)
    records.append(record)
    save(root / 'records.json', records)
    print(json.dumps({'label': label, 'passed': True}), flush=True)
    return out

def remote(node, command, public=False):
    host = node['public_ssh_host'] if public else node['wireguard_ip']
    port = str(node['public_ssh_port']) if public else '22'
    argv = ['ssh', '-i', str(Path.home() / '.orbit/ssh/id_ed25519'), '-p', port,
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known_hosts),
            '-o', 'ConnectTimeout=10', node['user'] + '@' + host, command]
    result = subprocess.run(argv, capture_output=True, text=True, timeout=60)
    assert result.returncode == 0, (node['name'], command, result.returncode, result.stdout, result.stderr)
    observations.append({'node': node['name'], 'remote': command, 'public': public, 'exit': result.returncode, 'stdout': result.stdout})
    save(root / 'observations.json', observations)
    return result.stdout

def assert_wrapped_table(lines, expected, expected_headers):
    # Role tables have an unwrapped numeric ID in their first column.
    # Join continuation cells within each ID row, never across columns or rows.
    headers, rows, current = [''] * len(expected_headers), [], None
    in_table = in_body = False
    for line in lines:
        if '┌' in line and '┬' in line:
            in_table = True
            continue
        if not in_table:
            continue
        if '┼' in line:
            in_body = True
            continue
        if '└' in line:
            break
        cells = line.split('│')[1:-1]
        if len(cells) != len(expected_headers):
            continue
        cells = [''.join(cell.split()) for cell in cells]
        if not in_body:
            headers = [a + b for a, b in zip(headers, cells)]
        elif cells[0]:
            if current is not None:
                rows.append(current)
            current = cells
        else:
            assert current is not None
            current = [a + b for a, b in zip(current, cells)]
    if current is not None:
        rows.append(current)
    assert headers == [''.join(h.split()) for h in expected_headers], headers
    assert rows == [[''.join(str(v).split()) for v in row] for row in expected], (rows, expected)


def without_request(value):
    if isinstance(value, dict):
        return {k: without_request(v) for k, v in value.items() if k != 'request_id'}
    if isinstance(value, list):
        return [without_request(v) for v in value]
    return value

instances = read('instance:list')['app_instances']
sample = next(i for i in instances if i['name'] == 'e2e-dev')
app = read('app:show', sample['app_id'])
assert Path(sample['checkout_path']).is_dir(), 'Registration must run on the source app-dev Node.'
before = without_request(read('instance:list'))
if args.stage == 'register':
    local_source = root / 'local-source'
    subprocess.run(['git', 'clone', '--quiet', '--no-hardlinks', sample['checkout_path'], str(local_source)], check=True, timeout=60)
    subprocess.run(['git', '-C', str(local_source), 'remote', 'set-url', 'origin', app['repository_url']], check=True)
    def source_manifest(path):
        tracked = subprocess.check_output(['git', '-C', str(path), 'ls-files', '-z']).split(b'\0')
        files = {}
        for name in tracked:
            if name:
                f = path / os.fsdecode(name)
                if f.is_symlink():
                    files[os.fsdecode(name)] = {'symlink': os.readlink(f)}
                elif f.is_file():
                    files[os.fsdecode(name)] = {'sha256': hashlib.sha256(f.read_bytes()).hexdigest()}
        return {'commit': subprocess.check_output(['git', '-C', str(path), 'rev-parse', 'HEAD'], text=True).strip(), 'files': files}
    
    source_before = source_manifest(local_source)
    (local_source / 'ux356-preserved.txt').write_text('registration-content-sentinel\n')
    save(root / 'source-before.json', source_before)
    name = 'ux356-register-' + uuid.uuid4().hex[:8]
    command = ['instance:register', '--path=' + str(local_source), '--app=' + str(app['id']), '--name=' + name]
    before = without_request(read('instance:list'))
    for label, key in [('default-no', '\r'), ('cancel', '\x03'), ('eof', '\x04')]:
        record('register-' + label, command, ['Registration was cancelled.'], expected=1, key=key, prompt='Yes')
        assert local_source.is_dir() and source_manifest(local_source) == source_before and without_request(read('instance:list')) == before
    refusal = read(*command, expected=1, error='input.confirmation_required')
    record('register-json-refusal', [*command, '--json'], [], expected=1, json_payload=refusal, actual_gateway=False, error_code='input.confirmation_required')
    assert local_source.is_dir() and without_request(read('instance:list')) == before
    for option, value in [('--root', '../public'), ('--default-branch', 'bad branch')]:
        invalid = read(*command, option + '=' + value, '--yes', expected=1, error='validation.failed')
        record('register-invalid-' + option.removeprefix('--'), [*command, option + '=' + value, '--yes'], ['The request data is invalid.'], expected=1)
        assert local_source.is_dir() and source_manifest(local_source) == source_before and without_request(read('instance:list')) == before
    record('register', command, ['App instance: ' + name, 'Registered sources', '1/1'], key='y', prompt='Yes', animation={'name':'registration', 'pattern':r'(?P<glyph>[○◉]) Registering source', 'terminal_pattern':r'● Registered source', 'minimum_changes':2, 'min_interval':0.15, 'max_interval':0.65})
    registered = next(i for i in read('instance:list')['app_instances'] if i['name'] == name)
    assert registered['status'] == 'active' and registered['app_id'] == app['id'] and registered['node_id'] == sample['node_id']
    assert Path(registered['checkout_path']).is_dir()
    assert not local_source.exists(), 'Successful registration must relocate the original source'
    assert source_manifest(Path(registered['checkout_path'])) == source_before
    assert (Path(registered['checkout_path']) / 'ux356-preserved.txt').read_text() == 'registration-content-sentinel\n'
    save(root / 'source-after.json', source_manifest(Path(registered['checkout_path'])))
    save(root / 'registered.json', registered)
    record('remove-registration', ['instance:destroy', registered['id'], '--yes', '--force'], ['removed.', 'Removal: ' + name])
    assert not Path(registered['checkout_path']).exists()
    assert without_request(read('instance:list')) == before
elif args.stage == 'retry':
    # A local repository with unresolved values proves immediate validation and retry,
    # then declines ownership before any request to register the source.
    unresolved = root / 'unresolved-source'
    unresolved.mkdir()
    for argv in [
        ['git', 'init', '--quiet', '--initial-branch=main', str(unresolved)],
        ['git', '-C', str(unresolved), '-c', 'user.name=CLI UX Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '--allow-empty', '-m', 'Disposable registration fixture'],
        ['git', '-C', str(unresolved), 'remote', 'add', 'origin', 'https://example.invalid/ux356/source.git'],
    ]:
        subprocess.run(argv, check=True)
    inputs = [
        {'wait_for': '\x1b[36mDefault branch\x1b[39m', 'send': 'bad branch\r'},
        {'wait_for': 'Enter a valid Git branch name.', 'send': '\x7f' * 10 + 'main\r'},
        {'wait_for': '\x1b[36mWeb root\x1b[39m', 'send': '../public\r'},
        {'wait_for': 'Enter a normalized relative web path.', 'send': '\x7f' * 9 + 'public\r'},
        {'wait_for': 'allowing relocation and later removal?', 'send': '\r'},
    ]
    record('registration-retry-and-decline', ['instance:register', '--path=' + str(unresolved)], ['Registration was cancelled.'], expected=1, input_actions=inputs)
    assert unresolved.is_dir() and without_request(read('instance:list')) == before
    # Successful accept uses a real checkout of the topology-owned App. Selected App
    # skips branch/root prompts; wait_for matches the ownership question in raw bytes.
    accept_source = root / 'accept-source'
    subprocess.run(['git', 'clone', '--quiet', '--no-hardlinks', sample['checkout_path'], str(accept_source)], check=True, timeout=60)
    subprocess.run(['git', '-C', str(accept_source), 'remote', 'set-url', 'origin', app['repository_url']], check=True)
    accept_name = 'ux356-retry-' + uuid.uuid4().hex[:8]
    accept = [
        {'wait_for': 'allowing relocation and later removal?', 'send': 'y'},
        {'wait_for': 'Yes', 'send': '\r'},
    ]
    record('registration-retry-and-accept', ['instance:register', '--path=' + str(accept_source), '--app=' + str(app['id']), '--name=' + accept_name], ['Registered sources'], input_actions=accept, animation={'name': 'registration', 'pattern': r'(?P<glyph>[○◉]) Registering source', 'terminal_pattern': r'● Registered source', 'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 0.65})
    accepted = next(i for i in read('instance:list')['app_instances'] if i['name'] == accept_name)
    assert accepted['status'] == 'active' and accepted['app_id'] == app['id']
    assert accepted['effective_root'] == app['root'] and not accept_source.exists()
    record('remove-retry-accept', ['instance:destroy', accepted['id'], '--yes', '--force'], ['removed.'])
    assert without_request(read('instance:list')) == before
    secret = 'ux356-register-secret'
    credential = root / 'credential-source'
    subprocess.run(['git', 'clone', '--quiet', '--no-hardlinks', sample['checkout_path'], str(credential)], check=True, timeout=60)
    subprocess.run(['git', '-C', str(credential), 'remote', 'set-url', 'origin', 'https://ux356-user:' + secret + '@example.test/ux356/source.git'], check=True)
    cred = read('instance:register', '--path=' + str(credential), '--app=' + str(app['id']), '--json', expected=1, error='instance.source_invalid')
    record('register-credential-origin', ['instance:register', '--path=' + str(credential), '--app=' + str(app['id'])], ['The current path is not a supported Git checkout or worktree.'], expected=1, absent=[secret, 'ux356-user'])
    assert secret not in json.dumps(cred) and Path(credential).is_dir()
    record('register-cancel-at-branch', ['instance:register', '--path=' + str(unresolved)], ['Registration was cancelled.'], expected=1, input_actions=[{'wait_for': '\x1b[36mDefault branch\x1b[39m', 'send': '\x03'}])
    assert unresolved.is_dir() and without_request(read('instance:list')) == before
    worktree_main = root / 'worktree-main'
    worktree_linked = root / 'worktree-linked'
    subprocess.run(['git', 'clone', '--quiet', '--no-hardlinks', sample['checkout_path'], str(worktree_main)], check=True, timeout=60)
    subprocess.run(['git', '-C', str(worktree_main), 'remote', 'set-url', 'origin', app['repository_url']], check=True)
    subprocess.run(['git', '-C', str(worktree_main), 'worktree', 'add', '--detach', str(worktree_linked)], check=True)
    wt_name = 'ux356-trees-' + uuid.uuid4().hex[:8]
    record('register-include-worktrees', ['instance:register', '--path=' + str(worktree_main), '--include-worktrees', '--app=' + str(app['id']), '--name=' + wt_name], ['Registered sources'], input_actions=[{'wait_for': 'allowing relocation and later removal?', 'send': 'y'}, {'wait_for': 'Yes', 'send': '\r'}])
    trees = next(i for i in read('instance:list')['app_instances'] if i['name'] == wt_name)
    assert trees['status'] == 'active' and not worktree_main.exists()
    record('remove-include-worktrees', ['instance:destroy', trees['id'], '--yes', '--force'], ['removed.'])
    assert without_request(read('instance:list')) == before
print(json.dumps({'stage': args.stage, 'passed': True, 'records': len(records), 'root': str(root)}), flush=True)
