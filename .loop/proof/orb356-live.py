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
import sqlite3

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    stderr_file = open(os.environ['ORB356_CAPTURE_STDERR'], 'wb') if os.environ.get('ORB356_CAPTURE_STDERR') else None
    child = subprocess.Popen(sys.argv[3:], stderr=stderr_file)

    def forward(signum, _frame):
        try:
            child.send_signal(signum)
        except ProcessLookupError:
            pass

    signal.signal(signal.SIGINT, forward)
    signal.signal(signal.SIGTERM, forward)
    delay = float(os.environ.get('ORB356_SIGNAL_AFTER') or 0)
    sig_name = os.environ.get('ORB356_SIGNAL')
    marker = os.environ.get('ORB356_PENDING_MARKER')
    after_arrival = float(os.environ.get('ORB356_SIGNAL_AFTER_ARRIVAL') or 0)
    if sig_name and (delay > 0 or marker):
        import threading

        def killer():
            if marker:
                deadline = time.monotonic() + 30
                while time.monotonic() < deadline:
                    if Path(marker).exists():
                        time.sleep(after_arrival)
                        break
                    time.sleep(0.02)
                else:
                    return
            elif delay > 0:
                time.sleep(delay)
            try:
                child.send_signal(getattr(signal, sig_name))
            except ProcessLookupError:
                pass
            if marker:
                Path(marker + '.signaled').write_text(json.dumps({'signaled': time.time()}) + '\n')

        threading.Thread(target=killer, daemon=True).start()
    code = child.wait()
    if stderr_file is not None:
        stderr_file.close()
    after = termios.tcgetattr(0)
    Path(sys.argv[2]).write_text(json.dumps({'before': repr(before), 'after': repr(after), 'equal': before == after, 'exit': code}))
    sys.exit(code)

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--stage', choices=['prepare', 'reads', 'reads-narrow', 'source', 'invalid', 'mutation-modes', 'consent-automation', 'unpublished-source', 'signals-empty', 'clone-partial', 'pending-http', 'empty-list', 'native-visual', 'fixture-cleanup', 'coverage-report'], required=True)
parser.add_argument('--resume-before-transfer', action='store_true', help='Discovery only: prior source/steps completed')
parser.add_argument('--resume-before-production-remove', action='store_true', help='Discovery only: retry production content-retention removal')
parser.add_argument('--resume-after-mutation-clone', action='store_true', help='Discovery only: json-tty create and clone already succeeded')
parser.add_argument('--resume-after-mutation-pipe-create', action='store_true', help='Discovery only: json-tty finished and pipe create already succeeded')
parser.add_argument('--resume-after-reads-wide', action='store_true', help='Discovery only: wide read recordings already passed')
parser.add_argument('--resume-after-signals-create', action='store_true', help='Discovery only: development interrupt instance already created')
parser.add_argument('--resume-after-empty-steps', action='store_true', help='Discovery only: production empty-steps already recorded')
parser.add_argument('--resume-after-clone-partial-human', action='store_true', help='Discovery only: clone-partial JSON and human captures already exist')
parser.add_argument('--resume-after-native-list', action='store_true', help='Discovery only: inspect a preserved native-list capture; do not recapture')
parser.add_argument('--resume-after-native-show', action='store_true', help='Discovery only: skip native-show-prod when it already exists')
parser.add_argument('--resume-after-clone', type=Path, help='Discovery only: directory containing created.json and cloned.json')
parser.add_argument('--visible', action='store_true')
parser.add_argument('--node-scope', choices=['gateway', 'app-dev'], default='gateway', help='coverage-report required-evidence set')
parser.add_argument('--discovery-manifest', type=Path)
args = parser.parse_args()
assert not args.resume_before_transfer or args.resume_after_clone, 'Transfer resume requires prior fixture identity'
assert not args.resume_before_production_remove or args.resume_after_clone, 'Production-remove resume requires clone identity'
assert not args.resume_after_mutation_clone or (args.discovery_manifest and args.stage == 'mutation-modes'), 'Mutation clone resume is discovery-only'
assert not args.resume_after_mutation_pipe_create or (args.discovery_manifest and args.stage == 'mutation-modes'), 'Mutation pipe-create resume is discovery-only'
assert not args.resume_after_reads_wide or (args.discovery_manifest and args.stage in ['reads', 'reads-narrow']), 'Reads-wide resume is discovery-only'
assert not args.resume_after_signals_create or (args.discovery_manifest and args.stage == 'signals-empty'), 'Signals-create resume is discovery-only'
assert not args.resume_after_empty_steps or (args.discovery_manifest and args.stage == 'signals-empty'), 'Empty-steps resume is discovery-only'
assert not args.resume_after_clone_partial_human or (args.discovery_manifest and args.stage == 'clone-partial'), 'Clone-partial resume is discovery-only'
assert not args.resume_after_native_list or (args.discovery_manifest and args.stage == 'native-visual'), 'Native-list resume is discovery-only'
assert not args.resume_after_native_show or (args.discovery_manifest and args.stage == 'native-visual'), 'Native-show resume is discovery-only'
assert not args.resume_after_clone or (args.discovery_manifest and args.stage == 'source'), 'Resume is discovery-only'
os.umask(0o077)
source = Path('/home/orbit/orbit')
FIXTURE_DIR = Path(__file__).resolve().parent
if args.discovery_manifest:
    manifest = json.loads(args.discovery_manifest.read_text())
    assert manifest['candidate'] == args.candidate
    for entry in manifest['files']:
        data = os.readlink(source / entry['path']).encode() if entry['symlink'] else (source / entry['path']).read_bytes()
        assert hashlib.sha256(data).hexdigest() == entry['sha256'], entry['path']
else:
    assert subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() == args.candidate
    assert not subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()
resume_existing = args.resume_before_production_remove or args.resume_after_mutation_clone or args.resume_after_mutation_pipe_create or args.resume_after_reads_wide or args.resume_after_signals_create or args.resume_after_empty_steps or args.resume_after_clone_partial_human or args.resume_after_native_list or args.resume_after_native_show
root = args.state / args.stage
root.mkdir(parents=True, exist_ok=resume_existing)
private = root / 'gateway-home'
private.mkdir(exist_ok=resume_existing)
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
if resume_existing and (root / 'records.json').exists():
    records.extend(json.loads((root / 'records.json').read_text()))
if resume_existing and (root / 'observations.json').exists():
    observations.extend(json.loads((root / 'observations.json').read_text()))

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
# ConsoleInterrupted.php: parent::__construct('Command interrupted.', 128 + $signal)
SIGINT_EXIT = 128 + signal.SIGINT
SIGTERM_EXIT = 128 + signal.SIGTERM
INJECTED_RELEASES_REQUEST_ID = '0198e15c-bf97-7c23-8f1f-61b8fe67a845'


def assert_request_id(value, *, actual_gateway, label):
    if actual_gateway:
        assert isinstance(value, str) and REQUEST_ID_UUID.fullmatch(value), (label, 'gateway request_id', value)
    else:
        assert value is None, (label, 'local validation request_id', value)


def command_name(argv):
    return next((part for part in argv if ':' in part and not part.startswith('-')), None)


# Predetermined from command contracts, not from observed frames.
# Detail trees use title-case labels and aligned values (no colon). One-line
# correlation uses "Request ID: {uuid}".
HUMAN_REQUEST_ID_FORMS = {
    'instance:create': ('detail-tree', ['Request ID']),
    'instance:show': ('detail-tree', ['Request ID']),
    'instance:update': ('detail-tree', ['Request ID']),
    'instance:clone': ('detail-tree', ['Clone request ID', 'Release request ID']),
    'instance:transfer': ('detail-tree', ['Request ID']),
    'instance:deploy-step:create': ('detail-tree', ['Request ID']),
    'instance:deploy-step:update': ('detail-tree', ['Request ID']),
    'instance:destroy': ('one-line', ['Request ID:']),
    'instance:deploy-step:destroy': ('one-line', ['Request ID:']),
    'instance:list': ('one-line', ['Request ID:']),
    'instance:deploy-step:list': ('one-line', ['Request ID:']),
    'instance:release:list': ('one-line', ['Request ID:']),
}
REQUEST_ID_BODY = r'[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'


def assert_human_request_id(text, argv, *, label):
    name = command_name(argv)
    assert name in HUMAN_REQUEST_ID_FORMS, (label, 'no predetermined human request-id form', name)
    form, fields = HUMAN_REQUEST_ID_FORMS[name]
    for field in fields:
        if form == 'one-line':
            pattern = re.compile(re.escape(field) + r' (' + REQUEST_ID_BODY + r')', re.I)
        else:
            pattern = re.compile(re.escape(field) + r' +(' + REQUEST_ID_BODY + r')', re.I)
        match = pattern.search(text)
        assert match, (label, form, field, text)
        assert_request_id(match.group(1), actual_gateway=True, label=label + '.' + field)
        if form == 'detail-tree':
            assert field + ':' not in text, (label, 'detail tree must not use the one-line colon form', field, text)


def json_success_schema(argv):
    # Predetermined from the command contract, not from observed frames.
    # Audited instance JSON success shapes used by this fixture:
    # - entity (top-level request_id): create, show, list, update, destroy, deploy-step:*,
    #   release:list, transfer (CLI subset plus request_id)
    # - clone-aggregate (request_ids.clone and request_ids.releases): instance:clone
    # Deploy streams remain NDJSON events and are not asserted here.
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


def record(label, argv, contains, *, expected=0, key=None, prompt=None, columns=100, plain=False, table=None, table_headers=None, fallback=None, detail=None, json_payload=None, absent=None, animation=None, input_actions=None, max_after_input=None, wrapped_contains=None, actual_gateway=True, error_code=None, wrap_header=None, wrap_expected=None):
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
        headers = table_headers or ['ID', 'ROLE', 'STATUS', 'FAILED STEP', 'ERROR CODE']
        reconstructed = assert_table_matches_api(captured_frames, table, headers, label=label)
        save(out / 'expected-table.json', table)
        save(out / 'reconstructed-table.json', reconstructed)
        save(out / 'table-screen.json', inspect_table_screen(captured_frames))
    if fallback is not None:
        # Last-frame cell text drops records that scrolled off a 40x100 PTY.
        # Fallback field preservation is the sequential raw stream.
        compact = ''.join(re.sub(r'\x1b\[[0-9;?]*[A-Za-z]', '', (out / 'capture/raw.bin').read_bytes().decode('utf-8', 'replace')).split())
        expected_records = ''.join('Record' + str(i + 1) + ''.join(''.join(str(k).split()) + ':' + ''.join(str(v).split()) for k, v in row) for i, row in enumerate(fallback))
        assert expected_records in compact, (label, expected_records[:240], compact[:240], len(expected_records), len(compact))
        request = re.search(r'RequestID:(' + REQUEST_ID_BODY + r')', compact, re.I)
        assert request, (label, 'fallback one-line Request ID: UUID', compact[-120:])
        assert_request_id(request.group(1), actual_gateway=True, label=label)
        save(out / 'expected-records.json', fallback)
        save(out / 'fallback-source.json', {'source': 'capture/raw.bin', 'reason': 'last PTY frame cannot hold every fallback record'})
        if not plain:
            assert b'\x1b' in (out / 'capture/raw.bin').read_bytes(), (label, 'decorated fallback must keep ANSI')
            assert wrap_header is not None and wrap_expected is not None, (label, 'decorated fallback requires a predetermined wrap target')
            save(out / 'fallback-frames.json', assert_fallback_scroll_frames(captured_frames, fallback, label=label, columns=columns, wrap_header=wrap_header, wrap_expected=wrap_expected, wrap_record_number=len(fallback)))
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

def split_table_cells(line, n):
    if '│' not in line:
        return None
    cells = line.split('│')[1:-1]
    if len(cells) != n:
        return None
    return [''.join(cell.split()) for cell in cells]


def parse_wrapped_table(lines, expected_headers):
    # Role tables have an unwrapped numeric ID in their first column.
    # Join continuation cells within each ID row, never across columns or rows.
    n = len(expected_headers)
    headers, rows, current = [''] * n, [], None
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
        cells = split_table_cells(line, n)
        if cells is None:
            continue
        if not in_body:
            headers = [a + b for a, b in zip(headers, cells)]
        elif cells[0]:
            if current is not None:
                rows.append(current)
            current = cells
        elif current is not None:
            current = [a + b for a, b in zip(current, cells)]
    if current is not None:
        rows.append(current)
    return headers, rows


