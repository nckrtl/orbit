#!/usr/bin/env python3
"""Record real doctor and deployment-stream operations and verify resulting guest state."""
import argparse
import json
import os
import re
import select
import shlex
import shutil
import signal
import subprocess
import sys
import termios
import time
from pathlib import Path

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    stderr_file = open(os.environ['ORB361_CAPTURE_STDERR'], 'wb') if os.environ.get('ORB361_CAPTURE_STDERR') else None
    child = subprocess.Popen(sys.argv[3:], stderr=stderr_file)
    # A foreground Ctrl-C delivers SIGINT to this wrapper too (same PTY process group), which
    # raises KeyboardInterrupt inside wait() before the child (already dying from its own SIGINT)
    # actually exits. Keep waiting instead of letting that propagate uncaught, or terminal.json
    # never gets written. A signal-terminated child reports a negative Popen.returncode; convert
    # to the shell's 128+signum convention so --release=... exit-code assertions stay comparable.
    while True:
        try:
            child.wait()
            break
        except KeyboardInterrupt:
            continue
    code = child.returncode
    if code < 0:
        code = 128 - code
    if stderr_file is not None:
        stderr_file.close()
    after = termios.tcgetattr(0)
    Path(sys.argv[2]).write_text(json.dumps({'before': repr(before), 'after': repr(after), 'equal': before == after, 'exit': code}))
    sys.exit(code)

STAGES = [
    'doctor-baseline', 'doctor-drift-prepare', 'doctor-drift-report', 'doctor-unverifiable-report',
    'doctor-clean-recheck',
    'deploy-prepare', 'deploy-success', 'deploy-chatty', 'deploy-interrupt',
    'deploy-silent-interrupt-decorated', 'deploy-silent-interrupt-pipe', 'deploy-silent-interrupt-json',
    'deploy-failure', 'deploy-after-activation-failure', 'deploy-operation-failure',
    'rollback-explicit', 'rollback-json', 'rollback-pipe', 'rollback-plain',
    'rollback-activation-failure', 'rollback-no-release',
    'rollback-invalid', 'rollback-missing-instance', 'deploy-missing-instance',
    'json-parity', 'resulting-state', 'coverage-report',
]

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--stage', choices=STAGES, required=True)
parser.add_argument('--visible', action='store_true')
parser.add_argument('--node-scope', choices=['gateway'], default='gateway')
parser.add_argument('--rehearsal', action='store_true',
                     help='Skip the candidate git check; a discovery topology bind-mounts the '
                          'worktree, whose .git file points at a Beast-host gitdir path that is '
                          'not reachable from inside the guest. Never used for a real prove run.')
args = parser.parse_args()

os.umask(0o077)
source = Path('/home/orbit/orbit')
if not args.rehearsal:
    assert subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() == args.candidate
    assert not subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()

root = args.state / args.stage
root.mkdir(parents=True, exist_ok=True)
private = root / 'gateway-home'
private.mkdir(exist_ok=True)
shutil.copyfile(Path.home() / '.orbit/config.json', private / 'config.json')
known_hosts = root / 'known_hosts'
if (Path.home() / '.orbit/ssh/known_hosts').exists():
    shutil.copyfile(Path.home() / '.orbit/ssh/known_hosts', known_hosts)
env = dict(os.environ, ORBIT_HOME=str(private), TERM='xterm-256color', LC_ALL='C.UTF-8')
for key in ['NO_COLOR', 'FORCE_COLOR', 'CLICOLOR', 'COLUMNS', 'LINES']:
    env.pop(key, None)
launcher = source / 'apps/cli/orbit'
recorder = source / '.agents/skills/verifying-cli-output/scripts'
records, observations = [], []

FIXTURE_APP_SLUG = 'orb361-deploy-fixture'
FIXTURE_APP_REPO = 'https://github.com/mdn/beginner-html-site-styled.git'
FIXTURE_APP_ROOT = 'styles'
FIXTURE_DEV_NAME = 'orb361-dev'
FIXTURE_PROD_NAME = 'orb361-prod'
FIXTURE_PREVIEW_NAME = 'orb361-deploy-fixture'
FIXTURE_PROCESS_NAME = 'orb361-drift-check'
FIXTURE_BRANCH = 'main'
FIXTURE_HEALTH_PATH = '/style.css'  # index.html lives outside --root=styles; this file does not

ANSI_GREEN = b'\x1b[32m'
ANSI_ORANGE = b'\x1b[38;5;208m'
ANSI_RED = b'\x1b[31m'
ANSI_DIM = b'\x1b[2m'
ANSI_RESET = b'\x1b[0m'

DOCTOR_STATE_ROWS = [{
    'name': 'doctor-progress', 'pattern': r'[○◉●]\s+(?P<state>Verifying|Verified|Verify) registered state',
    'states': ['Verify', 'Verifying', 'Verified'],
    'transitions': [['Verify', 'Verifying'], ['Verifying', 'Verified']],
    'required': ['Verified'],
}]
DOCTOR_ANIMATION_ROWS = [{
    # The pattern must match only the active (waiting/running) glyphs and label, never the
    # settled one; ● + "Verified" also matching `pattern` hides the terminal transition from
    # verify.py entirely (confirmed against a real capture: 2.2s of real animation misread as
    # "cadence outside bounds" / "terminal state missing" because it never stopped matching).
    'name': 'doctor-progress', 'pattern': r'(?P<glyph>[○◉])\s+Verifying registered state',
    'terminal_pattern': r'●\s+Verified registered state',
    'minimum_changes': 1, 'min_interval': 0.1, 'max_interval': 3.0,
}]


