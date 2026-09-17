#!/usr/bin/env python3
"""Record real doctor and deployment-stream operations and verify resulting guest state."""
import argparse
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import termios
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
    'deploy-prepare', 'deploy-success', 'deploy-interrupt', 'deploy-failure',
    'rollback-explicit', 'rollback-json', 'rollback-pipe', 'rollback-plain', 'rollback-no-release',
    'rollback-invalid', 'rollback-missing-instance', 'deploy-missing-instance',
    'resulting-state', 'coverage-report',
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


def assert_request_id(value, *, label, actual_gateway=True):
    if actual_gateway:
        assert isinstance(value, str) and REQUEST_ID_UUID.fullmatch(value), (label, 'expected a Gateway request_id', value)
    else:
        assert value is None, (label, 'a local validation failure never reaches the Gateway', value)


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
        # No animation_rows here: the liveness-check command's own tick-N stdout is interleaved
        # live and scrolls the progress display, so some reconstructed frames show neither the
        # active nor the terminal row (verified against real frames: 10 such gaps, one pair per
        # tick). animation_rows requires every post-animation frame to match one or the other, so
        # it misreads a benign scroll as "disappeared without terminal state". state_rows tolerates
        # frames where the row is briefly absent and still proves the Run -> Running -> Ran
        # transition happened.
        state_rows=[{
            'name': 'liveness-check', 'pattern': r'[○◉●]\s+(?P<state>Running|Ran|Run) liveness-check',
            'states': ['Run', 'Running', 'Ran'], 'transitions': [['Run', 'Running'], ['Running', 'Ran']],
            'required': ['Ran'],
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

elif args.stage == 'resulting-state':
    prepared = load_state('deploy-prepare', 'prepared.json')
    prod_id = prepared['prod']['id']
    prod_node = prepared['prod_node']
    rollback_result = load_state('rollback-plain', 'rollback-plain-result.json')
    show = read('instance:show', prod_id)
    home = show.get('production_home') or show.get('checkout_path')
    assert home, show
    # The app-specific home directory is locked to its own Unix user plus caddy (execute-only);
    # the generic orbit SSH user cannot traverse it without sudo.
    current_target = remote(prod_node, 'sudo readlink -f ' + shlex.quote(home + '/current'))
    assert current_target.rstrip('/').endswith(rollback_result['selected_release']), (current_target, rollback_result)
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
            'human-decorated (Ctrl-C)': ('deploy-interrupt', 'deploy-interrupt-decorated'),
            'human-decorated (missing instance)': ('deploy-missing-instance', 'deploy-missing-human'),
            'json (missing instance)': ('deploy-missing-instance', 'deploy-missing-json'),
        },
        'instance:rollback': {
            'human-decorated': ('rollback-explicit', 'rollback-decorated'),
            'human-plain': ('rollback-plain', 'rollback-plain'),
            'json': ('rollback-json', 'rollback-json'),
            'pipe': ('rollback-pipe', 'rollback-pipe'),
            'json (invalid release)': ('rollback-invalid', 'rollback-invalid-json'),
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
    assert (args.state / 'deploy-interrupt/interrupt-unchanged-state.json').exists(), 'missing deploy-interrupt unchanged-state evidence'
    unknown_family = load_json('doctor-baseline/unknown-family.json')
    assert unknown_family and unknown_family['exit'] != 0, unknown_family

    total = sum(len(modes) for modes in COMMAND_MODE_CASES.values())
    aggregate = {
        'total': total, 'passed': total, 'complete': True,
        'cells': {command: sorted(modes.keys()) for command, modes in COMMAND_MODE_CASES.items()},
    }
    save(root / 'coverage.json', aggregate)
    print(json.dumps({'coverage': str(total) + '/' + str(total) + ' complete'}), flush=True)