def parse_wrapped_table_body(lines, n, header0=None):
    # Body rows without requiring the header to remain on screen.
    # When the top border is visible, skip wrapped header cells until ┼.
    rows, current = [], None
    saw_bottom = False
    saw_top = False
    in_body = False
    for line in lines:
        if '┌' in line and '┬' in line:
            saw_top = True
            in_body = False
            continue
        if '┼' in line:
            in_body = True
            continue
        if '└' in line:
            saw_bottom = True
            if current is not None:
                rows.append(current)
                current = None
            break
        if saw_top and not in_body:
            continue
        cells = split_table_cells(line, n)
        if cells is None:
            continue
        if header0 and cells[0] == header0:
            continue
        if cells[0]:
            if current is not None:
                rows.append(current)
            current = cells
        elif current is not None:
            current = [a + b for a, b in zip(current, cells)]
    trailing_open = current is not None and not saw_bottom
    if current is not None and saw_bottom:
        rows.append(current)
    return rows, trailing_open


def reconstruct_wrapped_table_across_frames(frames, expected_headers):
    n = len(expected_headers)
    header0 = ''.join(expected_headers[0].split())
    headers = [''] * n
    by_id = {}
    order = []
    for frame in frames:
        has_top = any('┌' in line and '┬' in line for line in frame['lines'])
        if has_top:
            parsed_headers, rows = parse_wrapped_table(frame['lines'], expected_headers)
            if any(parsed_headers):
                headers = parsed_headers
        else:
            rows, _trailing_open = parse_wrapped_table_body(frame['lines'], n, header0=header0)
        for row in rows:
            if not row[0] or row[0] == header0:
                continue
            if row[0] not in by_id:
                order.append(row[0])
            prev = by_id.get(row[0])
            if prev is None or sum(len(cell) for cell in row) >= sum(len(cell) for cell in prev):
                by_id[row[0]] = row
    return {'headers': headers, 'rows': [by_id[key] for key in order], 'ids': order}


def compact_table_rows(expected):
    return [[''.join(str(v).split()) for v in row] for row in expected]


def assert_table_matches_api(frames, expected, expected_headers, *, label):
    reconstructed = reconstruct_wrapped_table_across_frames(frames, expected_headers)
    compact_headers = [''.join(h.split()) for h in expected_headers]
    compact_rows = compact_table_rows(expected)
    expected_ids = [row[0] for row in compact_rows]
    assert reconstructed['headers'] == compact_headers, (label, reconstructed['headers'], compact_headers)
    assert reconstructed['ids'] == expected_ids, (label, 'first-seen order vs API', reconstructed['ids'], expected_ids)
    assert reconstructed['rows'] == compact_rows, (label, reconstructed['rows'], compact_rows)
    return reconstructed


def validate_wrapped_table_parser():
    headers = ['ID', 'NAME']

    def frame(lines):
        return {'lines': lines, 'cells': [], 'elapsed': 0, 'cursor': {'hidden': False}}

    visible = [
        '┌────┬──────┐',
        '│ ID │ NAME │',
        '├────┼──────┤',
        '│ 1  │ alpha│',
        '│ 2  │ beta │',
        '└────┴──────┘',
    ]
    reconstructed = reconstruct_wrapped_table_across_frames([frame(visible)], headers)
    assert reconstructed['headers'] == ['ID', 'NAME'], reconstructed
    assert reconstructed['ids'] == ['1', '2'], reconstructed
    assert reconstructed['rows'] == [['1', 'alpha'], ['2', 'beta']], reconstructed
    body, _ = parse_wrapped_table_body(visible, 2, header0='ID')
    assert body == [['1', 'alpha'], ['2', 'beta']], body

    scrolled = [
        '│ 1  │ alpha│',
        '│ 2  │ beta │',
        '└────┴──────┘',
    ]
    reconstructed = reconstruct_wrapped_table_across_frames([frame(scrolled)], headers)
    assert reconstructed['ids'] == ['1', '2'], reconstructed
    assert reconstructed['rows'] == [['1', 'alpha'], ['2', 'beta']], reconstructed

    wrapped = [
        '┌────┬──────┐',
        '│ ID │ NA   │',
        '│    │ ME   │',
        '├────┼──────┤',
        '│ 1  │ al   │',
        '│    │ pha  │',
        '│ 2  │ be   │',
        '│    │ ta   │',
        '└────┴──────┘',
    ]
    reconstructed = reconstruct_wrapped_table_across_frames([frame(wrapped)], headers)
    assert reconstructed['headers'] == ['ID', 'NAME'], reconstructed
    assert reconstructed['ids'] == ['1', '2'], reconstructed
    assert reconstructed['rows'] == [['1', 'alpha'], ['2', 'beta']], reconstructed

    mixed = [
        frame(wrapped),
        frame(['│ 2  │ be   │', '│    │ ta   │', '└────┴──────┘']),
    ]
    reconstructed = reconstruct_wrapped_table_across_frames(mixed, headers)
    assert reconstructed['ids'] == ['1', '2'], reconstructed
    assert reconstructed['rows'] == [['1', 'alpha'], ['2', 'beta']], reconstructed
    assert_table_matches_api([frame(visible), frame(scrolled), frame(wrapped)], [['1', 'alpha'], ['2', 'beta']], headers, label='parser-self-check')


def inspect_table_screen(frames):
    last = frames[-1]
    first = frames[0]
    border = set('┌┐└┘│─┬┴┼├┤')
    cells = last.get('cells') or []
    text = '\n'.join(last['lines'])

    def styles(cell_list):
        return {
            'n': len(cell_list),
            'dim': sorted({cell.get('dim') for cell in cell_list}),
            'bold': sorted({cell.get('bold') for cell in cell_list}),
            'fg': sorted({str(cell.get('fg')) for cell in cell_list}),
            'bg': sorted({str(cell.get('bg')) for cell in cell_list}),
        }

    rid_rows = [index for index, line in enumerate(last['lines']) if 'Request ID:' in line]
    rid_cells = [cell for cell in cells if cell.get('row') in rid_rows]
    border_cells = [cell for cell in cells if (cell.get('data') or '') in border]
    text_cells = [cell for cell in cells if (cell.get('data') or ' ').strip() and (cell.get('data') or '') not in border]
    glyphs = []
    for frame in frames:
        for line in frame['lines']:
            if 'Loading App instances' in line or 'Loaded App instances' in line:
                glyphs.append({'elapsed': frame.get('elapsed'), 'line': line.strip()})
                break
    return {
        'frame_count': len(frames),
        'cursor': last.get('cursor'),
        'has_top_border': '┌' in text and '┬' in text,
        'has_bottom_border': '└' in text,
        'has_request_id': 'Request ID:' in text,
        'border': styles(border_cells),
        'text': styles(text_cells),
        'request_id': {
            **styles(rid_cells),
            'rows': rid_rows,
            'data': ''.join((cell.get('data') or '') for cell in rid_cells),
        },
        'progress_dim': any(cell.get('dim') for cell in first.get('cells') or []),
        'animation': glyphs[:12],
        'cell_schema': 'data',
        'limitation': 'Last screen may clip headers; body reconstruction uses settled frames, not this viewport alone.',
    }


def assert_wrapped_table(lines, expected, expected_headers):
    headers, rows = parse_wrapped_table(lines, expected_headers)
    assert headers == [''.join(h.split()) for h in expected_headers], headers
    assert rows == [[''.join(str(v).split()) for v in row] for row in expected], (rows, expected)


def native_list_animation_observation(frames):
    # Documented cadence is ~300ms. Capture-tolerant proof uses 0.15-0.65s with
    # minimum_changes 2 on clone/create/remove/transfer. instance:list often
    # reaches Loaded after one active-indicator change; that completion is
    # request-bound, not a full cadence run.
    events = []
    previous = None
    for frame in frames:
        glyph = None
        for line in frame['lines']:
            if 'Loading App instances' in line:
                if '○' in line:
                    glyph = '○'
                elif '◉' in line:
                    glyph = '◉'
            elif 'Loaded App instances' in line and '●' in line:
                glyph = '●'
            if glyph:
                break
        if glyph and glyph != previous:
            events.append({'elapsed': frame.get('elapsed'), 'glyph': glyph})
            previous = glyph
    intervals = [events[index]['elapsed'] - events[index - 1]['elapsed'] for index in range(1, len(events))]
    return {
        'kind': 'short-run-observation',
        'not_cadence_proof': True,
        'documented_cadence_seconds': 0.3,
        'cadence_proof_bounds': {'min_interval': 0.15, 'max_interval': 0.65, 'minimum_changes': 2, 'used_on': ['clone', 'create', 'remove', 'transfer']},
        'reason': 'List loading often completes after one ○/◉ change. Do not treat 0.05-2.0s verify.py bounds as cadence proof.',
        'events': events,
        'intervals': intervals,
        'three_hundred_ms_class_intervals': [value for value in intervals if 0.15 <= value <= 0.65],
    }


def assert_native_list_screen(frames, *, terminal, label):
    screen = inspect_table_screen(frames)
    assert frames[-1]['cursor']['hidden'] is False, label
    assert json.loads(terminal.read_text())['equal'], label
    assert screen['progress_dim'] is True, (label, 'progress connectors should be dim')
    assert screen['has_request_id'], label
    assert False in screen['request_id']['dim'], (label, 'Request ID must stay undimmed', screen['request_id'])
    assert True in screen['border']['dim'], (label, 'table borders should be dim', screen['border'])
    assert False in screen['text']['dim'], (label, 'table text should stay undimmed', screen['text'])
    animation = native_list_animation_observation(frames)
    glyphs = [event['glyph'] for event in animation['events']]
    assert '○' in glyphs or '◉' in glyphs, (label, animation)
    assert '●' in glyphs, (label, animation)
    screen['animation_observation'] = animation
    return screen


def reconstruct_last_frame_text(out):
    frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
    return ''.join(line.rstrip() for line in frames[-1]['lines'])


def assert_clone_partial_human_warning(out, *, name, clone_id, post=None):
    # CloneInstanceCommand.php writes this exact sentence through writeHumanMessage (TerminalText::wrap).
    reconstructed = reconstruct_last_frame_text(out)
    match = re.search(r'Clone request ID: (' + REQUEST_ID_BODY + r')', reconstructed)
    assert match, reconstructed
    warning = 'App instance [' + name + '] (#' + str(clone_id) + ') was cloned; the selected release could not be read. Clone request ID: ' + match.group(1)
    assert warning in reconstructed, (warning, reconstructed)
    assert 'Releases are unavailable.' in reconstructed
    assert '0198e15c-bf97-7c23-8f1f-61b8fe67a845' in reconstructed
    raw = re.sub(r'\x1b\[[0-9;?]*[A-Za-z]', '', (out / 'capture/raw.bin').read_bytes().decode('utf-8', 'replace'))
    assert ''.join(warning.split()) in ''.join(raw.split()), raw
    clone_rid = match.group(1)
    post_rid = None if post is None else post.get('request_id')
    if post_rid:
        assert clone_rid == post_rid, (clone_rid, post_rid, post)
    return clone_rid


def last_record_wrap_target(fallback):
    row = fallback[-1]
    values = {header: value for header, value in row}
    for header in ('URL', 'ROUTE DOMAIN', 'ROOT', 'NAME'):
        value = values.get(header)
        if value in (None, '—'):
            continue
        text = header + ': ' + str(value)
        columns = 40 if len(text) >= 40 else max(20, len(text) - 1)
        return header, value, columns
    raise AssertionError('last fallback record must have a reconstructable field')


def reconstruct_wrapped_fields(lines, header, columns):
    prefix = header + ': '
    record_n = None
    current = None
    current_record = None
    results = []

    def flush():
        nonlocal current, current_record
        if current is not None:
            results.append({'record': current_record, 'value': ''.join(current)})
            current = None
            current_record = None

    for line in lines:
        stripped = line.strip()
        filled = len(line.rstrip()) >= columns - 1
        if stripped.startswith('Record '):
            flush()
            parts = stripped.split()
            try:
                record_n = int(parts[1])
            except (IndexError, ValueError):
                record_n = None
            continue
        if stripped.startswith(prefix):
            flush()
            current = [stripped[len(prefix):]]
            current_record = record_n
            if not filled:
                flush()
            continue
        if current is not None:
            next_header = ': ' in stripped and stripped.split(': ', 1)[0] != header and stripped.split(': ', 1)[0].isupper()
            if next_header:
                flush()
            elif stripped:
                current.append(stripped)
                if not filled:
                    flush()
            else:
                flush()
    flush()
    return results