def save(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')


def state_path(stage, name):
    return args.state / stage / name


def load_state(stage, name):
    return json.loads(state_path(stage, name).read_text())


def read(*argv, expected=0, error=None):
    command = ['php', str(launcher), *map(str, argv), '--json']
    result = subprocess.run(command, cwd=source, env=env, capture_output=True, input=b'', timeout=240)
    assert result.returncode == expected, (argv, result.returncode, result.stdout, result.stderr)
    assert result.stderr == b'', (argv, result.stderr)
    payload = json.loads(result.stdout)
    if error:
        assert payload['error']['code'] == error, (argv, payload)
    observations.append({'argv': command, 'exit': result.returncode, 'payload': payload})
    save(root / 'observations.json', observations)
    return payload


REQUEST_ID_UUID = re.compile(r'\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z', re.I)
REQUEST_ID_BYTES = re.compile(rb'[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', re.I)
RELEASE_NAME_BYTES = re.compile(rb'\b\d{14}-[0-9a-f]{4,}\b')


def assert_request_id(value, *, label, actual_gateway=True):
    if actual_gateway:
        assert isinstance(value, str) and REQUEST_ID_UUID.fullmatch(value), (label, 'expected a Gateway request_id', value)
    else:
        assert value is None, (label, 'a local validation failure never reaches the Gateway', value)


def normalize_parity_bytes(raw):
    """Replaces per-run request IDs and release names with fixed placeholders (R3): two separate
    deploys — one candidate, one `main` — legitimately generate different request IDs and release
    names (a timestamp plus a random suffix), but every other byte, including field order and
    field names, must match exactly. Everything else in the wire events (phase, step_name,
    stream, data_base64, status, failed_step, error_code) is deterministic for a given fixture
    scenario, so a byte comparison after only these two substitutions is a real parity check, not
    a weakened one."""
    normalized = REQUEST_ID_BYTES.sub(b'<request-id>', raw)
    return RELEASE_NAME_BYTES.sub(b'<release>', normalized)


def record(label, argv, contains, *, expected=0, columns=100, plain=False, absent=None,
           animation_rows=None, state_rows=None, json_payload=None, error_code=None, json_mode='envelope',
           max_first_output_seconds=3, idle_timeout=90, timeout=255, input_actions=None,
           raw_contains=None, raw_absent=None, actual_gateway=True):
    """json_mode 'envelope' is one JSON document (doctor, and refusals before any stream opens).
    json_mode 'ndjson' is the deploy/rollback event stream: one JSON object per line, the last
    line is the terminal `result` event, and a failure never becomes an `{"error": ...}` envelope.
    Channel separation comes from the one real invocation: the --child wrapper redirects the
    command's own stderr to stderr.bin outside the PTY, so raw.bin is pure stdout. This never
    re-runs a mutating command a second time just to inspect its streams, so it is safe to use
    for interactive cases (input_actions) too."""
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--no-ansi' if plain else '--ansi']
    capture = [sys.executable, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
               '--candidate', args.candidate, '--label', label, '--columns', str(columns), '--rows', '50',
               '--timeout', str(timeout - 5), '--idle-timeout', str(idle_timeout)]
    if not args.visible:
        capture.append('--no-live')
    if input_actions is not None:
        save(out / 'inputs.json', input_actions)
        capture += ['--input-plan', str(out / 'inputs.json')]
    capture += ['--', sys.executable, str(Path(__file__).resolve()), '--child', str(out / 'terminal.json'), *command]
    print('$ ' + shlex.join(command), flush=True)
    capture_env = dict(env, ORB361_CAPTURE_STDERR=str(out / 'stderr.bin'))
    result = subprocess.run(capture, cwd=source, env=capture_env, capture_output=not args.visible, timeout=timeout)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal'], label
    stderr_bytes = (out / 'stderr.bin').read_bytes()
    assert stderr_bytes == b'', (label, 'stderr must stay empty', stderr_bytes)

    raw_bytes = (out / 'capture/raw.bin').read_bytes()
    if '--json' in argv:
        assert b'\x1b' not in raw_bytes, (label, 'no ANSI in JSON output')
        if json_mode == 'ndjson':
            lines = [json.loads(line) for line in raw_bytes.decode().splitlines() if line]
            assert lines, (label, 'NDJSON stream produced no lines')
            for line in lines:
                assert_request_id(line.get('request_id'), label=label, actual_gateway=actual_gateway)
            last = lines[-1]
            assert last.get('type') == 'result', (label, last)
            expected_status = 'succeeded' if expected == 0 else 'failed'
            assert last.get('status') == expected_status, (label, last)
            if expected != 0:
                assert last.get('failed_step') is not None and last.get('error_code') is not None, (label, last)
                if error_code is not None:
                    assert last['error_code'] == error_code, (label, last)
            save(out / 'direct-json.json', lines)
        else:
            machine = json.loads(raw_bytes)
            if error_code is not None:
                assert isinstance(machine.get('error'), dict) and machine['error']['code'] == error_code, (label, machine)
                assert_request_id(machine['error']['request_id'], label=label, actual_gateway=actual_gateway)
            else:
                assert 'error' not in machine, (label, machine)
                if 'request_id' in machine:
                    assert_request_id(machine['request_id'], label=label, actual_gateway=actual_gateway)
            if json_payload is not None:
                stripped_machine = {k: v for k, v in machine.items() if k != 'request_id'}
                stripped_expected = {k: v for k, v in json_payload.items() if k != 'request_id'}
                assert stripped_machine == stripped_expected, (label, machine, json_payload)
            save(out / 'direct-json.json', machine)
    elif expected != 0:
        # Human channel placement: the failure message lands on stdout (raw.bin); stderr stays empty.
        for needle in contains:
            assert needle.encode() in raw_bytes, (label, 'expected human text on stdout', needle)

    captured_frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
    assert captured_frames[-1]['cursor']['hidden'] is False, label
    for needle in raw_contains or []:
        assert needle in raw_bytes, (label, 'expected raw byte sequence', needle)
    for needle in raw_absent or []:
        assert needle not in raw_bytes, (label, 'forbidden raw byte sequence', needle)

    expectation = {'candidate': args.candidate, 'label': label, 'exit_code': expected}
    if '--json' in argv:
        expectation['absent'] = list(dict.fromkeys([*(absent or []), 'Request ID:', 'AppInstance [']))
    else:
        expectation['contains'] = contains
        expectation['final_contains'] = contains
        if absent:
            expectation['absent'] = absent
        expectation['max_first_output_seconds'] = max_first_output_seconds
        if animation_rows:
            expectation['animation_rows'] = animation_rows
        if state_rows:
            expectation['state_rows'] = state_rows
    save(out / 'expectation.json', expectation)
    verified = subprocess.run(
        [sys.executable, str(recorder / 'verify.py'), '--capture', str(out / 'capture'), '--expect', str(out / 'expectation.json')],
        capture_output=True, text=True,
    )
    (out / 'verify.json').write_text(verified.stdout)
    assert verified.returncode == 0, (label, verified.stdout, verified.stderr)
    if plain:
        assert b'\x1b' not in (out / 'capture/raw.bin').read_bytes(), label

    case = {
        'label': label, 'argv': command, 'candidate': args.candidate, 'environment': 'disposable-incus',
        'exit': expected, 'columns': columns, 'plain': plain, 'json': '--json' in argv, 'passed': True,
    }
    save(out / 'case.json', case)
    records.append(case)
    save(root / 'records.json', records)
    print(json.dumps({'label': label, 'passed': True}), flush=True)
    return out


def pipe_case(label, argv, contains=None, *, expected=0):
    """Undecorated piped output: separate-stream check, no ANSI, no forced decoration."""
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv)]
    result = subprocess.run(command, cwd=source, env=env, input=b'', capture_output=True, timeout=240)
    assert result.returncode == expected, (label, result)
    assert b'\x1b' not in result.stdout, (label, 'piped stdout must carry no ANSI')
    assert result.stderr == b'', (label, 'piped stderr must stay empty', result.stderr)
    for needle in contains or []:
        assert needle.encode() in result.stdout, (label, 'expected piped text on stdout', needle)
    (out / 'stdout.bin').write_bytes(result.stdout)
    (out / 'stderr.bin').write_bytes(result.stderr)
    case = {'label': label, 'argv': command, 'exit': expected, 'passed': True}
    save(out / 'case.json', case)
    records.append(case)
    save(root / 'records.json', records)
    print(json.dumps({'label': label, 'passed': True}), flush=True)
    return result


def interrupt_latency_seconds(capture_dir):
    """Seconds between the last sent input action (e.g. Ctrl-C) and process exit, using
    capture.py's own monotonic timestamps in input-events.jsonl and summary.json — both
    relative to the same `started` reference, so their difference needs no wall-clock of ours."""
    events = [json.loads(line) for line in (capture_dir / 'input-events.jsonl').read_text().splitlines() if line]
    summary = json.loads((capture_dir / 'summary.json').read_text())
    assert events, (capture_dir, 'no input events recorded')
    return summary['duration_seconds'] - events[-1]['elapsed']


def pipe_interrupt_case(label, argv, *, marker, expected=130, marker_timeout=30):
    """An interrupt with no PTY at all, for pipe mode and for JSON mode alike, that waits for the
    silent step's own marker in stdout before sending SIGINT — never a fixed delay. In pipe/plain
    mode, ProgressDisplay's own tree redraw is gated on mayRepaint (decoration), but
    Animation::start()'s undecorated fallback is a separate, independent write: it prints
    "Running <step>...\\n" in plain text the instant the step's operation begins, before any
    application output — confirmed live, well ahead of a deliberately slow step's own completion.
    In JSON mode the step's own phase event (its `"step_name":"<name>"` literal, from the CLI's
    own compact json_encode of that event) arrives at the same moment, over the same kind of bare
    pipe. record()'s capture.py always allocates a PTY, whose line discipline echoes the sent
    Ctrl-C keystroke into stdout and corrupts NDJSON parsing (confirmed live); production --json
    is never run under a real terminal anyway, and the CLI's own signal test uses a bare pipe for
    this exact mode too — this helper avoids a PTY entirely, in both modes."""
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv)]
    is_json = '--json' in argv
    process = subprocess.Popen(
        command, cwd=source, env=env, stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    os.set_blocking(process.stdout.fileno(), False)
    marker_bytes = marker.encode()
    buffer = b''
    deadline = time.monotonic() + marker_timeout
    while marker_bytes not in buffer:
        exit_code = process.poll()
        if exit_code is not None:
            os.set_blocking(process.stdout.fileno(), True)
            rest = process.stdout.read() or b''
            raise AssertionError((label, 'process exited before the marker appeared', marker, buffer + rest, exit_code))
        if time.monotonic() > deadline:
            process.kill()
            process.wait(timeout=10)
            raise AssertionError((label, 'marker never appeared within the timeout', marker, buffer))
        ready, _, _ = select.select([process.stdout], [], [], 0.05)
        if ready:
            chunk = process.stdout.read()
            if chunk:
                buffer += chunk
    signalled_at = time.monotonic()
    process.send_signal(signal.SIGINT)
    os.set_blocking(process.stdout.fileno(), True)
    rest_stdout, stderr = process.communicate(timeout=30)
    stdout = buffer + rest_stdout
    latency = time.monotonic() - signalled_at
    # A signal-terminated child reports a negative Popen.returncode (Python's own convention,
    # -signum); convert to the shell's 128+signum convention the rest of this file compares
    # against (see the --child wrapper's identical conversion above).
    code = process.returncode
    if code < 0:
        code = 128 - code
    assert code == expected, (label, code, process.returncode, stdout, stderr)
    assert b'\x1b' not in stdout, (label, 'stdout must carry no ANSI')
    assert stderr == b'', (label, 'stderr must stay empty', stderr)
    (out / 'stdout.bin').write_bytes(stdout)
    (out / 'stderr.bin').write_bytes(stderr)
    case = {
        'label': label, 'argv': command, 'candidate': args.candidate, 'environment': 'disposable-incus',
        'exit': expected, 'plain': not is_json, 'json': is_json, 'passed': True, 'interrupt_latency_seconds': latency,
    }
    save(out / 'case.json', case)
    records.append(case)
    save(root / 'records.json', records)
    print(json.dumps({'label': label, 'passed': True, 'interrupt_latency_seconds': latency}), flush=True)
    return latency, stdout


def deploy_activity_records(instance_id, command='instance:deploy'):
    """Gateway activity rows (id and error_code) for this instance and command, newest first.
    200 is the API's own maximum, for comfortable margin against unrelated activity elsewhere on
    the topology."""
    response = read('activity:list', '--limit=200')
    return sorted(
        (
            {'id': activity['id'], 'error_code': activity.get('error_code')}
            for activity in response.get('activities', [])
            if activity.get('command') == command and activity.get('subject_id') == instance_id
        ),
        key=lambda record: record['id'],
    )


def deploy_activity_ids(instance_id, command='instance:deploy'):
    """IDs of recorded Gateway activity rows for this instance and command, newest first, to
    prove an interrupted request never triggers an automatic second one. Comparing IDs (which
    only ever increase) rather than a plain count sidesteps windowing entirely: with a fixed
    --limit, enough unrelated activity between two checks (every read()/record() call this
    stage itself makes is its own row of a different command) can push an older matching row out
    of the window, making a plain count understate or even decrease — confirmed live."""
    return [record['id'] for record in deploy_activity_records(instance_id, command)]


def deploy_activity_new_ids(instance_id, before_ids, *, timeout_seconds=35, poll_seconds=1.0):
    """Waits for a new activity row (an ID not in before_ids) to appear after an interrupted
    request, and returns the newly appeared IDs. F1 only makes the CLIENT disconnect promptly;
    the Gateway's own operation keeps running server-side and only stops the *next* protected
    boundary from starting (docs/reference/deployments.md) — for a silent before_activation step
    this means it runs its full duration (confirmed live: activity duration_ms up to ~17.5s for
    an 8s sleep step, failed_step "activation", not the step itself), and the activity row is
    only queryable once that finishes, not when the client exits. Each deploy_activity_ids()
    call is also a real Gateway round trip (~2s observed live via sendWithProgress), not free
    polling overhead, so the budget absorbs both."""
    deadline = time.monotonic() + timeout_seconds
    before_set = set(before_ids)
    new_ids = [i for i in deploy_activity_ids(instance_id) if i not in before_set]
    while not new_ids and time.monotonic() < deadline:
        time.sleep(poll_seconds)
        new_ids = [i for i in deploy_activity_ids(instance_id) if i not in before_set]
    return new_ids


def remote(node, command):
    host = node['wireguard_ip']
    argv = ['ssh', '-i', str(Path.home() / '.orbit/ssh/id_ed25519'),
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known_hosts),
            '-o', 'ConnectTimeout=10', node['user'] + '@' + host, command]
    result = subprocess.run(argv, capture_output=True, text=True, timeout=30)
    assert result.returncode == 0, (command, result.stdout, result.stderr)
    return result.stdout.strip()


def node_with_role(role):
    nodes = read('node:list')
    return next(n for n in nodes['nodes'] if role in n.get('roles', []))


def app_prod_node():
    return node_with_role('app-prod')


if args.stage == 'doctor-baseline':
    # This shared topology's `instance` family is pre-existing unverifiable/drift on the sample
    # e2e-dev/e2e-prod instances, unrelated to this issue — filed as ORB-380. The gateway Node has
    # no App instances and is genuinely all-clear in every family, so it is the honest target for
    # the "all clear" success-path recordings.
    baseline = read('doctor', expected=1)
    save(root / 'baseline.json', baseline)
    by_resource = {}
    for node in baseline['nodes']:
        for family in node['families']:
            for issue in family['issues']:
                by_resource.setdefault((node['node_name'], issue['resource_name']), []).append(issue['code'])
    assert by_resource == {
        ('app-dev', 'e2e-dev'): ['instance.source_identity_mismatch', 'instance.inspection_failed'],
        ('app-prod', 'e2e-prod'): ['instance.inspection_failed'],
    }, ('pre-existing baseline finding changed shape, see ORB-380', by_resource)
    save(root / 'orb-380-pre-existing-finding.json', {
        'linear_issue': 'ORB-380',
        'note': 'Filed for this exact pre-existing finding; unrelated to ORB-361 rendering changes.',
        'findings': [
            {'node': node_name, 'resource': resource_name, 'codes': codes}
            for (node_name, resource_name), codes in by_resource.items()
        ],
    })
    gateway = node_with_role('gateway')
    payload = read('doctor', '--node', str(gateway['id']))
    assert payload['healthy'] is True, payload
    record(
        'all-clear-human', ['doctor', '--node', str(gateway['id'])],
        ['Verified registered state', 'Healthy: yes', 'Request ID:'], columns=200,
        state_rows=DOCTOR_STATE_ROWS, animation_rows=DOCTOR_ANIMATION_ROWS,
        raw_contains=[ANSI_GREEN], raw_absent=[ANSI_ORANGE],
    )
    record(
        'all-clear-plain', ['doctor', '--node', str(gateway['id'])],
        ['Healthy: yes', 'Request ID:'], columns=200, plain=True,
    )
    record('all-clear-json', ['doctor', '--node', str(gateway['id']), '--json'], [], json_payload=payload)
    pipe_case('all-clear-pipe', ['doctor', '--node', str(gateway['id'])], ['Healthy: yes', 'Request ID:'])
    record(
        'node-filter-human', ['doctor', '--node', str(gateway['id'])],
        ['NODE', 'FAMILY', 'STATUS', 'Healthy: yes'], columns=200,
    )
    record(
        'family-filter-human', ['doctor', '--family', 'role'],
        ['NODE', 'FAMILY', 'STATUS', 'CHECKED', 'FINDING'], columns=200,
    )
    unknown = subprocess.run(
        ['php', str(launcher), 'doctor', '--family', 'not-a-real-family', '--json'],
        cwd=source, env=env, input=b'', capture_output=True, timeout=60,
    )
    assert unknown.returncode != 0, unknown
    unknown_payload = json.loads(unknown.stdout)
    assert unknown_payload.get('error', {}).get('code') == 'validation.failed', unknown_payload
    save(root / 'unknown-family.json', {'exit': unknown.returncode, 'stderr': unknown.stderr.decode(), 'payload': unknown_payload})

elif args.stage == 'doctor-drift-prepare':
    # A node-level process (not tied to an App instance) so this fixture stays clear of the
    # `instance` family's pre-existing ORB-380 finding, which masks any further drift raised
    # against the same instance. Created from the gateway because only the gateway holds Orbit
    # API credentials; app-dev can only be reached to flip the resulting systemd unit externally.
    app_dev = node_with_role('app-dev')
    process = read(
        'process:create', FIXTURE_PROCESS_NAME, '--node', str(app_dev['id']),
        '--runtime=systemd', '--command=/bin/sleep', '--command=3600', '--start',
    )
    save(root / 'process.json', process)
    payload = read('doctor', '--node', str(app_dev['id']), '--family', 'process')
    assert payload['healthy'] is True, payload
    save(root / 'process-baseline.json', payload)