def fallback_frame_styles(frame, needle):
    rows = [index for index, line in enumerate(frame['lines']) if needle in line]
    if not rows:
        return None
    cells = [cell for cell in frame.get('cells') or [] if cell.get('row') in rows]
    return {
        'needle': needle,
        'rows': rows,
        'dim': sorted({cell.get('dim') for cell in cells}),
        'fg': sorted({str(cell.get('fg')) for cell in cells}),
        'bold': sorted({cell.get('bold') for cell in cells}),
    }


def assert_fallback_scroll_frames(frames, fallback, *, label, columns, wrap_header, wrap_expected, wrap_record_number):
    assert frames, label
    fallback_frames = [frame for frame in frames if any(line.strip().startswith('Record ') for line in frame['lines'])]
    assert fallback_frames, (label, 'no reconstructed frame shows a fallback record')
    unique = []
    for frame in fallback_frames:
        text = '\n'.join(frame['lines'])
        if not unique or unique[-1] != text:
            unique.append(text)
    assert len(unique) >= 2, (label, 'fallback on-screen contents did not change; cannot claim scroll')
    occurrences = []
    for frame in fallback_frames:
        occurrences.extend(reconstruct_wrapped_fields(frame['lines'], wrap_header, columns))
    expected_compact = ''.join(str(wrap_expected).split())
    matches = [item for item in occurrences if ''.join(str(item['value']).split()) == expected_compact]
    anchored = [item for item in matches if item.get('record') == wrap_record_number]
    assert matches, (label, wrap_header, wrap_expected, wrap_record_number, occurrences)
    reconstructed = (anchored or matches)[0]['value']
    record_numbers = sorted({int(line.strip().split()[1]) for frame in fallback_frames for line in frame['lines'] if line.strip().startswith('Record ') and len(line.strip().split()) > 1})
    assert wrap_record_number in record_numbers, (label, 'predetermined last record is missing from reconstructed frames', wrap_record_number, record_numbers)
    progress = frames[0]
    record_styles = fallback_frame_styles(fallback_frames[-1], 'Record ')
    request_styles = fallback_frame_styles(fallback_frames[-1], 'Request ID:')
    dim_progress = any(cell.get('dim') for frame in frames[:5] for cell in frame.get('cells') or [])
    assert record_styles and False in record_styles['dim'], (label, 'Record label must stay undimmed', record_styles)
    assert request_styles and False in request_styles['dim'], (label, 'Request ID must stay undimmed', request_styles)
    assert dim_progress, (label, 'progress tree connectors should be dim')
    return {
        'records_seen_in_any_frame': sorted({int(line.strip().split()[1]) for frame in fallback_frames for line in frame['lines'] if line.strip().startswith('Record ')}),
        'unique_fallback_frames': len(unique),
        'wrapped_field': {'header': wrap_header, 'expected': wrap_expected, 'record_number': wrap_record_number, 'reconstructed': reconstructed},
        'styles': {'progress_dim': dim_progress, 'record': record_styles, 'request_id': request_styles},
        'frame_count': len(frames),
    }


def without_request(value):
    if isinstance(value, dict):
        return {k: without_request(v) for k, v in value.items() if k != 'request_id'}
    if isinstance(value, list):
        return [without_request(v) for v in value]
    return value

validate_wrapped_table_parser()

nodes = read('node:list')['nodes']
dev = next(n for n in nodes if n['name'] == 'app-dev')
prod = next(n for n in nodes if n['name'] == 'app-prod')
extra = next(n for n in nodes if n['name'] == 'app-prod-2')
instances = read('instance:list')['app_instances']
sample = next(i for i in instances if i['name'] == 'e2e-dev')
production = next(i for i in instances if i['name'] == 'e2e-prod')
app = read('app:show', sample['app_id'])
save(root / 'baseline.json', {'nodes': nodes, 'instances': instances, 'app': app})

if args.stage == 'prepare':
    assert extra['roles'] == ['app-prod'] and not any(i['node_id'] == extra['id'] for i in instances)
    assert dev['cluster_id'] is not None
    read('node:role:remove', extra['id'], 'app-prod', '--force')
    if extra['cluster_id'] is None:
        read('cluster:node:add', dev['cluster_id'], extra['id'])
    read('node:role:add', extra['id'], 'app-dev')
    read('node:add', extra['name'], '--tld=ux356.test')
    after = read('node:show', extra['id'])
    assert after['status'] == 'active' and after['roles'] == ['app-dev'] and after['cluster_id'] == dev['cluster_id'], after
    assert without_request(read('instance:list')) == without_request({'app_instances': instances})
    fixture_app = read('app:create', 'ux356-fixture', 'https://github.com/mdn/beginner-html-site-styled.git', '--name=CLI UX fixture', '--root=styles')
    save(root / 'fixture-app.json', fixture_app)
    save(root / 'prepared.json', {'destination': after, 'source': dev, 'sample': sample, 'production': production, 'app': app})

elif args.stage in ['reads', 'reads-narrow']:
    def cell(value):
        if value is None:
            return '—'
        if isinstance(value, bool):
            return 'yes' if value else 'no'
        return value

    def removal_cell(removal):
        if not removal:
            return None
        summary = ('forced' if removal.get('force') else 'normal') + ' ' + str(removal['completed']) + '/' + str(removal['total']) + ' completed; ' + str(removal['remaining']) + ' remaining; ' + (removal.get('current_step') or '—')
        if removal.get('failed_step') is not None:
            summary += '; failed ' + str(removal['failed_step']) + ' (' + str(removal.get('error_code') or '—') + ')'
        return summary

    list_payload = read('instance:list')
    show_payload = read('instance:show', sample['id'])
    prod_show = read('instance:show', production['id'])
    steps_payload = read('instance:deploy-step:list', production['id'])
    releases_payload = read('instance:release:list', production['id'])
    instance_headers = ['ID', 'APP', 'NODE', 'VITE PORT', 'NAME', 'ENVIRONMENT', 'SOURCE LAYOUT', 'ROOT', 'SELECTED BRANCH', 'BRANCH OVERRIDE', 'MIGRATION REQUIRED', 'ROUTE DOMAIN', 'URL', 'STATUS', 'REMOVAL']
    instance_rows = [[cell(v) for v in [
        inst['id'], inst['app_id'], inst['node_id'], inst.get('vite_port'), inst['name'], inst['environment'],
        inst['source_layout'], inst.get('effective_root'), inst.get('selected_branch'), inst.get('branch_override'),
        'yes' if inst.get('migration_required') else 'no', inst.get('domain'), inst.get('url'), inst['status'],
        removal_cell(inst.get('removal')),
    ]] for inst in list_payload['app_instances']]
    instance_fallback = [[(header, value) for header, value in zip(instance_headers, row)] for row in instance_rows]
    step_headers = ['NAME', 'PHASE', 'COMMAND', 'TIMEOUT (SECONDS)']
    step_rows = [[cell(step['name']), cell(step['phase']), cell(step['command']), cell(step.get('timeout_seconds'))] for step in steps_payload.get('steps') or []]
    release_headers = ['RELEASE', 'SELECTED']
    release_rows = [[cell(name), 'yes' if name == releases_payload.get('selected_release') else 'no'] for name in releases_payload.get('releases') or []]
    if args.stage == 'reads' and not args.resume_after_reads_wide:
        record('instances-human', ['instance:list'], ['ID', 'APP', 'NODE', 'e2e-dev', 'e2e-prod'], columns=200, table=instance_rows, table_headers=instance_headers)
        record('instances-plain', ['instance:list'], ['ID', 'APP', 'NODE', 'e2e-dev', 'e2e-prod'], columns=200, plain=True, table=instance_rows, table_headers=instance_headers)
        record('instances-json-tty', ['instance:list', '--json'], [], json_payload=list_payload)
        record('instance-human', ['instance:show', sample['id']], ['App instance: e2e-dev', 'Checkout', 'Selected branch', 'No deploy steps found.'], columns=200)
        record('instance-plain', ['instance:show', sample['id']], ['App instance: e2e-dev', 'Checkout', 'Selected branch', 'No deploy steps found.'], columns=200, plain=True)
        record('instance-json-tty', ['instance:show', sample['id'], '--json'], [], json_payload=show_payload)
        record('production-human', ['instance:show', production['id']], ['App instance: e2e-prod', 'Production user', 'Production home'], columns=200)
        record('production-json-tty', ['instance:show', production['id'], '--json'], [], json_payload=prod_show)
        if step_rows:
            record('steps-human', ['instance:deploy-step:list', production['id']], ['NAME', 'PHASE', 'COMMAND', 'TIMEOUT'], columns=200, table=step_rows, table_headers=step_headers)
            record('steps-plain', ['instance:deploy-step:list', production['id']], ['NAME', 'PHASE', 'COMMAND', 'TIMEOUT'], columns=200, plain=True, table=step_rows, table_headers=step_headers)
        else:
            record('steps-human', ['instance:deploy-step:list', production['id']], ['No deploy steps found.'], columns=200)
        record('steps-json-tty', ['instance:deploy-step:list', production['id'], '--json'], [], json_payload=steps_payload)
        if release_rows:
            record('releases-human', ['instance:release:list', production['id']], ['RELEASE', 'SELECTED', 'Selected release:'], columns=200, table=release_rows, table_headers=release_headers)
            record('releases-plain', ['instance:release:list', production['id']], ['RELEASE', 'SELECTED', 'Selected release:'], columns=200, plain=True, table=release_rows, table_headers=release_headers)
        else:
            record('releases-human', ['instance:release:list', production['id']], ['No retained releases found.'], columns=200)
        record('releases-json-tty', ['instance:release:list', production['id'], '--json'], [], json_payload=releases_payload)
        for label, command in [
            ('instances', ['instance:list']),
            ('instance', ['instance:show', sample['id']]),
            ('steps', ['instance:deploy-step:list', production['id']]),
            ('releases', ['instance:release:list', production['id']]),
        ]:
            result = subprocess.run(['php', str(launcher), *map(str, command), '--ansi'], cwd=source, env=env, input=b'', capture_output=True, timeout=120)
            assert result.returncode == 0 and result.stderr == b'' and b'\x1b' not in result.stdout, (label, result)
            (root / (label + '-pipe.stdout')).write_bytes(result.stdout)
            (root / (label + '-pipe.stderr')).write_bytes(result.stderr)
            save(root / (label + '-pipe.json'), {'argv': command, 'exit': result.returncode, 'ansi': False})
    # Plain raw.bin keeps every field. Decorated frames inspect wrapping and scroll, not only the last frame.
    if not (root / 'instances-fallback').exists():
        record('instances-fallback', ['instance:list'], ['Request ID:'], columns=40, fallback=instance_fallback, plain=True)
    wrap_header, wrap_expected, wrap_columns = last_record_wrap_target(instance_fallback)
    save(root / 'expected-fallback-wrap.json', {'header': wrap_header, 'value': wrap_expected, 'columns': wrap_columns, 'record_number': len(instance_fallback), 'record': dict(instance_fallback[-1])})
    if not (root / 'instances-fallback-decorated').exists():
        record('instances-fallback-decorated', ['instance:list'], ['Request ID:'], columns=wrap_columns, fallback=instance_fallback, wrap_header=wrap_header, wrap_expected=wrap_expected)
    if not (root / 'instance-narrow').exists():
        record('instance-narrow', ['instance:show', sample['id']], ['Request ID'], columns=40, wrapped_contains=[sample['checkout_path'], sample['starting_commit']])