elif args.stage == 'doctor-drift-report':
    app_dev = node_with_role('app-dev')
    payload = read('doctor', '--node', str(app_dev['id']), '--family', 'process', expected=1)
    findings = [issue for node in payload['nodes'] for family in node['families'] for issue in family['issues']]
    codes = [issue['code'] for issue in findings]
    assert 'process.state_mismatch' in codes, findings
    assert [f['resource_name'] for f in findings] == [FIXTURE_PROCESS_NAME], findings
    save(root / 'drift.json', payload)
    record(
        'drift-human', ['doctor', '--node', str(app_dev['id']), '--family', 'process'],
        ['drift', 'process.state_mismatch', 'Healthy: no'], expected=1, columns=200,
        state_rows=DOCTOR_STATE_ROWS, animation_rows=DOCTOR_ANIMATION_ROWS,
        raw_contains=[ANSI_ORANGE], raw_absent=[ANSI_GREEN],
    )
    record(
        'drift-json', ['doctor', '--node', str(app_dev['id']), '--family', 'process', '--json'], [],
        expected=1, json_payload=payload,
    )

elif args.stage == 'doctor-unverifiable-report':
    node = app_prod_node()
    payload = read('doctor', '--node', str(node['id']), '--family', 'role', expected=1)
    findings = [issue for n in payload['nodes'] for family in n['families'] for issue in family['issues']]
    assert len(findings) == 1 and findings[0]['code'] == 'role.inspection_failed', findings
    save(root / 'unverifiable.json', payload)
    record(
        'unverifiable-human', ['doctor', '--node', str(node['id']), '--family', 'role'],
        ['unverifiable', 'role.inspection_failed', 'Healthy: no'], expected=1, columns=200,
        state_rows=DOCTOR_STATE_ROWS, animation_rows=DOCTOR_ANIMATION_ROWS,
        raw_contains=[ANSI_ORANGE], raw_absent=[ANSI_GREEN],
    )
    record(
        'unverifiable-json', ['doctor', '--node', str(node['id']), '--family', 'role', '--json'], [],
        expected=1, json_payload=payload,
    )

elif args.stage == 'doctor-clean-recheck':
    # Confirm the fixture-specific findings are gone, not that the whole topology is healthy —
    # the pre-existing e2e-dev/e2e-prod instance.* findings recorded in doctor-baseline are
    # unrelated to this issue (ORB-380) and stay exactly as they were.
    app_dev = node_with_role('app-dev')
    process_after = read('doctor', '--node', str(app_dev['id']), '--family', 'process')
    assert process_after['healthy'] is True, process_after
    node_prod = app_prod_node()
    role_after = read('doctor', '--node', str(node_prod['id']), '--family', 'role')
    assert role_after['healthy'] is True, role_after
    process = load_state('doctor-drift-prepare', 'process.json')
    destroyed = read('process:destroy', str(process['id']), '--yes')
    save(root / 'clean-recheck.json', {
        'process_after': process_after, 'role_after': role_after, 'process_destroyed': destroyed,
    })

elif args.stage == 'deploy-prepare':
    app = read('app:create', FIXTURE_APP_SLUG, FIXTURE_APP_REPO, '--name=ORB-361 deploy fixture', '--root=' + FIXTURE_APP_ROOT)
    save(root / 'app.json', app)
    nodes = read('node:list')
    app_dev = next(n for n in nodes['nodes'] if 'app-dev' in n.get('roles', []))
    prod_node = next(n for n in nodes['nodes'] if 'app-prod' in n.get('roles', []))
    dev = read('instance:create', app['id'], app_dev['id'], FIXTURE_DEV_NAME, '--branch=' + FIXTURE_BRANCH)
    save(root / 'dev-instance.json', dev)
    prod = read('instance:clone', dev['id'], prod_node['id'], FIXTURE_PROD_NAME, '--preview-name=' + FIXTURE_PREVIEW_NAME)
    prod['id'] = prod['target_id']  # instance:clone's JSON names the new AppInstance id target_id
    save(root / 'prod-instance.json', prod)
    # A production AppInstance with zero stored environment rows refuses at the deploy
    # environment-sync boundary (env.configuration_missing); a fresh clone starts with none.
    env_result = read('env:update', '--instance=' + str(prod['id']), '--key=ORB361_FIXTURE', '--value=1')
    save(root / 'env.json', env_result)
    step = read(
        'instance:deploy-step:create', prod['id'], 'liveness-check',
        '--command=for i in 1 2 3 4 5; do echo tick-$i; sleep 1; done', '--phase=before_activation',
    )
    save(root / 'liveness-step.json', step)
    save(root / 'prepared.json', {'app': app, 'dev': dev, 'prod': prod, 'prod_node': prod_node})

elif args.stage == 'deploy-success':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    record(
        'deploy-decorated', ['instance:deploy', str(prod_id)],
        ['Deploy AppInstance', 'Resolved release', 'Synced environment', 'Ran liveness-check',
         'tick-1', 'tick-5', 'Activated release', 'Deployment succeeded.', 'Request ID:'],
        columns=200, max_first_output_seconds=5, idle_timeout=30,
        # animation_rows restored: F4 (Animation::printLine(), opt-in for deploy/rollback step
        # output) replaced the per-line clear-and-restart cycle that used to blank the tree
        # around each printed liveness-check tick-N line (the ORB-363 gap state_rows previously
        # had to tolerate here; see orb361-proof-rehearsal-report.md for that prior state).
        animation_rows=[{
            'name': 'liveness-check', 'pattern': r'(?P<glyph>[○◉])\s+Running liveness-check',
            'terminal_pattern': r'●\s+Ran liveness-check',
            'minimum_changes': 1, 'min_interval': 0.1, 'max_interval': 3.0,
        }],
    )
    release_a = read('instance:release:list', prod_id)
    save(root / 'release-a.json', release_a)
    record(
        'deploy-json', ['instance:deploy', str(prod_id), '--json'], [],
        json_mode='ndjson', max_first_output_seconds=5, idle_timeout=30,
    )
    release_b = read('instance:release:list', prod_id)
    save(root / 'release-b.json', release_b)
    pipe_case(
        'deploy-pipe', ['instance:deploy', str(prod_id)],
        ['Deploy AppInstance', 'Resolved release', 'Ran liveness-check', 'Activated release', 'Deployment succeeded.'],
    )
    release_c = read('instance:release:list', prod_id)
    save(root / 'release-c.json', release_c)
    assert release_c['selected_release'] not in (release_a['selected_release'],), release_c
    record(
        'deploy-plain', ['instance:deploy', str(prod_id)],
        ['Deploy AppInstance', 'Resolved release', 'Ran liveness-check', 'Activated release', 'Deployment succeeded.'],
        columns=200, plain=True, max_first_output_seconds=5, idle_timeout=30,
    )
    release_d = read('instance:release:list', prod_id)
    save(root / 'release-d.json', release_d)
    assert release_d['selected_release'] not in (release_a['selected_release'], release_c['selected_release']), release_d

elif args.stage == 'deploy-chatty':
    # Chatty output (F11): heavier than deploy-success's 5 liveness-check ticks, to prove F4
    # holds under real load, not just a handful of lines. animation_rows requires every frame
    # during the step's animation window to match either the active or the terminal pattern; a
    # clear-and-redraw regression would show frames matching neither. 120 lines at 50ms (R3):
    # heavier than the original 40, and a max_interval near 0.5s (the normal tick cadence is
    # ~0.3s live) is a meaningful bound instead of the earlier 3.0s, which would not have caught
    # a real cadence disruption.
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    read(
        'instance:deploy-step:create', prod_id, 'chatty-step',
        '--command=for i in $(seq 1 120); do echo line-$i; sleep 0.05; done',
        '--phase=before_activation', '--after=liveness-check',
    )
    try:
        record(
            # 'Running chatty-step' is not in this list: contains doubles as final_contains (the
            # settled last frame), and the row shows its completed label ('Ran chatty-step') by
            # then. animation_rows below still proves the running state independently.
            'deploy-chatty-decorated', ['instance:deploy', str(prod_id)],
            ['line-1', 'line-120', 'Ran chatty-step', 'Deployment succeeded.'],
            columns=200, max_first_output_seconds=5, idle_timeout=30, timeout=120,
            animation_rows=[{
                'name': 'chatty-step', 'pattern': r'(?P<glyph>[○◉])\s+Running chatty-step',
                'terminal_pattern': r'●\s+Ran chatty-step',
                'minimum_changes': 1, 'min_interval': 0.02, 'max_interval': 0.5,
            }],
        )
    finally:
        read('instance:deploy-step:destroy', prod_id, 'chatty-step', '--yes')

elif args.stage == 'deploy-interrupt':
    # docs/reference/deployments.md:138-142: the Gateway detects the disconnect lazily (on its
    # next write), stops the *next* boundary from starting, sends no success result, and does not
    # roll code back automatically. A release the interrupted attempt already created may or may
    # not be left behind as retained-but-unselected; only `current` (selected_release) is a
    # promise. Record the observed releases list rather than asserting it stays identical.
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    before = read('instance:release:list', prod_id)
    record(
        'deploy-interrupt-decorated', ['instance:deploy', str(prod_id)],
        ['tick-1'], expected=130, columns=200, max_first_output_seconds=5, idle_timeout=30,
        absent=['Deployment succeeded.', 'Activated release'],
        input_actions=[{'wait_for': 'tick-1', 'send': '\x03'}],
    )
    after = read('instance:release:list', prod_id)
    assert after['selected_release'] == before['selected_release'], (before, after)
    save(root / 'interrupt-unchanged-state.json', {
        'before': before, 'after': after,
        'releases_grew_by': len(after['releases']) - len(before['releases']),
    })

elif args.stage == 'deploy-silent-interrupt-decorated':
    # Ctrl-C during a silent step (F1's headline finding, F11): a step that prints nothing at
    # all, interrupted in all 3 modes, under 1s and with the state changed by exactly the one
    # request (no automatic retry). This is what makes F1 a real regression to catch: earlier
    # interrupt coverage (deploy-interrupt) waits for output text first, which hid the original
    # bug (the blocking SDK read held the SIGINT handler until the next byte arrived). One mode
    # per stage: each mode's own activity-settle poll can run close to 30s (F1 only makes the
    # CLIENT disconnect fast; the Gateway's own operation runs to its next protected boundary
    # regardless — confirmed live, activity duration_ms 17533 for this 8s-sleep step), and all
    # 3 modes combined in one stage exceeded the harness's exec time limit during rehearsal.
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    read(
        'instance:deploy-step:create', prod_id, 'silent-step',
        '--command=sleep 8', '--phase=before_activation', '--after=liveness-check',
    )
    try:
        before_decorated = read('instance:release:list', prod_id)
        activity_before_decorated = deploy_activity_ids(prod_id)
        capture_dir = record(
            'deploy-silent-interrupt-decorated', ['instance:deploy', str(prod_id)],
            ['Running silent-step'], expected=130, columns=200, max_first_output_seconds=10, idle_timeout=15,
            absent=['Deployment succeeded.', 'Activated release', 'Deployment stream failed.'],
            input_actions=[{'wait_for': 'Running silent-step', 'send': '\x03'}],
        )
        latency_decorated = interrupt_latency_seconds(capture_dir / 'capture')
        assert latency_decorated < 1.0, ('deploy-silent-interrupt-decorated', latency_decorated)
        after_decorated = read('instance:release:list', prod_id)
        assert after_decorated['selected_release'] == before_decorated['selected_release'], (before_decorated, after_decorated)
        new_ids_decorated = deploy_activity_new_ids(prod_id, activity_before_decorated)
        assert len(new_ids_decorated) == 1, \
            ('deploy-silent-interrupt-decorated', activity_before_decorated, new_ids_decorated)
        new_records_decorated = [r for r in deploy_activity_records(prod_id) if r['id'] in new_ids_decorated]
        assert new_records_decorated[0]['error_code'] == 'deployment.cancelled', \
            ('deploy-silent-interrupt-decorated', new_records_decorated)
    finally:
        read('instance:deploy-step:destroy', prod_id, 'silent-step', '--yes')

    save(root / 'silent-interrupt-decorated-latency.json', {
        'decorated_seconds': latency_decorated, 'decorated_new_activity_ids': new_ids_decorated,
    })

elif args.stage == 'deploy-silent-interrupt-pipe':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    read(
        'instance:deploy-step:create', prod_id, 'silent-step',
        '--command=sleep 8', '--phase=before_activation', '--after=liveness-check',
    )
    try:
        before_pipe = read('instance:release:list', prod_id)
        activity_before_pipe = deploy_activity_ids(prod_id)
        # Waits for "Running silent-step...\n" itself (Animation::start()'s plain-mode fallback
        # write, independent of ProgressDisplay's repaint-gated tree — confirmed live to appear
        # well before a deliberately slow step finishes) instead of a fixed delay past liveness-
        # check's own 5 one-second ticks.
        latency_pipe, stdout_pipe = pipe_interrupt_case(
            'deploy-silent-interrupt-pipe', ['instance:deploy', str(prod_id)],
            marker='Running silent-step',
        )
        assert latency_pipe < 1.0, ('deploy-silent-interrupt-pipe', latency_pipe)
        # No later output: the interrupt landed inside the silent step, before it (or activation)
        # could ever complete.
        for needle in (b'Deployment succeeded.', b'Activated release', b'Deployment stream failed.'):
            assert needle not in stdout_pipe, ('deploy-silent-interrupt-pipe', needle, stdout_pipe)
        after_pipe = read('instance:release:list', prod_id)
        assert after_pipe['selected_release'] == before_pipe['selected_release'], (before_pipe, after_pipe)
        new_ids_pipe = deploy_activity_new_ids(prod_id, activity_before_pipe)
        assert len(new_ids_pipe) == 1, ('deploy-silent-interrupt-pipe', activity_before_pipe, new_ids_pipe)
        new_records_pipe = [r for r in deploy_activity_records(prod_id) if r['id'] in new_ids_pipe]
        assert new_records_pipe[0]['error_code'] == 'deployment.cancelled', \
            ('deploy-silent-interrupt-pipe', new_records_pipe)
    finally:
        read('instance:deploy-step:destroy', prod_id, 'silent-step', '--yes')

    save(root / 'silent-interrupt-pipe-latency.json', {
        'pipe_seconds': latency_pipe, 'pipe_new_activity_ids': new_ids_pipe,
    })

elif args.stage == 'deploy-silent-interrupt-json':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    read(
        'instance:deploy-step:create', prod_id, 'silent-step',
        '--command=sleep 8', '--phase=before_activation', '--after=liveness-check',
    )
    try:
        before_json = read('instance:release:list', prod_id)
        activity_before_json = deploy_activity_ids(prod_id)
        # Plain subprocess, no PTY: record()'s capture.py always allocates one, and its line
        # discipline echoes the Ctrl-C keystroke into the captured bytes, corrupting NDJSON
        # parsing — confirmed live. Production --json is never run interactively in a real
        # terminal anyway; the CLI's own signal test uses a bare pipe for this exact mode too.
        # Waits for the silent step's own phase event (its `"step_name":"silent-step"` literal,
        # the CLI's own compact json_encode of that event) instead of a fixed delay.
        latency_json, stdout = pipe_interrupt_case(
            'deploy-silent-interrupt-json', ['instance:deploy', str(prod_id), '--json'],
            marker='"step_name":"silent-step"',
        )
        assert latency_json < 1.0, ('deploy-silent-interrupt-json', latency_json)
        lines = [json.loads(line) for line in stdout.decode().splitlines() if line]
        assert lines, ('deploy-silent-interrupt-json', 'no NDJSON lines captured')
        for line in lines:
            assert line.get('type') in ('phase', 'output'), \
                ('deploy-silent-interrupt-json', 'no result line expected on interrupt', line)
            assert_request_id(line.get('request_id'), label='deploy-silent-interrupt-json')
        # No later output: the last event captured is the silent step's own phase event — no
        # output event follows it (silent-step prints nothing) and the interrupt landed inside
        # it, not during a later step.
        assert lines[-1].get('type') == 'phase' and lines[-1].get('step_name') == 'silent-step', \
            ('deploy-silent-interrupt-json', 'interrupt did not land on the silent step', lines[-1])
        after_json = read('instance:release:list', prod_id)
        assert after_json['selected_release'] == before_json['selected_release'], (before_json, after_json)
        new_ids_json = deploy_activity_new_ids(prod_id, activity_before_json)
        assert len(new_ids_json) == 1, ('deploy-silent-interrupt-json', activity_before_json, new_ids_json)
        new_records_json = [r for r in deploy_activity_records(prod_id) if r['id'] in new_ids_json]
        assert new_records_json[0]['error_code'] == 'deployment.cancelled', \
            ('deploy-silent-interrupt-json', new_records_json)
    finally:
        read('instance:deploy-step:destroy', prod_id, 'silent-step', '--yes')

    save(root / 'silent-interrupt-json-latency.json', {
        'json_seconds': latency_json, 'json_new_activity_ids': new_ids_json,
    })