elif args.stage == 'source':
    app = next(a for a in read('app:list')['apps'] if a['slug'] == 'ux356-fixture')
    save(root / 'source-app.json', app)
    assert extra['roles'] == ['app-dev'] and extra['cluster_id'] == dev['cluster_id']
    assert extra['tld'], 'Transfer fixture destination requires a TLD'
    if args.resume_after_clone:
        created = json.loads((args.resume_after_clone / 'created.json').read_text())
        clone = json.loads((args.resume_after_clone / 'cloned.json').read_text())
        assert without_request(read('instance:show', created['id']))['name'] == created['name']
        assert read('instance:show', clone['id'])['name'] == clone['name']
        name, clone_name, branch = created['name'], clone['name'], created['selected_branch']
        instance_id = created['id']
        save(root / 'resumed-discovery.json', {'from': str(args.resume_after_clone), 'created': created, 'cloned': clone})
    else:
        suffix = uuid.uuid4().hex[:8]
        name = 'ux356-' + suffix
        clone_name = 'ux356-prod-' + suffix
        branch = app['default_branch']
        record('create', ['instance:create', app['id'], dev['id'], name, '--branch=' + branch], ['App instance: ' + name, 'Selected branch', branch])
        created = next(i for i in read('instance:list')['app_instances'] if i['name'] == name)
        instance_id = created['id']
        assert created['status'] == 'active' and created['selected_branch'] == branch and created['node_id'] == dev['id']
        save(root / 'created.json', created)
        record('clone', ['instance:clone', instance_id, prod['id'], clone_name, '--preview-name=' + clone_name], ['App instance cloned.', 'Preview domain', clone_name], animation={'name': 'clone', 'pattern': r'(?P<glyph>[○◉]) Cloning source', 'terminal_pattern': r'● Cloned source', 'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 0.65})
        clone = next(i for i in read('instance:list')['app_instances'] if i['name'] == clone_name)
        assert clone['environment'] == 'production' and clone['status'] == 'active'
        save(root / 'cloned.json', clone)
    clone_id = clone['id']
    if not args.resume_before_production_remove and not args.resume_before_transfer:
        record('update', ['instance:update', clone_id, '--branch=codeowners'], ['Deployment branch', 'Selected branch', 'codeowners', 'main', 'Request ID'])
        assert read('instance:show', clone_id)['selected_branch'] == clone['selected_branch']
        with sqlite3.connect('file:/home/orbit/.orbit/gateway.sqlite?mode=ro', uri=True) as db:
            stored = db.execute('SELECT id, branch, deployment_branch FROM app_instances WHERE id = ?', (clone_id,)).fetchone()
        assert stored == (clone_id, clone['selected_branch'], 'codeowners'), stored
        save(root / 'stored-deployment-branch.json', {'id': stored[0], 'source_branch': stored[1], 'deployment_branch': stored[2]})
        releases = read('instance:release:list', clone_id)
        assert releases['selected_release'] is None, releases
        record('clone-releases', ['instance:release:list', clone_id], ['RELEASE', 'SELECTED', 'initial', 'Selected release:'])
        record('empty-steps', ['instance:deploy-step:list', clone_id], ['No deploy steps found.'])
        first = 'printf ux356-step'
        second = 'printf ux356-sibling'
        record('step-create', ['instance:deploy-step:create', clone_id, 'ux-check', '--command=' + first, '--timeout=60'], ['Deploy step: ux-check', first, '60'])
        record('step-create-sibling', ['instance:deploy-step:create', clone_id, 'ux-later', '--command=' + second, '--timeout=45'], ['Deploy step: ux-later', second, '45'])
        steps = read('instance:deploy-step:list', clone_id)
        names = [s['name'] for s in steps['steps']]
        assert names == ['ux-check', 'ux-later'], names
        command = 'printf ux356-updated'
        record('step-update', ['instance:deploy-step:update', clone_id, 'ux-check', '--command=' + command, '--phase=after_activation'], ['Deploy step: ux-check', command, 'after_activation'])
        before_steps = without_request(read('instance:deploy-step:list', clone_id))
        updated = next(s for s in before_steps['steps'] if s['name'] == 'ux-check')
        sibling = next(s for s in before_steps['steps'] if s['name'] == 'ux-later')
        assert updated['command'] == command and updated['phase'] == 'after_activation' and updated['timeout_seconds'] == 60
        assert sibling['command'] == second and sibling['timeout_seconds'] == 45
        # List order is phase then placement, not create order.
        assert [s['name'] for s in before_steps['steps']] == ['ux-later', 'ux-check'], before_steps
        save(root / 'steps-after-phase-update.json', before_steps)
        for label, key in [('default-no', '\r'), ('cancel', '\x03'), ('eof', '\x04')]:
            record('step-' + label, ['instance:deploy-step:destroy', clone_id, 'ux-check'], ['cancelled'], expected=1, key=key, prompt='Yes')
            assert without_request(read('instance:deploy-step:list', clone_id)) == before_steps
        record('step-destroy', ['instance:deploy-step:destroy', clone_id, 'ux-check'], ['Destroyed deploy step ux-check.'], key='y', prompt='Yes')
        remaining = [s['name'] for s in read('instance:deploy-step:list', clone_id)['steps']]
        assert remaining == ['ux-later'], remaining
        record('step-destroy-sibling', ['instance:deploy-step:destroy', clone_id, 'ux-later', '--yes'], ['Destroyed deploy step ux-later.'])
        assert read('instance:deploy-step:list', clone_id)['steps'] == []
        # A real file verifies content preservation and dirty-source safeguards.
        marker = created['checkout_path'] + '/ux356-untracked.txt'
        remote(dev, 'printf ux356-preserved > ' + shlex.quote(marker))
        before = without_request(read('instance:show', instance_id))
        for label, key in [('default-no', '\r'), ('cancel', '\x03'), ('eof', '\x04')]:
            record('transfer-' + label, ['instance:transfer', instance_id, extra['id']], ['cancelled'], expected=1, key=key, prompt='Yes')
            assert without_request(read('instance:show', instance_id)) == before
        refusal = read('instance:transfer', instance_id, extra['id'], expected=1, error='instance.confirmation_required')
        record('transfer-json-refusal', ['instance:transfer', instance_id, extra['id'], '--json'], [], expected=1, json_payload=refusal, actual_gateway=False, error_code='instance.confirmation_required')
    if not args.resume_before_production_remove:
        record('transfer', ['instance:transfer', instance_id, extra['id']], ['Destination Node', 'Cleanup', 'completed'], key='y', prompt='Yes', animation={'name': 'transfer', 'pattern': r'(?P<glyph>[○◉]) Transferring App instance', 'terminal_pattern': r'● Transferred App instance', 'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 0.65})
        moved = read('instance:show', instance_id)
        assert moved['id'] == instance_id and moved['node_id'] == extra['id'] and moved['status'] == 'active'
        assert remote(extra, 'cat ' + shlex.quote(moved['checkout_path'] + '/ux356-untracked.txt')) == 'ux356-preserved'
        remote(dev, 'test ! -e ' + shlex.quote(created['checkout_path']))
        save(root / 'transferred.json', moved)
        # Return the fixture to its original Node, preserving its identifier and content.
        returned_capture = record('transfer-back-json', ['instance:transfer', instance_id, dev['id'], '--force', '--json'], [])
        returned = read('instance:show', instance_id)
        assert returned['node_id'] == dev['id'] and remote(dev, 'cat ' + shlex.quote(returned['checkout_path'] + '/ux356-untracked.txt')) == 'ux356-preserved'
        transfer_result = json.loads((returned_capture / 'capture/raw.bin').read_bytes())
        expected_transfer = {key: returned[key] for key in ['id', 'node_id', 'name', 'checkout_path', 'domain', 'transfer']}
        assert without_request(transfer_result) == expected_transfer, transfer_result
        progress = transfer_result['transfer']
        assert progress['source_node_id'] == extra['id'] and progress['destination_node_id'] == dev['id']
        assert progress['cutover_completed'] is True and progress['cleanup_completed'] is True
        save(root / 'transfer-back-expected.json', expected_transfer)
        for label, key in [('default-no', '\r'), ('cancel', '\x03'), ('eof', '\x04')]:
            record('remove-' + label, ['instance:destroy', instance_id], ['cancelled'], expected=1, key=key, prompt='Yes')
            assert without_request(read('instance:show', instance_id)) == without_request(returned)
        record('remove-dirty-refusal', ['instance:destroy', instance_id, '--yes'], ['dirty or unpublished source'], expected=1)
        assert remote(dev, 'cat ' + shlex.quote(returned['checkout_path'] + '/ux356-untracked.txt')) == 'ux356-preserved'
        # Completed transfer history currently prevents row deletion (ORB-369). Destroy a never-transferred disposable instead.
        save(root / 'retained-transferred.json', {'id': instance_id, 'reason': 'orb-369-completed-transfer-history', 'node_id': returned['node_id']})
        disposable_name = 'ux356-rm-' + uuid.uuid4().hex[:8]
        record('create-for-destroy', ['instance:create', app['id'], dev['id'], disposable_name, '--branch=' + branch], ['App instance: ' + disposable_name], animation={'name': 'create', 'pattern': r'(?P<glyph>[○◉]) Creating App instance', 'terminal_pattern': r'● Created App instance', 'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 0.65})
        disposable = next(i for i in read('instance:list')['app_instances'] if i['name'] == disposable_name)
        force_only = read('instance:destroy', disposable['id'], '--force', expected=1, error='input.confirmation_required')
        record('remove-force-without-yes', ['instance:destroy', disposable['id'], '--force', '--json'], [], expected=1, json_payload=force_only, actual_gateway=False, error_code='input.confirmation_required')
        assert any(i['id'] == disposable['id'] for i in read('instance:list')['app_instances'])
        record('remove-source', ['instance:destroy', disposable['id'], '--yes'], ['removed.', 'Removal: ' + disposable_name], animation={'name': 'remove', 'pattern': r'(?P<glyph>[○◉]) Removing App instance', 'terminal_pattern': r'● Removed App instance', 'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 0.65})
        assert not any(i['id'] == disposable['id'] for i in read('instance:list')['app_instances'])
    production_user = clone['production_user']
    production_home = clone['production_home']
    production_sentinel = production_home.rstrip('/') + '/releases/initial/ux356-retained.txt'
    leftover = production_home.rstrip('/') + '/ux356-retained.txt'
    leftover_stat = remote(prod, 'if sudo test -e ' + shlex.quote(leftover) + '; then sudo cp -a -- ' + shlex.quote(leftover) + ' /var/tmp/ux356-retained-root-owned.txt; sudo stat -c %U:%G:%a:%n -- ' + shlex.quote(leftover) + '; sudo rm -- ' + shlex.quote(leftover) + '; fi').strip()
    if leftover_stat:
        save(root / 'removed-unsafe-home-sentinel.json', {'path': leftover, 'stat': leftover_stat, 'copied_to': '/var/tmp/ux356-retained-root-owned.txt'})
    # Retained production content must stay owned by the production user. Root-owned files fail source_finalization.
    remote(prod, 'printf ux356-retained | sudo -u ' + shlex.quote(production_user) + ' -H tee ' + shlex.quote(production_sentinel) + ' >/dev/null')
    owner = remote(prod, 'sudo stat -c %U:%G -- ' + shlex.quote(production_sentinel)).strip()
    assert owner == production_user + ':' + production_user, owner
    unsafe = remote(prod, 'sudo find -P ' + shlex.quote(production_home) + ' -xdev ! -user ' + shlex.quote(production_user) + ' -print').strip()
    assert unsafe == '', unsafe
    save(root / 'production-sentinel.json', {'path': production_sentinel, 'owner': owner, 'unsafe_find': unsafe})
    record('remove-production', ['instance:destroy', clone_id], ['removed.', 'Removal: ' + clone_name], key='y', prompt='Yes')
    assert not any(i['id'] == clone_id for i in read('instance:list')['app_instances'])
    remote(prod, 'sudo test -d ' + shlex.quote(clone['production_home']))
    assert remote(prod, 'sudo cat ' + shlex.quote(production_sentinel)) == 'ux356-retained'
    save(root / 'retained-production.json', {'path': clone['production_home'], 'exists': True, 'sentinel_preserved': True})

elif args.stage == 'invalid':
    before = without_request(read('instance:list'))
    before_obs = len(observations)
    negatives = [
        (['instance:show', '0'], 'instance.id_invalid'),
        (['instance:create', '0', str(dev['id']), 'ux356-bad'], 'app.id_invalid'),
        (['instance:clone', '0', str(prod['id']), 'ux356-bad', '--preview-name=ux356-bad'], 'instance.candidate_id_invalid'),
        (['instance:clone', str(sample['id']), str(prod['id']), 'ux356-bad'], 'instance.preview_name_required'),
        (['instance:update', '0', '--branch=main'], 'instance.id_invalid'),
        (['instance:destroy', '0'], 'instance.id_invalid'),
        (['instance:transfer', '0', str(extra['id'])], 'instance.id_invalid'),
        (['instance:deploy-step:list', '0'], 'instance.id_invalid'),
        (['instance:deploy-step:create', '0', 'ux-bad', '--command=true'], 'instance.id_invalid'),
        (['instance:release:list', '0'], 'instance.id_invalid'),
        (['instance:register', '--path=/no/such/ux356-source', '--yes'], 'instance.source_invalid'),
    ]
    for command, error in negatives:
        payload = read(*command, expected=1, error=error)
        message = payload['error']['message']
        label = command[0].replace(':', '-') + '-' + error.replace('.', '-')
        record(label + '-human', command, [message], expected=1, actual_gateway=False)
        record(label + '-json-tty', command + ['--json'], [], expected=1, json_payload=payload, actual_gateway=False, error_code=error)
        record(label + '-plain', command, [message], expected=1, plain=True, actual_gateway=False)
        result = subprocess.run(['php', str(launcher), *map(str, command), '--ansi'], cwd=source, env=env, input=b'', capture_output=True, timeout=30)
        assert result.returncode == 1 and result.stderr == b'' and b'\x1b' not in result.stdout
        assert message in result.stdout.decode()
        save(root / (label + '-pipe.json'), {'argv': command, 'exit': result.returncode, 'stdout': result.stdout.decode(), 'actual_gateway': False})
    missing = read('instance:show', '999999', expected=1, error='http.404')
    record('instance-show-missing-json-tty', ['instance:show', '999999', '--json'], [], expected=1, json_payload=missing, actual_gateway=True, error_code='http.404')
    assert without_request(read('instance:list')) == before
    save(root / 'invalid-observation-delta.json', {'before': before_obs, 'after': len(observations), 'list_unchanged': True})

elif args.stage == 'mutation-modes':
    def mutation(label, command, message, mode, expected=0):
        command = list(map(str, command))
        if mode == 'json-tty':
            out = record(label + '-' + mode, command + ['--json'], [], expected=expected, actual_gateway=True)
            return json.loads((out / 'capture/raw.bin').read_bytes())
        out = root / (label + '-pipe')
        out.mkdir()
        argv = ['php', str(launcher), *command, '--ansi']
        print('$ ' + shlex.join(argv) + ' [separate pipes]', flush=True)
        result = subprocess.run(argv, cwd=source, env=env, input=b'', capture_output=True, timeout=240)
        (out / 'stdout.bin').write_bytes(result.stdout)
        (out / 'stderr.bin').write_bytes(result.stderr)
        text = result.stdout.decode()
        assert result.returncode == expected and result.stderr == b'' and b'\x1b' not in result.stdout, (label, result.returncode, text, result.stderr)
        if expected == 0:
            assert message in text, (label, text)
            assert_human_request_id(text, command, label=label + '-pipe')
        else:
            assert message in text, (label, text)
        case = {'label': label + '-pipe', 'argv': argv, 'candidate': args.candidate, 'exit': result.returncode, 'actual_gateway': True, 'passed': True}
        save(out / 'case.json', case)
        records.append(case)
        save(root / 'records.json', records)
        print(json.dumps({'label': case['label'], 'passed': True}), flush=True)
        return None

    fixture = next(a for a in read('app:list')['apps'] if a['slug'] == 'ux356-fixture')
    # One App owns one repository, and production homes are per App. Source-stage
    # removal retained /home/orbit-app-2, so another clone of that App on app-prod fails
    # production-source-prepare. Each mode uses a distinct repository and home.
    mode_repositories = {
        'json-tty': 'https://github.com/mdn/beginner-html-site.git',
        'pipe': 'https://github.com/mdn/beginner-html-site-scripted.git',
    }
    for mode in ['json-tty', 'pipe']:
        if args.resume_after_mutation_pipe_create and mode == 'json-tty':
            continue
        repository = mode_repositories[mode]
        mode_app = next((a for a in read('app:list')['apps'] if a['repository_url'] == repository), None)
        if mode_app is None:
            slug = 'ux356-' + ('json' if mode == 'json-tty' else 'pipe') + '-' + uuid.uuid4().hex[:8]
            mode_app = read('app:create', slug, repository, '--name=CLI UX ' + mode, '--root=images')
        elif mode_app['root'] != 'images':
            mode_app = read('app:update', mode_app['id'], '--root=images')
        save(root / ('mode-app-' + mode + '.json'), mode_app)
        if args.resume_after_mutation_clone and mode == 'json-tty':
            listed = read('instance:list')['app_instances']
            instance = next(i for i in listed if i['app_id'] == mode_app['id'] and i['environment'] == 'development' and i['status'] == 'active' and i['name'].startswith('ux356-mode-'))
            clone = next(i for i in listed if i['app_id'] == mode_app['id'] and i['environment'] == 'production' and i['status'] == 'active')
            clone_name = clone['name']
            out = root / 'clone-json-tty'
            argv = ['instance:clone', instance['id'], prod['id'], clone_name, '--preview-name=' + clone_name, '--json']
            machine = json.loads((out / 'capture/raw.bin').read_bytes())
            assert (out / 'stderr.bin').read_bytes() == b'' and b'\x1b' not in (out / 'capture/raw.bin').read_bytes()
            assert_json_machine(machine, actual_gateway=True, error_code=None, json_payload=None, label='clone-json-tty', argv=argv)
            assert machine['target_id'] == clone['id']
            save(out / 'json-frame-tokens.json', json_frame_token_rationale(actual_gateway=True, error_code=None, argv=argv))
            case = {'label': 'clone-json-tty', 'argv': ['php', str(launcher), *argv, '--ansi'], 'candidate': args.candidate, 'environment': 'disposable-incus', 'request_observation': 'gateway', 'actual_gateway': True, 'exit': 0, 'json_frame_token_contains': 'inapplicable', 'json_success_schema': 'clone-aggregate', 'resumed_existing_capture': True, 'passed': True}
            save(out / 'case.json', case)
            if not any(r.get('label') == 'clone-json-tty' for r in records):
                records.append(case)
                save(root / 'records.json', records)
            print(json.dumps({'label': 'clone-json-tty', 'passed': True, 'resumed': True}), flush=True)
        elif args.resume_after_mutation_pipe_create and mode == 'pipe':
            listed = read('instance:list')['app_instances']
            instance = next(i for i in listed if i['app_id'] == mode_app['id'] and i['environment'] == 'development' and i['status'] == 'active' and i['name'].startswith('ux356-mode-'))
            suffix = instance['name'].removeprefix('ux356-mode-')
            clone_name = 'ux356-mode-prod-' + suffix
            out = root / 'create-pipe'
            text = (out / 'stdout.bin').read_text()
            assert (out / 'stderr.bin').read_bytes() == b'' and b'\x1b' not in (out / 'stdout.bin').read_bytes()
            assert 'App instance: ' + instance['name'] in text
            assert_human_request_id(text, ['instance:create'], label='create-pipe')
            case = {'label': 'create-pipe', 'argv': ['php', str(launcher), 'instance:create', str(mode_app['id']), str(dev['id']), instance['name'], '--branch=' + mode_app['default_branch'], '--ansi'], 'candidate': args.candidate, 'exit': 0, 'actual_gateway': True, 'human_request_id_form': 'detail-tree', 'resumed_existing_capture': True, 'passed': True}
            save(out / 'case.json', case)
            if not any(r.get('label') == 'create-pipe' for r in records):
                records.append(case)
                save(root / 'records.json', records)
            print(json.dumps({'label': 'create-pipe', 'passed': True, 'resumed': True}), flush=True)
            mutation('clone', ['instance:clone', instance['id'], prod['id'], clone_name, '--preview-name=' + clone_name], 'App instance cloned.', mode)
            clone = next(i for i in read('instance:list')['app_instances'] if i['name'] == clone_name)
        else:
            suffix = uuid.uuid4().hex[:8]
            name = 'ux356-mode-' + suffix
            created = mutation('create', ['instance:create', mode_app['id'], dev['id'], name, '--branch=' + mode_app['default_branch']], 'App instance: ' + name, mode)
            instance = next(i for i in read('instance:list')['app_instances'] if i['name'] == name)
            if created is not None:
                assert without_request(created)['id'] == instance['id']
            clone_name = 'ux356-mode-prod-' + suffix
            mutation('clone', ['instance:clone', instance['id'], prod['id'], clone_name, '--preview-name=' + clone_name], 'App instance cloned.', mode)
            clone = next(i for i in read('instance:list')['app_instances'] if i['name'] == clone_name)
        mutation('update', ['instance:update', clone['id'], '--branch=codeowners'], 'Deployment branch', mode)
        mutation('step-create', ['instance:deploy-step:create', clone['id'], 'ux-mode', '--command=true', '--timeout=30'], 'Deploy step: ux-mode', mode)
        mutation('step-update', ['instance:deploy-step:update', clone['id'], 'ux-mode', '--timeout=40'], 'Deploy step: ux-mode', mode)
        mutation('step-destroy', ['instance:deploy-step:destroy', clone['id'], 'ux-mode', '--yes'], 'Destroyed deploy step ux-mode.', mode)
        mutation('transfer', ['instance:transfer', instance['id'], extra['id'], '--force'], 'Cleanup', mode)
        moved = read('instance:show', instance['id'])
        assert moved['node_id'] == extra['id']
        mutation('transfer-back', ['instance:transfer', instance['id'], dev['id'], '--force'], 'Cleanup', mode)
        mutation('destroy-clone', ['instance:destroy', clone['id'], '--yes'], 'removed.', mode)
        save(root / ('retained-mode-' + mode + '.json'), {'id': instance['id'], 'reason': 'orb-369-completed-transfer-history'})

elif args.stage == 'consent-automation':
    fixture = next(a for a in read('app:list')['apps'] if a['slug'] == 'ux356-fixture')
    name = 'ux356-consent-' + uuid.uuid4().hex[:8]
    created = read('instance:create', fixture['id'], dev['id'], name, '--branch=' + fixture['default_branch'])
    instance_id = created['id']
    before = without_request(read('instance:show', instance_id))
    json_refusal = read('instance:destroy', instance_id, expected=1, error='input.confirmation_required')
    record('destroy-json-refusal', ['instance:destroy', instance_id, '--json'], [], expected=1, json_payload=json_refusal, actual_gateway=False, error_code='input.confirmation_required')
    # --no-ansi only changes rendering. A PTY is still interactive and must prompt.
    argv = ['php', str(launcher), 'instance:destroy', str(instance_id), '--ansi']
    print('$ ' + shlex.join(argv) + ' [separate pipes]', flush=True)
    pipe = subprocess.run(argv, cwd=source, env=env, input=b'', capture_output=True, timeout=30)
    text = pipe.stdout.decode()
    assert pipe.returncode == 1 and pipe.stderr == b'' and b'\x1b' not in pipe.stdout, (pipe.returncode, text, pipe.stderr)
    assert 'Supply --yes to confirm this operation.' in text
    assert '○ Yes' not in text and '● No' not in text
    save(root / 'destroy-pipe-refusal.json', {'argv': argv, 'exit': pipe.returncode, 'stdout': text, 'actual_gateway': False})
    (root / 'destroy-pipe-refusal.stdout').write_bytes(pipe.stdout)
    (root / 'destroy-pipe-refusal.stderr').write_bytes(pipe.stderr)
    assert without_request(read('instance:show', instance_id)) == before
    record('destroy-plain-default-no', ['instance:destroy', instance_id], ['Remove App instance', 'App instance removal cancelled.'], expected=1, plain=True, key='\r', prompt='Yes')
    assert without_request(read('instance:show', instance_id)) == before
    force_refusal = read('instance:destroy', instance_id, '--force', expected=1, error='input.confirmation_required')
    record('destroy-force-json-refusal', ['instance:destroy', instance_id, '--force', '--json'], [], expected=1, json_payload=force_refusal, actual_gateway=False, error_code='input.confirmation_required')
    transfer_plain = subprocess.run(['php', str(launcher), 'instance:transfer', str(instance_id), str(extra['id']), '--no-ansi'], cwd=source, env=env, input=b'', capture_output=True, timeout=30)
    assert transfer_plain.returncode == 1 and b'\x1b' not in transfer_plain.stdout
    assert b'Use --force to confirm' in transfer_plain.stdout
    save(root / 'transfer-plain-refusal.json', {'exit': transfer_plain.returncode, 'stdout': transfer_plain.stdout.decode(), 'actual_gateway': False})
    record('destroy-yes', ['instance:destroy', instance_id, '--yes'], ['removed.', 'Removal: ' + name])
    assert not any(i['id'] == instance_id for i in read('instance:list')['app_instances'])
    assert without_request(read('instance:list'))['app_instances']

elif args.stage == 'unpublished-source':
    fixture = next(a for a in read('app:list')['apps'] if a['slug'] == 'ux356-fixture')
    name = 'ux356-pub-' + uuid.uuid4().hex[:8]
    record('create-unpublished', ['instance:create', fixture['id'], dev['id'], name, '--branch=' + fixture['default_branch']], ['App instance: ' + name])
    instance = next(i for i in read('instance:list')['app_instances'] if i['name'] == name)
    checkout = instance['checkout_path']
    remote(dev, 'git -C ' + shlex.quote(checkout) + ' -c user.name=CLIUX -c user.email=fixture@example.invalid commit --allow-empty -m ux356-unpublished')
    porcelain = remote(dev, 'git -C ' + shlex.quote(checkout) + ' status --porcelain --untracked-files=all')
    assert porcelain == '', porcelain
    unpublished = read('instance:destroy', instance['id'], '--yes', expected=1, error='instance.remove_refused')
    record('remove-unpublished-refusal', ['instance:destroy', instance['id'], '--yes'], ['dirty or unpublished source'], expected=1, json_payload=unpublished, actual_gateway=True, error_code='instance.remove_refused')
    save(root / 'unpublished-setup.json', {'name': name, 'id': instance['id'], 'porcelain': porcelain, 'kind': 'unpublished-commit'})
    assert any(i['id'] == instance['id'] for i in read('instance:list')['app_instances'])
    remote(dev, 'git -C ' + shlex.quote(checkout) + ' reset --hard HEAD~1')
    remote(dev, 'printf ux356-dirty > ' + shlex.quote(checkout + '/ux356-dirty.txt'))
    dirty_porcelain = remote(dev, 'git -C ' + shlex.quote(checkout) + ' status --porcelain --untracked-files=all')
    assert 'ux356-dirty.txt' in dirty_porcelain, dirty_porcelain
    dirty = read('instance:destroy', instance['id'], '--yes', expected=1, error='instance.remove_refused')
    record('remove-dirty-refusal', ['instance:destroy', instance['id'], '--yes'], ['dirty or unpublished source'], expected=1, json_payload=dirty, actual_gateway=True, error_code='instance.remove_refused')
    save(root / 'dirty-setup.json', {'name': name, 'id': instance['id'], 'porcelain': dirty_porcelain, 'kind': 'untracked-file'})
    record('remove-dirty-force', ['instance:destroy', instance['id'], '--yes', '--force'], ['removed.', 'Removal: ' + name])
    assert not any(i['id'] == instance['id'] for i in read('instance:list')['app_instances'])

elif args.stage == 'signals-empty':
    save(root / 'remaining-coverage.json', {
        'clone_partial_success': 'unverified',
        'empty_instance_list': 'unverified',
        'empty_instance_list_reason': 'instance:list has no empty filter; samples must not be deleted; no isolated empty registry was acquired',
        'empty_deploy_steps': 'disposable instance only; not a global instance list',
        'pending_http_sigint': 'unverified',
        'pending_http_sigterm': 'unverified',
        'native_visual': 'unverified',
        'complete': False,
    })
    fixture = next(a for a in read('app:list')['apps'] if a['slug'] == 'ux356-fixture')
    instance = next((i for i in read('instance:list')['app_instances'] if i['environment'] == 'development' and i['status'] == 'active' and i['name'].startswith('ux356-int-')), None)
    if instance is None:
        name = 'ux356-int-' + uuid.uuid4().hex[:8]
        create_label = 'create-interrupt-removal'
        if (root / create_label).exists():
            create_label = 'create-interrupt-removal-retry'
        record(create_label, ['instance:create', fixture['id'], dev['id'], name, '--branch=' + fixture['default_branch']], ['App instance: ' + name])
        instance = next(i for i in read('instance:list')['app_instances'] if i['name'] == name)
    name = instance['name']
    assert 'app-prod' in prod['roles'] and 'app-dev' not in prod['roles'], prod
    assert 'app-dev' in extra['roles'] and 'app-prod' not in extra['roles'], extra
    if not args.resume_after_empty_steps:
        empty_app = read('app:create', 'ux356-es-' + uuid.uuid4().hex[:8], 'https://github.com/mdn/todo-react.git', '--name=CLI UX empty steps', '--root=public')
        occupied = {i['app_id'] for i in read('instance:list')['app_instances'] if i['environment'] == 'production' and i['node_id'] == prod['id'] and i['status'] in ['active', 'reserved', 'source_resolved']}
        assert empty_app['id'] not in occupied, (empty_app, occupied)
        home = '/home/orbit-app-' + str(empty_app['id'])
        listing = remote(prod, 'if sudo test -e ' + shlex.quote(home) + '; then sudo find -P ' + shlex.quote(home) + ' -mindepth 1 -maxdepth 1 -print; else echo MISSING; fi').strip()
        save(root / 'empty-steps-preflight.json', {'prod_roles': prod['roles'], 'extra_roles': extra['roles'], 'app': empty_app, 'production_home': home, 'home_entries': listing, 'occupied_app_ids': sorted(occupied)})
        assert listing == 'MISSING', listing
        candidate_name = 'ux356-es-' + uuid.uuid4().hex[:8]
        record('create-empty-steps-candidate', ['instance:create', empty_app['id'], dev['id'], candidate_name, '--branch=' + empty_app['default_branch']], ['App instance: ' + candidate_name])
        candidate = next(i for i in read('instance:list')['app_instances'] if i['name'] == candidate_name)
        clone_name = candidate_name + '-prod'
        record('clone-empty-steps', ['instance:clone', candidate['id'], prod['id'], clone_name, '--preview-name=' + clone_name], ['App instance cloned.'])
        clone = next(i for i in read('instance:list')['app_instances'] if i['name'] == clone_name)
        assert clone['environment'] == 'production' and clone['status'] == 'active' and clone['node_id'] == prod['id'], clone
        empty_steps = read('instance:deploy-step:list', clone['id'])
        assert empty_steps.get('steps') == []
        record('empty-steps', ['instance:deploy-step:list', clone['id']], ['No deploy steps found.'])
        record('empty-steps-json-tty', ['instance:deploy-step:list', clone['id'], '--json'], [], json_payload=empty_steps)
    save(root / 'pending-http.json', {'status': 'unverified', 'reason': 'process-start timer and first spinner do not prove a Gateway request is in flight', 'sigint_exit': SIGINT_EXIT, 'sigterm_exit': SIGTERM_EXIT, 'prompt_decline_exit': 1})
    record('remove-interrupt', ['instance:destroy', instance['id'], '--yes'], ['Removing App instance', 'Operation interrupted.'], expected=SIGINT_EXIT, input_actions=[{'wait_for': 'Removing App instance', 'send': '\x03'}], max_after_input=5)
    remaining = next((i for i in read('instance:list')['app_instances'] if i['id'] == instance['id']), None)
    save(root / 'interrupt-state.json', remaining)
    if remaining is not None:
        record('remove-interrupt-retry', ['instance:destroy', instance['id'], '--yes'], ['removed.', 'Removal: ' + name])
        assert not any(i['id'] == instance['id'] for i in read('instance:list')['app_instances'])
    remaining_coverage = json.loads((root / 'remaining-coverage.json').read_text())
    remaining_coverage['empty_deploy_steps'] = 'production clone on app-prod with zero steps'
    remaining_coverage['pending_http_sigint'] = 'unverified'
    remaining_coverage['pending_http_sigterm'] = 'unverified'
    remaining_coverage['pending_http_note'] = 'process-start timer does not prove a Gateway request is in flight'
    save(root / 'remaining-coverage.json', remaining_coverage)

elif args.stage == 'clone-partial':
    if args.resume_after_clone_partial_human:
        log = json.loads((root / 'proxy-log.json').read_text())
        json_id = next(row['id'] for row in log if row['method'] == 'POST' and row['provenance'] == 'forwarded-real')
        human_id = next(row['id'] for row in reversed(log) if row['method'] == 'POST' and row['provenance'] == 'forwarded-real')
        json_clone = read('instance:show', json_id)
        human_clone = read('instance:show', human_id)
        assert json_clone['status'] == 'active' and json_clone['environment'] == 'production' and json_clone['node_id'] == prod['id']
        assert human_clone['status'] == 'active' and human_clone['environment'] == 'production' and human_clone['node_id'] == prod['id']
        human_post = next(row for row in reversed(log) if row['method'] == 'POST' and row['provenance'] == 'forwarded-real' and row['id'] == human_id)
        clone_rid = assert_clone_partial_human_warning(root / 'clone-partial-human', name=human_clone['name'], clone_id=human_clone['id'], post=human_post)
        json_machine = json.loads((root / 'clone-partial-json-tty/capture/raw.bin').read_bytes())
        assert_json_machine(json_machine, actual_gateway=True, error_code='deployment.releases_unavailable', json_payload=None, label='clone-partial-json-tty', argv=['instance:clone', '--json'])
        assert json_machine['error']['request_id'] == INJECTED_RELEASES_REQUEST_ID, json_machine
        assert 'request_ids' not in json_machine, json_machine
        json_post = next(row for row in log if row['id'] == json_id and row['method'] == 'POST')
        json_fault = next(row for row in log if row['id'] == json_id and row['method'] == 'GET')
        save(root / 'clone-partial-persisted.json', [
            {'mode': 'json-tty', 'clone': json_clone, 'post': json_post, 'injected_503': json_fault, 'error_request_id': json_machine['error']['request_id'], 'post_clone_request_id': json_post.get('request_id'), 'json_has_request_ids': False},
            {'mode': 'human', 'clone': human_clone, 'clone_request_id': clone_rid, 'post': next(row for row in log if row['id'] == human_id and row['method'] == 'POST'), 'injected_503': next(row for row in log if row['id'] == human_id and row['method'] == 'GET')},
        ])
        save(root / 'wrapping-diagnosis.json', {
            'writeHumanMessage': 'GatewayCommand::writeHumanMessage already wraps with TerminalText::wrap(columns). The 100-cell split of Clone is width-aware wrap, not terminal auto-wrap of an unwrapped error.',
            'cli_ux': 'Long labels and errors must not depend on terminal auto-wrap. This path uses wrap(). JSON/API unchanged.',
            'fixture': 'Reconstruct the CloneInstanceCommand.php:104 sentence across wrapped last-frame lines. Do not re-clone persisted IDs.',
        })
        print(json.dumps({'stage': args.stage, 'passed': True, 'resumed': True, 'records': len(records), 'root': str(root)}), flush=True)
        raise SystemExit(0)
    assert 'app-prod' in prod['roles'] and 'app-dev' not in prod['roles'], prod
    assert 'app-dev' in extra['roles'] and 'app-prod' not in extra['roles'], extra
    tls = root / 'proxy-tls'
    tls.mkdir()
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'cert.pem'), '-days', '1', '-nodes', '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1'], check=True, capture_output=True)
    config = json.loads((private / 'config.json').read_text())
    active = config['active_gateway']
    upstream = config['gateways'][active]['url']
    upstream_ca = config['gateways'][active]['ca_path']
    proxy_state = root / 'proxy.json'
    proxy = subprocess.Popen([sys.executable, str(FIXTURE_DIR / 'orb356-releases-fault-proxy.py'), '--upstream', upstream, '--upstream-ca', upstream_ca, '--cert', str(tls / 'cert.pem'), '--key', str(tls / 'key.pem'), '--state', str(proxy_state)], cwd=source)
    try:
        for _ in range(50):
            if proxy_state.exists():
                break
            time.sleep(0.1)
        listen = json.loads(proxy_state.read_text())
        config['gateways'][active] = {'url': 'https://127.0.0.1:' + str(listen['port']), 'ca_path': str(tls / 'cert.pem')}
        save(private / 'config.json', config)
        smoke = subprocess.run(['php', str(launcher), 'instance:list', '--json'], cwd=source, env=env, capture_output=True, timeout=30)
        save(root / 'proxy-smoke.json', {'exit': smoke.returncode, 'stdout': smoke.stdout.decode()[:500], 'stderr': smoke.stderr.decode()[:500]})
        assert smoke.returncode == 0 and json.loads(smoke.stdout).get('app_instances') is not None, smoke
        modes = [
            ('json-tty', 'https://github.com/mdn/learning-area.git', 'javascript', True),
            ('human', 'https://github.com/mdn/webaudio-examples.git', 'audio-buffer', False),
        ]
        persisted = []
        for mode, repo, web_root, use_json in modes:
            occupied = {i['app_id'] for i in read('instance:list')['app_instances'] if i['environment'] == 'production' and i['node_id'] == prod['id'] and i['status'] in ['active', 'reserved', 'source_resolved']}
            repos = {a['repository_url'] for a in read('app:list')['apps']}
            assert repo not in repos, (mode, repo, repos)
            partial_app = read('app:create', 'ux356-cp-' + mode[:4] + '-' + uuid.uuid4().hex[:6], repo, '--name=CLI UX clone ' + mode, '--root=' + web_root)
            home = '/home/orbit-app-' + str(partial_app['id'])
            listing = remote(prod, 'if sudo test -e ' + shlex.quote(home) + '; then sudo find -P ' + shlex.quote(home) + ' -mindepth 1 -maxdepth 1 -print; else echo MISSING; fi').strip()
            save(root / ('preflight-' + mode + '.json'), {'prod_roles': prod['roles'], 'extra_roles': extra['roles'], 'app': partial_app, 'production_home': home, 'home_entries': listing, 'occupied_app_ids': sorted(occupied), 'proxy': listen})
            assert partial_app['id'] not in occupied and listing == 'MISSING', (mode, partial_app, occupied, listing)
            cand_name = 'ux356-cp-' + mode[:4] + '-' + uuid.uuid4().hex[:6]
            record('create-' + mode, ['instance:create', partial_app['id'], dev['id'], cand_name, '--branch=' + partial_app['default_branch']], ['App instance: ' + cand_name])
            candidate = next(i for i in read('instance:list')['app_instances'] if i['name'] == cand_name)
            clone_name = cand_name + '-prod'
            ids_before = {i['id'] for i in read('instance:list')['app_instances']}
            if use_json:
                record('clone-partial-' + mode, ['instance:clone', candidate['id'], prod['id'], clone_name, '--preview-name=' + clone_name, '--json'], [], expected=1, actual_gateway=True, error_code='deployment.releases_unavailable')
            else:
                record('clone-partial-' + mode, ['instance:clone', candidate['id'], prod['id'], clone_name, '--preview-name=' + clone_name], ['was cloned; the selected release could not be read', 'Releases are unavailable.'], expected=1)
            config['gateways'][active] = {'url': upstream, 'ca_path': upstream_ca}
            save(private / 'config.json', config)
            after = read('instance:list')['app_instances']
            created = [i for i in after if i['id'] not in ids_before and i['environment'] == 'production' and i['app_id'] == partial_app['id']]
            assert len(created) == 1 and created[0]['status'] == 'active' and created[0]['node_id'] == prod['id'], (mode, created)
            shown = read('instance:show', created[0]['id'])
            assert shown['status'] == 'active' and shown['environment'] == 'production'
            log = json.loads((root / 'proxy-log.json').read_text()) if (root / 'proxy-log.json').exists() else json.loads(proxy_state.read_text()).get('log', [])
            posts = [row for row in log if row.get('method') == 'POST' and row.get('id') == created[0]['id'] and row.get('provenance') == 'forwarded-real']
            faults = [row for row in log if row.get('method') == 'GET' and row.get('id') == created[0]['id'] and row.get('provenance') == 'fixture-injected']
            assert posts and faults, (mode, created[0]['id'], log)
            entry = {'mode': mode, 'app_id': partial_app['id'], 'clone': shown, 'post': posts[-1], 'injected_503': faults[-1]}
            if use_json:
                machine = json.loads((root / 'clone-partial-json-tty/capture/raw.bin').read_bytes())
                assert_json_machine(machine, actual_gateway=True, error_code='deployment.releases_unavailable', json_payload=None, label='clone-partial-json-tty', argv=['instance:clone', '--json'])
                assert machine['error']['request_id'] == INJECTED_RELEASES_REQUEST_ID, machine
                assert 'request_ids' not in machine, machine
                if faults[-1].get('request_id'):
                    assert machine['error']['request_id'] == faults[-1]['request_id'], (machine, faults[-1])
                entry['error_request_id'] = machine['error']['request_id']
                entry['post_clone_request_id'] = posts[-1].get('request_id')
                entry['json_has_request_ids'] = False
            else:
                clone_rid = assert_clone_partial_human_warning(root / 'clone-partial-human', name=shown['name'], clone_id=shown['id'], post=posts[-1])
                entry['clone_request_id'] = clone_rid
            persisted.append(entry)
            config['gateways'][active] = {'url': 'https://127.0.0.1:' + str(listen['port']), 'ca_path': str(tls / 'cert.pem')}
            save(private / 'config.json', config)
        save(root / 'clone-partial-persisted.json', persisted)
        assert len(persisted) == 2 and {row['clone']['id'] for row in persisted} == {row['post']['id'] for row in persisted}
        save(root / 'wrapping-diagnosis.json', {
            'writeHumanMessage': 'GatewayCommand::writeHumanMessage already wraps with TerminalText::wrap(columns). The 100-cell split of Clone is width-aware wrap, not terminal auto-wrap of an unwrapped error.',
            'cli_ux': 'Long labels and errors must not depend on terminal auto-wrap. This path uses wrap(). JSON/API unchanged.',
            'fixture': 'Reconstruct the CloneInstanceCommand.php warning across wrapped last-frame lines after the authoritative clone ID is known. Correlate Clone request ID with the forwarded POST request_id when the proxy recorded it. Do not re-clone persisted IDs.',
        })
    finally:
        config['gateways'][active] = {'url': upstream, 'ca_path': upstream_ca}
        save(private / 'config.json', config)
        proxy.terminate()
        try:
            proxy.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proxy.kill()

elif args.stage == 'pending-http':
    tls = root / 'proxy-tls'
    tls.mkdir()
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'cert.pem'), '-days', '1', '-nodes', '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1'], check=True, capture_output=True)
    config = json.loads((private / 'config.json').read_text())
    active = config['active_gateway']
    upstream = config['gateways'][active]['url']
    upstream_ca = config['gateways'][active]['ca_path']
    delay = 8.0
    after_arrival = 0.4
    evidence = []
    for sig_name, expected in [('SIGINT', SIGINT_EXIT), ('SIGTERM', SIGTERM_EXIT)]:
        marker = root / ('arrived-' + sig_name)
        if marker.exists():
            marker.unlink()
        signaled = Path(str(marker) + '.signaled')
        if signaled.exists():
            signaled.unlink()
        proxy_state = root / ('proxy-' + sig_name + '.json')
        proxy = subprocess.Popen([sys.executable, str(FIXTURE_DIR / 'orb356-pending-http-proxy.py'), '--upstream', upstream, '--upstream-ca', upstream_ca, '--cert', str(tls / 'cert.pem'), '--key', str(tls / 'key.pem'), '--marker', str(marker), '--delay', str(delay), '--state', str(proxy_state)], cwd=source)
        try:
            for _ in range(50):
                if proxy_state.exists():
                    break
                time.sleep(0.1)
            listen = json.loads(proxy_state.read_text())
            config['gateways'][active] = {'url': 'https://127.0.0.1:' + str(listen['port']), 'ca_path': str(tls / 'cert.pem')}
            save(private / 'config.json', config)
            env.update({'ORB356_SIGNAL': sig_name, 'ORB356_PENDING_MARKER': str(marker), 'ORB356_SIGNAL_AFTER_ARRIVAL': str(after_arrival)})
            env.pop('ORB356_SIGNAL_AFTER', None)
            record('list-pending-' + sig_name.lower(), ['instance:list'], ['Loading App instances', 'Operation interrupted.'], expected=expected)
        finally:
            config['gateways'][active] = {'url': upstream, 'ca_path': upstream_ca}
            save(private / 'config.json', config)
            env.pop('ORB356_SIGNAL', None)
            env.pop('ORB356_PENDING_MARKER', None)
            env.pop('ORB356_SIGNAL_AFTER_ARRIVAL', None)
            proxy.terminate()
            try:
                proxy.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proxy.kill()
        summary = json.loads((root / ('list-pending-' + sig_name.lower()) / 'capture/summary.json').read_text())
        arrival = json.loads(marker.read_text())
        signal_meta = json.loads(signaled.read_text()) if signaled.exists() else {}
        assert marker.exists() and signaled.exists(), (sig_name, arrival, signal_meta)
        assert summary['duration_seconds'] < delay, (sig_name, summary, delay)
        assert summary['child_exit_code'] == expected, (sig_name, summary)
        frames = [json.loads(line) for line in (root / ('list-pending-' + sig_name.lower()) / 'capture/frames.jsonl').read_text().splitlines()]
        assert frames[-1]['cursor']['hidden'] is False, sig_name
        evidence.append({
            'signal': sig_name,
            'expected_exit': expected,
            'arrival': arrival,
            'signaled': signal_meta,
            'duration': summary['duration_seconds'],
            'first_output': summary['first_output_seconds'],
            'cursor_hidden_final': frames[-1]['cursor']['hidden'],
            'did_not_wait_for_delayed_response': summary['duration_seconds'] < delay,
            'arrival_proves': 'request waiting at fixture proxy, not upstream Gateway processing',
            'upstream_forward': 'only after delay; interrupt occurs during proxy hold',
            'proxy': json.loads(proxy_state.read_text()),
        })
    save(root / 'pending-http-evidence.json', evidence)

elif args.stage == 'empty-list':
    tls = root / 'mock-tls'
    tls.mkdir()
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'cert.pem'), '-days', '1', '-nodes', '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1'], check=True, capture_output=True)
    mock_state = root / 'mock.json'
    mock = subprocess.Popen([sys.executable, str(FIXTURE_DIR / 'orb356-empty-list-mock.py'), '--cert', str(tls / 'cert.pem'), '--key', str(tls / 'key.pem'), '--state', str(mock_state)], cwd=source)
    try:
        for _ in range(50):
            if mock_state.exists():
                break
            time.sleep(0.1)
        listen = json.loads(mock_state.read_text())
        config = json.loads((private / 'config.json').read_text())
        active = config['active_gateway']
        original = config['gateways'][active]
        config['gateways'][active] = {'url': 'https://127.0.0.1:' + str(listen['port']), 'ca_path': str(tls / 'cert.pem')}
        save(private / 'config.json', config)
        import ssl as sslmod
        import urllib.request as urlreq
        ctx = sslmod.create_default_context()
        ctx.load_verify_locations(str(tls / 'cert.pem'))
        wire = urlreq.urlopen('https://127.0.0.1:' + str(listen['port']) + '/api/v1/instances', context=ctx, timeout=10)
        wire_body = json.loads(wire.read().decode())
        wire_header = wire.headers.get('X-Orbit-Request-Id')
        save(root / 'empty-list-wire.json', {'status': wire.status, 'body': wire_body, 'request_id_header': wire_header})
        assert wire.status == 200
        assert wire_body == {'data': [], 'meta': {'request_id': listen['request_id']}}
        assert 'app_instances' not in wire_body
        assert wire_header == listen['request_id']
        empty = {'app_instances': [], 'request_id': listen['request_id']}
        record('instances-empty-human', ['instance:list'], ['No App instances found.', 'Request ID:'], columns=100)
        record('instances-empty-plain', ['instance:list'], ['No App instances found.', 'Request ID:'], columns=100, plain=True)
        record('instances-empty-json-tty', ['instance:list', '--json'], [], json_payload=empty)
        pipe = subprocess.run(['php', str(launcher), 'instance:list', '--ansi'], cwd=source, env=env, capture_output=True, timeout=30)
        assert pipe.returncode == 0 and b'\x1b' not in pipe.stdout and b'No App instances found.' in pipe.stdout
        (root / 'instances-empty-pipe.stdout').write_bytes(pipe.stdout)
        save(root / 'empty-list-isolation.json', {
            'kind': 'controlled-empty-response-cli-rendering',
            'not_real_registry_integration': True,
            'real_registry_untouched': True,
            'gateway_envelope': {'data': [], 'meta': {'request_id': listen['request_id']}},
            'cli_envelope': empty,
            'mock': listen,
        })
    finally:
        config['gateways'][active] = original
        save(private / 'config.json', config)
        mock.terminate()
        try:
            mock.wait(timeout=5)
        except subprocess.TimeoutExpired:
            mock.kill()
    real = read('instance:list')
    assert len(real['app_instances']) > 0
    save(root / 'real-list-after-empty-mock.json', {'count': len(real['app_instances'])})

elif args.stage == 'native-visual':
    def cell(value):
        if value is None:
            return '—'
        if isinstance(value, bool):
            return 'yes' if value else 'no'
        return value

    def removal_cell(removal):
        if not removal:
            return None
        summary = ('forced' if removal.get('force') else 'normal') + ' ' + str(removal['completed']) + '/' + str(removal['total']) + ' completed; ' + str(removal['remaining']) + ' remaining; ' + (removal.get('current_step') or '—')
        if removal.get('failed_step') is not None:
            summary += '; failed ' + str(removal['failed_step']) + ' (' + str(removal.get('error_code') or '—') + ')'
        return summary

    list_payload = read('instance:list')
    instance_headers = ['ID', 'APP', 'NODE', 'VITE PORT', 'NAME', 'ENVIRONMENT', 'SOURCE LAYOUT', 'ROOT', 'SELECTED BRANCH', 'BRANCH OVERRIDE', 'MIGRATION REQUIRED', 'ROUTE DOMAIN', 'URL', 'STATUS', 'REMOVAL']
    instance_rows = [[cell(v) for v in [
        inst['id'], inst['app_id'], inst['node_id'], inst.get('vite_port'), inst['name'], inst['environment'],
        inst['source_layout'], inst.get('effective_root'), inst.get('selected_branch'), inst.get('branch_override'),
        'yes' if inst.get('migration_required') else 'no', inst.get('domain'), inst.get('url'), inst['status'],
        removal_cell(inst.get('removal')),
    ]] for inst in list_payload['app_instances']]
    native_list = root / 'native-list'
    if args.resume_after_native_list:
        assert native_list.exists(), 'discovery resume requires a preserved native-list capture'
        frames = [json.loads(line) for line in (native_list / 'capture/frames.jsonl').read_text().splitlines()]
        reconstructed = assert_table_matches_api(frames, instance_rows, instance_headers, label='native-list-discovery-resume')
        screen = assert_native_list_screen(frames, terminal=native_list / 'terminal.json', label='native-list-discovery-resume')
        save(native_list / 'expected-table.json', instance_rows)
        save(native_list / 'reconstructed-table.json', reconstructed)
        save(native_list / 'table-screen.json', screen)
        save(native_list / 'native-list-linkage.json', {
            'kind': 'discovery-only-preserved-capture-inspection',
            'not_fresh_proof': True,
            'not_recaptured': True,
            'equivalent_accepted_content': 'reads/instances-human 200-col schema table',
            'limitation': 'Diagnostic resume inspects a preserved capture. Fresh proof must recapture with passing verify.py artifacts and must not depend on a historical contains failure.',
        })
    else:
        assert not native_list.exists(), 'fresh native-list would overwrite a preserved capture; move it aside first'
        out = record('native-list', ['instance:list'], ['Request ID:'], columns=100, table=instance_rows, table_headers=instance_headers)
        frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
        reconstructed = json.loads((out / 'reconstructed-table.json').read_text())
        screen = assert_native_list_screen(frames, terminal=out / 'terminal.json', label='native-list')
        save(out / 'table-screen.json', screen)
        save(out / 'native-list-fresh.json', {
            'kind': 'fresh-path-wrap-reconstruction',
            'not_historical_contains': True,
            'verify_py': 'one-line Request ID: footer; table body is wrap reconstruction vs API first-seen order',
            'animation': screen['animation_observation'],
            'first_seen_ids': reconstructed['ids'],
            'not_published_proof': True,
        })
        verify = json.loads((out / 'verify.json').read_text())
        assert verify['passed'] is True, verify
        assert verify.get('failures', []) == []
    if not (root / 'native-show-prod').exists():
        show_out = record('native-show-prod', ['instance:show', production['id']], ['App instance: e2e-prod', 'Production user', 'Production home'], columns=100)
        show_frames = [json.loads(line) for line in (show_out / 'capture/frames.jsonl').read_text().splitlines()]
        last = show_frames[-1]
        text = '\n'.join(last['lines'])
        connectors = [cell for cell in last.get('cells') or [] if (cell.get('data') or '') in '┌┐└┘│─┬┤├']
        save(show_out / 'show-screen.json', {
            'cursor': last.get('cursor'),
            'has_title': 'App instance: e2e-prod' in text,
            'request_id_form': 'detail-tree Request ID without colon' if re.search(r'Request ID\s+[0-9a-f-]', text) and 'Request ID:' not in text else text[text.find('Request ID'):text.find('Request ID') + 40] if 'Request ID' in text else None,
            'connector_dim': sorted({cell.get('dim') for cell in connectors}),
            'cell_schema': 'data',
        })