elif args.stage == 'deploy-failure':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    before = read('instance:release:list', prod_id)
    read('instance:deploy-step:create', prod_id, 'failing-step', '--command=exit 1', '--phase=before_activation', '--after=liveness-check')
    record(
        'deploy-failure-decorated', ['instance:deploy', str(prod_id)],
        ['Resolved release', 'Synced environment', 'Ran liveness-check', 'Running failing-step',
         'Activate release', 'Not reached.', 'Deployment failed.', 'Failed boundary: before_activation'],
        expected=1, columns=200, max_first_output_seconds=5, idle_timeout=30,
        # Failure-row state and color (F11): the failed row's glyph and error-code message are
        # red, the unreached row's glyph and "Not reached." message are orange/dim, and the
        # footer is red — not merely present as text, but carrying the states' real colors.
        raw_contains=[
            ANSI_RED + '●'.encode() + ANSI_RESET,
            ANSI_RED + b'deployment.step_failed' + ANSI_RESET,
            ANSI_ORANGE + '●'.encode() + ANSI_RESET,
            ANSI_DIM + b'Not reached.' + ANSI_RESET,
            ANSI_RED + b'Deployment failed.' + ANSI_RESET,
        ],
    )
    record(
        'deploy-failure-json', ['instance:deploy', str(prod_id), '--json'], [],
        expected=1, json_mode='ndjson', error_code='deployment.step_failed',
        max_first_output_seconds=5, idle_timeout=30,
    )
    after = read('instance:release:list', prod_id)
    assert after['selected_release'] == before['selected_release'], (before, after)
    read('instance:deploy-step:destroy', prod_id, 'failing-step', '--yes')
    save(root / 'failure-unchanged-state.json', {'before': before, 'after': after})

    # Narrow width (F11): the same failure, decorated, at 40 columns — wrapping must not lose
    # the failed-row error code, the "Not reached." rows, or the red footer.
    read('instance:deploy-step:create', prod_id, 'failing-step', '--command=exit 1', '--phase=before_activation', '--after=liveness-check')
    record(
        'deploy-failure-narrow', ['instance:deploy', str(prod_id)],
        ['Deployment failed.', 'deployment.step_failed', 'Not reached.'],
        expected=1, columns=40, max_first_output_seconds=5, idle_timeout=30,
    )
    read('instance:deploy-step:destroy', prod_id, 'failing-step', '--yes')

elif args.stage == 'deploy-after-activation-failure':
    # after_activation failure (F11): a step after activation fails; activation itself must
    # stay green (the fresh release is already selected) and the failed step's row takes the
    # blame, not activation's. Its own stage: combined with deploy-failure's other cases this
    # exceeded the harness's exec time limit during rehearsal.
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    after_before = read('instance:release:list', prod_id)
    read('instance:deploy-step:create', prod_id, 'after-fail', '--command=exit 1', '--phase=after_activation')
    record(
        'deploy-after-activation-failure-decorated', ['instance:deploy', str(prod_id)],
        ['Activated release', 'Running after-fail', 'Deployment failed.', 'Failed boundary: after_activation'],
        expected=1, columns=200, max_first_output_seconds=5, idle_timeout=30,
    )
    after_activation_after = read('instance:release:list', prod_id)
    assert after_activation_after['selected_release'] not in (after_before['selected_release'],), (after_before, after_activation_after)
    read('instance:deploy-step:destroy', prod_id, 'after-fail', '--yes')
    save(root / 'after-activation-failure-state.json', {'before': after_before, 'after': after_activation_after})

elif args.stage == 'deploy-operation-failure':
    # operation failure (F11): the dev instance can never be deployed (deployment_config.unavailable);
    # the Gateway's failed_step is a bare "operation" boundary with no row of its own — the tree's
    # opening row (never even started) must take the blame instead of every row looking merely
    # skipped. Its own stage for the same exec-time reason as deploy-after-activation-failure.
    prepared = load_state('deploy-prepare', 'prepared.json')
    dev_id = prepared['dev']['id']
    record(
        'deploy-operation-failure-decorated', ['instance:deploy', str(dev_id)],
        ['Resolving release', 'deployment_config.unavailable', 'Deployment failed.', 'Failed boundary: operation'],
        expected=1, columns=200, max_first_output_seconds=5, idle_timeout=30,
    )
    record(
        'deploy-operation-failure-json', ['instance:deploy', str(dev_id), '--json'], [],
        expected=1, json_mode='ndjson', error_code='deployment_config.unavailable',
        max_first_output_seconds=5, idle_timeout=30,
    )

elif args.stage == 'rollback-explicit':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    release_a = load_state('deploy-success', 'release-a.json')
    target = release_a['selected_release']
    record(
        'rollback-decorated', ['instance:rollback', str(prod_id), '--release=' + target],
        ['Roll back AppInstance', 'Selected release', 'Rollback succeeded.', 'Selected release: ' + target, 'Request ID:'],
        columns=200, max_first_output_seconds=5, idle_timeout=30,
    )
    after = read('instance:release:list', prod_id)
    assert after['selected_release'] == target, after
    save(root / 'rollback-result.json', after)

elif args.stage == 'rollback-json':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    release_b = load_state('deploy-success', 'release-b.json')
    target = release_b['selected_release']
    record(
        'rollback-json', ['instance:rollback', str(prod_id), '--release=' + target, '--json'], [],
        json_mode='ndjson', max_first_output_seconds=5, idle_timeout=30,
    )
    after = read('instance:release:list', prod_id)
    assert after['selected_release'] == target, after

elif args.stage == 'rollback-pipe':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    release_c = load_state('deploy-success', 'release-c.json')
    target = release_c['selected_release']
    pipe_case(
        'rollback-pipe', ['instance:rollback', str(prod_id), '--release=' + target],
        ['Roll back AppInstance', 'Selected release', 'Rollback succeeded.', 'Selected release: ' + target],
    )
    after = read('instance:release:list', prod_id)
    assert after['selected_release'] == target, after

elif args.stage == 'rollback-plain':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    release_d = load_state('deploy-success', 'release-d.json')
    target = release_d['selected_release']
    record(
        'rollback-plain', ['instance:rollback', str(prod_id), '--release=' + target],
        ['Roll back AppInstance', 'Selected release', 'Rollback succeeded.', 'Selected release: ' + target],
        columns=200, plain=True, max_first_output_seconds=5, idle_timeout=30,
    )
    after = read('instance:release:list', prod_id)
    assert after['selected_release'] == target, after
    save(root / 'rollback-plain-result.json', after)

elif args.stage == 'rollback-activation-failure':
    # A live reproduction of F2a: rollback's only admitted row is 'rollback' ('Select release');
    # activation has no row of its own, so an activation failure must still mark the reached row
    # failed, not leave it looking merely skipped. `chattr +i` on the app home makes the
    # activation step's re-point of `current` fail; always removed, even on failure.
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    prod_node = prepared['prod_node']
    show = read('instance:show', prod_id)
    home = show.get('production_home') or show.get('checkout_path')
    assert home, show
    release_before = read('instance:release:list', prod_id)
    target = release_before['selected_release']
    remote(prod_node, 'sudo chattr +i ' + shlex.quote(home))
    try:
        record(
            'rollback-activation-failure-decorated', ['instance:rollback', str(prod_id), '--release=' + target],
            ['Selecting release', 'Rollback failed.', 'Failed boundary: activation'],
            expected=1, columns=200, max_first_output_seconds=5, idle_timeout=30,
            raw_contains=[ANSI_RED + '●'.encode() + ANSI_RESET, ANSI_RED + b'Rollback failed.' + ANSI_RESET],
        )
    finally:
        remote(prod_node, 'sudo chattr -i ' + shlex.quote(home))
    release_after = read('instance:release:list', prod_id)
    assert release_after['selected_release'] == release_before['selected_release'], (release_before, release_after)
    save(root / 'rollback-activation-failure-state.json', {'before': release_before, 'after': release_after})