elif args.stage == 'fixture-cleanup':
    # Restore extra to the extendedAppProd recipe: app-prod-2 keeps app-prod.
    # Prepare swapped that for app-dev so transfer tests had an empty destination.
    extra_now = read('node:show', extra['id'])
    leftover = [inst for inst in read('instance:list')['app_instances'] if inst['node_id'] == extra['id']]
    for inst in leftover:
        read('instance:transfer', inst['id'], dev['id'], '--yes', '--force')
    extra_now = read('node:show', extra['id'])
    if 'app-prod' not in extra_now['roles']:
        read('node:role:add', extra['id'], 'app-prod')
    extra_now = read('node:show', extra['id'])
    if 'app-dev' in extra_now['roles']:
        read('node:role:remove', extra['id'], 'app-dev', '--force')
    extra_now = read('node:show', extra['id'])
    if extra_now.get('cluster_id') is not None:
        read('cluster:node:remove', extra_now['cluster_id'], extra['id'], '--force')
    restored = read('node:show', extra['id'])
    leftover = [inst for inst in read('instance:list')['app_instances'] if inst['node_id'] == extra['id']]
    assert restored['status'] == 'active' and restored['roles'] == ['app-prod'] and leftover == [], restored
    save(root / 'restored-destination.json', {'node': restored, 'leftover_instances': leftover})