elif args.stage == 'rollback-no-release':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    before = read('instance:release:list', prod_id)
    record(
        'rollback-no-release-human', ['instance:rollback', str(prod_id)],
        ['A retained release name is required.'], expected=1, columns=200,
    )
    record(
        'rollback-no-release-json', ['instance:rollback', str(prod_id), '--json'],
        [], expected=1, error_code='deployment.release_required', actual_gateway=False,
    )
    after = read('instance:release:list', prod_id)
    # request_id is fresh per call even for a rejected no-op; compare only the retained state.
    assert (after['releases'], after['selected_release']) == (before['releases'], before['selected_release']), (before, after)

elif args.stage == 'rollback-invalid':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    before = read('instance:release:list', prod_id)
    record(
        'rollback-invalid-json', ['instance:rollback', str(prod_id), '--release=not-a-real-release', '--json'],
        [], expected=1, json_mode='ndjson', error_code='rollback.release_invalid',
    )
    after = read('instance:release:list', prod_id)
    assert (after['releases'], after['selected_release']) == (before['releases'], before['selected_release']), (before, after)

elif args.stage == 'rollback-missing-instance':
    record(
        'rollback-missing-human', ['instance:rollback', '999999', '--release=anything'],
        ['Resource not found.'], expected=1, columns=200,
    )
    record(
        'rollback-missing-json', ['instance:rollback', '999999', '--release=anything', '--json'],
        [], expected=1, error_code='http.404',
    )

elif args.stage == 'deploy-missing-instance':
    record('deploy-missing-human', ['instance:deploy', '999999'], ['Resource not found.'], expected=1, columns=200)
    record('deploy-missing-json', ['instance:deploy', '999999', '--json'], [], expected=1, error_code='http.404')

elif args.stage == 'json-parity':
    # F11: the machine path's contract must not have drifted from `main`, not just from itself.
    # A separate `main` checkout, built once. Cloned from GitHub directly (nckrtl/orbit is
    # public), not from the candidate's own mounted worktree: guests never have usable git
    # metadata for a mounted worktree checkout (confirmed live — "not a git repository", and
    # WorktreeSynchronizer.php's own comment: "guests cannot read a worktree .git pointer file"
    # — a structural property of how source is mounted here, not a rehearsal-only quirk).
    # Byte comparison after normalize_parity_bytes() (R3): request IDs and release names
    # legitimately differ between two separate runs; everything else, including field order and
    # field names, must match exactly — not just JSON shape (keys/types), which would miss a
    # changed value, error code, base64 payload, or failure result.
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    main_root = Path('/home/orbit/orbit-main')

    if not main_root.exists():
        subprocess.run(
            ['git', 'clone', '--quiet', '--branch', 'main', '--depth', '1',
             'https://github.com/nckrtl/orbit.git', str(main_root)],
            check=True, timeout=120,
        )
        main_sha = subprocess.check_output(
            ['git', '-C', str(main_root), 'rev-parse', 'HEAD'], text=True,
        ).strip()
        subprocess.run(
            ['composer', 'install', '--no-interaction', '--quiet'],
            cwd=main_root / 'apps/cli', check=True, timeout=300,
        )
        save(root / 'main-checkout.json', {'main_sha': main_sha})

    main_launcher = main_root / 'apps/cli/orbit'

    def run_json(binary, argv, expected=0):
        command = ['php', str(binary), *map(str, argv), '--json']
        result = subprocess.run(command, cwd=source, env=env, capture_output=True, input=b'', timeout=240)
        assert result.returncode == expected, (binary, argv, result.returncode, result.stdout, result.stderr)
        assert result.stderr == b'', (binary, argv, result.stderr)
        return result.stdout

    def assert_parity(case_name, candidate_raw, main_raw):
        candidate_norm = normalize_parity_bytes(candidate_raw)
        main_norm = normalize_parity_bytes(main_raw)
        assert candidate_norm == main_norm, (case_name, candidate_norm, main_norm)
        parity[case_name] = candidate_norm.decode()

    parity = {}

    candidate_deploy_missing = run_json(launcher, ['instance:deploy', '999999'], expected=1)
    main_deploy_missing = run_json(main_launcher, ['instance:deploy', '999999'], expected=1)
    assert_parity('deploy-missing-instance', candidate_deploy_missing, main_deploy_missing)

    candidate_rollback_missing = run_json(launcher, ['instance:rollback', '999999', '--release=anything'], expected=1)
    main_rollback_missing = run_json(main_launcher, ['instance:rollback', '999999', '--release=anything'], expected=1)
    assert_parity('rollback-missing-instance', candidate_rollback_missing, main_rollback_missing)

    # expected=1: this fixture topology is unhealthy by design (the pre-existing ORB-380
    # instance.inspection_failed condition doctor-baseline already establishes and asserts).
    # Doctor's JSON has no request_id or timestamp field at all (DoctorReportData: healthy,
    # nodes, summary only — confirmed by reading DoctorCommand::handle() and DoctorReportData),
    # so this is already a same-instant, same-topology fleet snapshot: a plain byte comparison,
    # no normalization needed, though normalize_parity_bytes() is harmless to apply regardless.
    candidate_doctor = run_json(launcher, ['doctor'], expected=1)
    main_doctor = run_json(main_launcher, ['doctor'], expected=1)
    assert_parity('doctor', candidate_doctor, main_doctor)

    # The same fixture instance, deployed through each binary in turn: the event-type sequence,
    # phase order, and every field and value (once request IDs and release names are normalized
    # away) must match exactly.
    candidate_deploy = run_json(launcher, ['instance:deploy', str(prod_id)])
    main_deploy = run_json(main_launcher, ['instance:deploy', str(prod_id)])
    assert_parity('deploy', candidate_deploy, main_deploy)

    # Rollback success: each binary rolls the same instance back to a different retained release
    # (not the one currently selected) — the specific release name is normalized away, so this
    # still compares the same rollback event shape and every other value byte-for-byte.
    release_list = json.loads(run_json(launcher, ['instance:release:list', str(prod_id)]))
    rollback_targets = [name for name in release_list['releases'] if name != release_list['selected_release']]
    assert len(rollback_targets) >= 2, ('json-parity', 'not enough retained releases for a rollback comparison', release_list)
    candidate_rollback = run_json(launcher, ['instance:rollback', str(prod_id), f'--release={rollback_targets[0]}'])
    main_rollback = run_json(main_launcher, ['instance:rollback', str(prod_id), f'--release={rollback_targets[1]}'])
    assert_parity('rollback', candidate_rollback, main_rollback)

    # after_activation failure: activation itself succeeds and current advances, but a step after
    # it fails the overall deploy — the same shape as deploy-after-activation-failure's live case,
    # run through both binaries.
    read('instance:deploy-step:create', prod_id, 'json-parity-after-fail', '--command=exit 1', '--phase=after_activation')
    try:
        candidate_after_fail = run_json(launcher, ['instance:deploy', str(prod_id)], expected=1)
        main_after_fail = run_json(main_launcher, ['instance:deploy', str(prod_id)], expected=1)
        assert_parity('after-activation-failure', candidate_after_fail, main_after_fail)
    finally:
        read('instance:deploy-step:destroy', prod_id, 'json-parity-after-fail', '--yes')

    save(root / 'json-parity.json', parity)