elif args.stage == 'coverage-report':
    def exists(rel):
        return (args.state / rel).exists()

    def load_json(rel):
        path = args.state / rel
        return json.loads(path.read_text()) if path.exists() else None

    def labels(rel):
        rows = load_json(rel)
        return None if rows is None else [row.get('label') for row in rows]

    def assert_verified_cases(stage, expected):
        rows = load_json(stage + '/records.json')
        assert rows is not None, (stage, 'missing records.json')
        got = [row.get('label') for row in rows]
        missing_cases = [label for label in expected if label not in got]
        assert missing_cases == [], (stage, 'missing expected cases', missing_cases, got)
        failed = []
        for label in expected:
            verify = load_json(stage + '/' + label + '/verify.json')
            if not verify or verify.get('passed') is not True or verify.get('failures'):
                failed.append({'label': label, 'verify': verify})
        assert failed == [], (stage, 'verify did not pass', failed)
        return got

    invalid_negatives = [
        ('instance:show', 'instance.id_invalid'),
        ('instance:create', 'app.id_invalid'),
        ('instance:clone', 'instance.candidate_id_invalid'),
        ('instance:clone', 'instance.preview_name_required'),
        ('instance:update', 'instance.id_invalid'),
        ('instance:destroy', 'instance.id_invalid'),
        ('instance:transfer', 'instance.id_invalid'),
        ('instance:deploy-step:list', 'instance.id_invalid'),
        ('instance:deploy-step:create', 'instance.id_invalid'),
        ('instance:release:list', 'instance.id_invalid'),
        ('instance:register', 'instance.source_invalid'),
    ]
    invalid_cases = [
        command.replace(':', '-') + '-' + error.replace('.', '-') + suffix
        for command, error in invalid_negatives
        for suffix in ('-human', '-json-tty', '-plain')
    ] + ['instance-show-missing-json-tty']
    gateway_cases = {
        'pending-http': ['list-pending-sigint', 'list-pending-sigterm'],
        'empty-list': ['instances-empty-human', 'instances-empty-plain', 'instances-empty-json-tty'],
        'clone-partial': ['create-json-tty', 'clone-partial-json-tty', 'create-human', 'clone-partial-human'],
        'native-visual': ['native-list', 'native-show-prod'],
        'invalid': invalid_cases,
    }
    app_dev_cases = {
        'register': [
            'register-default-no', 'register-cancel', 'register-eof', 'register-json-refusal',
            'register-invalid-root', 'register-invalid-default-branch', 'register', 'remove-registration',
        ],
        'retry': [
            'registration-retry-and-decline', 'registration-retry-and-accept', 'remove-retry-accept',
            'register-credential-origin', 'register-cancel-at-branch', 'register-include-worktrees',
            'remove-include-worktrees',
        ],
    }
    required_files = {
        'gateway': [
            'prepare/prepared.json',
            'source/records.json',
            'mutation-modes/records.json',
            'consent-automation/records.json',
            'unpublished-source/records.json',
            'reads/records.json',
            'reads-narrow/records.json',
            'signals-empty/records.json',
            'clone-partial/clone-partial-persisted.json',
            'pending-http/pending-http-evidence.json',
            'empty-list/empty-list-isolation.json',
            'empty-list/empty-list-wire.json',
            'native-visual/native-list/reconstructed-table.json',
        ],
        'app-dev': [],
    }
    missing_files = [rel for rel in required_files[args.node_scope] if not exists(rel)]
    assert missing_files == [], (args.node_scope, 'missing required files', missing_files)
    expected_cases = gateway_cases if args.node_scope == 'gateway' else app_dev_cases
    verified = {stage: assert_verified_cases(stage, expected) for stage, expected in expected_cases.items()}
    if args.node_scope == 'gateway':
        pending = load_json('pending-http/pending-http-evidence.json')
        assert isinstance(pending, list) and {row.get('signal') for row in pending} == {'SIGINT', 'SIGTERM'}, pending
        empty_iso = load_json('empty-list/empty-list-isolation.json')
        assert empty_iso and empty_iso.get('kind') == 'controlled-empty-response-cli-rendering', empty_iso
        clone_persisted = load_json('clone-partial/clone-partial-persisted.json')
        assert isinstance(clone_persisted, list) and len(clone_persisted) == 2, clone_persisted
        json_clone = next(row for row in clone_persisted if row.get('mode') == 'json-tty')
        assert json_clone.get('json_has_request_ids') is False
        assert json_clone.get('error_request_id') == INJECTED_RELEASES_REQUEST_ID
    native_verify = load_json('native-visual/native-list/verify.json')
    show_verify = load_json('native-visual/native-show-prod/verify.json')
    report = {
        'kind': 'aggregate-coverage',
        'node_scope': args.node_scope,
        'evidence_gate': 'passed',
        'issue_acceptance': 'separate',
        'issue_review': 'separate',
        'verified_cases': verified,
        'expected_cases': expected_cases,
        'signals_empty_scoped': load_json('signals-empty/remaining-coverage.json'),
        'linked': {
            'prepare': exists('prepare/prepared.json'),
            'invalid': labels('invalid/records.json'),
            'source': labels('source/records.json'),
            'mutation_modes': labels('mutation-modes/records.json'),
            'consent_automation': labels('consent-automation/records.json'),
            'unpublished_source': labels('unpublished-source/records.json'),
            'reads': labels('reads/records.json'),
            'reads_narrow': labels('reads-narrow/records.json'),
            'register': labels('register/records.json'),
            'retry': labels('retry/records.json'),
            'signals_empty': labels('signals-empty/records.json'),
            'clone_partial': labels('clone-partial/records.json'),
            'pending_http': {
                'records': labels('pending-http/records.json'),
                'evidence': load_json('pending-http/pending-http-evidence.json'),
            },
            'empty_list': {
                'records': labels('empty-list/records.json'),
                'isolation': load_json('empty-list/empty-list-isolation.json'),
            },
            'native_visual': {
                'records': labels('native-visual/records.json'),
                'list_verify': native_verify,
                'show_verify': show_verify,
            },
        },
    }
    save(root / 'coverage-aggregate.json', report)

print(json.dumps({'stage': args.stage, 'passed': True, 'records': len(records), 'root': str(root)}), flush=True)