elif args.stage == 'resulting-state':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    prod_node = prepared['prod_node']
    # A fresh read, not rollback-plain's saved snapshot (F11): json-parity's own comparison
    # deploy runs between rollback-plain and this stage and moves `current` again, so the
    # snapshot no longer reflects the real, latest selection by the time this stage runs.
    current_release = read('instance:release:list', prod_id)
    show = read('instance:show', prod_id)
    home = show.get('production_home') or show.get('checkout_path')
    assert home, show
    # The app-specific home directory is locked to its own Unix user plus caddy (execute-only);
    # the generic orbit SSH user cannot traverse it without sudo.
    current_target = remote(prod_node, 'sudo readlink -f ' + shlex.quote(home + '/current'))
    assert current_target.rstrip('/').endswith(current_release['selected_release']), (current_target, current_release)
    url = show.get('url')
    assert url, show
    # .orbit.test is not really resolvable (RFC 2606) and Guests have no local /etc/hosts entry
    # for it either; --resolve pins the SNI/Host to this node without needing real DNS. The
    # platform's own root CA is already in the system trust store, so no --cacert is needed.
    host = url.split('://', 1)[1].split('/', 1)[0]
    http_status = remote(prod_node, 'curl -s -o /dev/null -w "%{http_code}" --resolve '
                          + shlex.quote(host + ':443:127.0.0.1') + ' ' + shlex.quote(url + FIXTURE_HEALTH_PATH))
    assert http_status.strip() == '200', http_status
    save(root / 'resulting-state.json', {'current_target': current_target, 'http_status': http_status, 'url': url})

elif args.stage == 'coverage-report':
    def load_json(rel):
        path = args.state / rel
        return json.loads(path.read_text()) if path.exists() else None

    def assert_case(stage, label):
        rows = load_json(stage + '/records.json')
        assert rows is not None, (stage, 'missing records.json')
        case = next((row for row in rows if row.get('label') == label), None)
        assert case is not None, (stage, label, 'case not recorded', [r.get('label') for r in rows])
        assert case.get('passed') is True, (stage, label, 'case did not pass', case)
        verify = load_json(stage + '/' + label + '/verify.json')
        if verify is not None:
            assert verify.get('passed') is True and not verify.get('failures'), (stage, label, verify)
        return case

    # Every command x mode cell this issue's contract admits. A missing cell fails the aggregate,
    # not just a missing label within one stage (orb360-fixture-review R5).
    COMMAND_MODE_CASES = {
        'doctor': {
            'human-decorated (all-clear)': ('doctor-baseline', 'all-clear-human'),
            'human-plain (all-clear)': ('doctor-baseline', 'all-clear-plain'),
            'json (all-clear)': ('doctor-baseline', 'all-clear-json'),
            'pipe (all-clear)': ('doctor-baseline', 'all-clear-pipe'),
            'human-decorated (--node)': ('doctor-baseline', 'node-filter-human'),
            'human-decorated (--family)': ('doctor-baseline', 'family-filter-human'),
            'human-decorated (drift finding)': ('doctor-drift-report', 'drift-human'),
            'json (drift finding)': ('doctor-drift-report', 'drift-json'),
            'human-decorated (unverifiable finding)': ('doctor-unverifiable-report', 'unverifiable-human'),
            'json (unverifiable finding)': ('doctor-unverifiable-report', 'unverifiable-json'),
        },
        'instance:deploy': {
            'human-decorated': ('deploy-success', 'deploy-decorated'),
            'human-plain': ('deploy-success', 'deploy-plain'),
            'json': ('deploy-success', 'deploy-json'),
            'pipe': ('deploy-success', 'deploy-pipe'),
            'human-decorated (failed step)': ('deploy-failure', 'deploy-failure-decorated'),
            'json (failed step)': ('deploy-failure', 'deploy-failure-json'),
            'human-decorated (failed step, narrow)': ('deploy-failure', 'deploy-failure-narrow'),
            'human-decorated (after_activation failure)': ('deploy-after-activation-failure', 'deploy-after-activation-failure-decorated'),
            'human-decorated (operation failure)': ('deploy-operation-failure', 'deploy-operation-failure-decorated'),
            'json (operation failure)': ('deploy-operation-failure', 'deploy-operation-failure-json'),
            'human-decorated (chatty output)': ('deploy-chatty', 'deploy-chatty-decorated'),
            'human-decorated (Ctrl-C)': ('deploy-interrupt', 'deploy-interrupt-decorated'),
            'human-decorated (silent Ctrl-C)': ('deploy-silent-interrupt-decorated', 'deploy-silent-interrupt-decorated'),
            'pipe (silent Ctrl-C)': ('deploy-silent-interrupt-pipe', 'deploy-silent-interrupt-pipe'),
            'json (silent Ctrl-C)': ('deploy-silent-interrupt-json', 'deploy-silent-interrupt-json'),
            'human-decorated (missing instance)': ('deploy-missing-instance', 'deploy-missing-human'),
            'json (missing instance)': ('deploy-missing-instance', 'deploy-missing-json'),
        },
        'instance:rollback': {
            'human-decorated': ('rollback-explicit', 'rollback-decorated'),
            'human-plain': ('rollback-plain', 'rollback-plain'),
            'json': ('rollback-json', 'rollback-json'),
            'pipe': ('rollback-pipe', 'rollback-pipe'),
            'json (invalid release)': ('rollback-invalid', 'rollback-invalid-json'),
            'human-decorated (activation failure)': ('rollback-activation-failure', 'rollback-activation-failure-decorated'),
            'human-decorated (no --release)': ('rollback-no-release', 'rollback-no-release-human'),
            'json (no --release)': ('rollback-no-release', 'rollback-no-release-json'),
            'human-decorated (missing instance)': ('rollback-missing-instance', 'rollback-missing-human'),
            'json (missing instance)': ('rollback-missing-instance', 'rollback-missing-json'),
        },
    }
    per_command = {}
    missing_cells = []
    for command, modes in COMMAND_MODE_CASES.items():
        per_command[command] = {}
        for mode, (stage, label) in modes.items():
            try:
                per_command[command][mode] = assert_case(stage, label)
            except AssertionError as failure:
                missing_cells.append({'command': command, 'mode': mode, 'reason': str(failure)})
    assert missing_cells == [], ('coverage aggregate has missing or failed cells', missing_cells)

    assert (args.state / 'doctor-clean-recheck/clean-recheck.json').exists(), 'missing doctor clean recheck'
    assert (args.state / 'resulting-state/resulting-state.json').exists(), 'missing resulting-state evidence'
    assert (args.state / 'doctor-baseline/orb-380-pre-existing-finding.json').exists(), 'missing ORB-380 pre-existing finding evidence'
    assert (args.state / 'deploy-failure/failure-unchanged-state.json').exists(), 'missing deploy-failure unchanged-state evidence'
    assert (args.state / 'deploy-after-activation-failure/after-activation-failure-state.json').exists(), \
        'missing after_activation failure evidence'
    assert (args.state / 'deploy-interrupt/interrupt-unchanged-state.json').exists(), 'missing deploy-interrupt unchanged-state evidence'
    assert (args.state / 'rollback-activation-failure/rollback-activation-failure-state.json').exists(), \
        'missing rollback activation-failure evidence'
    assert (args.state / 'json-parity/json-parity.json').exists(), 'missing JSON parity-with-main evidence'
    unknown_family = load_json('doctor-baseline/unknown-family.json')
    assert unknown_family and unknown_family['exit'] != 0, unknown_family

    silent_interrupt_decorated = load_json('deploy-silent-interrupt-decorated/silent-interrupt-decorated-latency.json')
    silent_interrupt_pipe = load_json('deploy-silent-interrupt-pipe/silent-interrupt-pipe-latency.json')
    silent_interrupt_json = load_json('deploy-silent-interrupt-json/silent-interrupt-json-latency.json')
    assert silent_interrupt_decorated is not None, 'missing silent-interrupt decorated latency evidence'
    assert silent_interrupt_pipe is not None, 'missing silent-interrupt pipe latency evidence'
    assert silent_interrupt_json is not None, 'missing silent-interrupt json latency evidence'
    assert silent_interrupt_decorated['decorated_seconds'] < 1.0, silent_interrupt_decorated
    assert silent_interrupt_pipe['pipe_seconds'] < 1.0, silent_interrupt_pipe
    assert silent_interrupt_json['json_seconds'] < 1.0, silent_interrupt_json

    total = sum(len(modes) for modes in COMMAND_MODE_CASES.values())
    aggregate = {
        'total': total, 'passed': total, 'complete': True,
        'cells': {command: sorted(modes.keys()) for command, modes in COMMAND_MODE_CASES.items()},
    }
    save(root / 'coverage.json', aggregate)
    print(json.dumps({'coverage': str(total) + '/' + str(total) + ' complete'}), flush=True)
