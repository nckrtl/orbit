#!/usr/bin/env python3
"""Record real tool and metrics CLI operations and verify their resulting guest state."""
import argparse
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import signal
import subprocess
import sys
import termios
import time

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    child = subprocess.Popen(sys.argv[3:])

    def forward(signum, _frame):
        try:
            child.send_signal(signum)
        except ProcessLookupError:
            pass

    signal.signal(signal.SIGINT, forward)
    signal.signal(signal.SIGTERM, forward)
    code = child.wait()
    after = termios.tcgetattr(0)
    Path(sys.argv[2]).write_text(json.dumps(
        {'before': repr(before), 'after': repr(after), 'equal': before == after, 'exit': code},
    ))
    sys.exit(code)

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument(
    '--stage',
    choices=[
        'tools', 'tools-consent', 'metrics-status-creds', 'metrics-exporters',
        'metrics-lifecycle', 'coverage-report',
    ],
    required=True,
)
parser.add_argument('--visible', action='store_true')
args = parser.parse_args()

os.umask(0o077)
source = Path('/home/orbit/orbit')
assert subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() == args.candidate
assert not subprocess.check_output(
    ['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'], text=True,
).strip()

root = args.state / args.stage
root.mkdir(parents=True, exist_ok=True)
private = root / 'gateway-home'
private.mkdir(exist_ok=True)
shutil.copyfile(Path.home() / '.orbit/config.json', private / 'config.json')
known_hosts = root / 'known_hosts'
if (Path.home() / '.orbit/ssh/known_hosts').exists():
    shutil.copyfile(Path.home() / '.orbit/ssh/known_hosts', known_hosts)
env = dict(os.environ, ORBIT_HOME=str(private), TERM='xterm-256color', LC_ALL='C.UTF-8')
for key in ('NO_COLOR', 'FORCE_COLOR', 'CLICOLOR', 'COLUMNS', 'LINES'):
    env.pop(key, None)
launcher = source / 'apps/cli/orbit'
recorder = source / '.agents/skills/verifying-cli-output/scripts'
SELF = Path(__file__).resolve()

records = []
if (root / 'records.json').exists():
    records = json.loads((root / 'records.json').read_text())


def save(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')


REQUEST_ID_UUID = re.compile(
    r'\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z',
    re.I,
)


def command_name(argv):
    return next((part for part in argv if ':' in part and not part.startswith('-')), None)


def assert_request_id(value, *, label):
    assert isinstance(value, str) and REQUEST_ID_UUID.fullmatch(value), (label, 'request_id', value)


def read(*argv, expected=0, error=None):
    """Internal, non-proof data fetch (node resolution etc.). Not a case."""
    command = ['php', str(launcher), *map(str, argv), '--json']
    result = subprocess.run(command, cwd=source, env=env, capture_output=True, input=b'', timeout=120)
    assert result.returncode == expected, (argv, result.returncode, result.stdout, result.stderr)
    assert result.stderr == b'' and b'\x1b' not in result.stdout, (argv, result.stderr)
    payload = json.loads(result.stdout)
    if error is not None:
        assert payload['error']['code'] == error, (argv, payload)
    return payload


def remote(node, command):
    argv = [
        'ssh', '-i', str(Path.home() / '.orbit/ssh/id_ed25519'),
        '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
        '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known_hosts),
        '-o', 'ConnectTimeout=10', node['user'] + '@' + node['wireguard_ip'], command,
    ]
    result = subprocess.run(argv, capture_output=True, text=True, timeout=60)
    assert result.returncode == 0, (node['name'], command, result.returncode, result.stdout, result.stderr)
    return result.stdout


def record(label, argv, contains, *, expected=0, key=None, prompt=None, columns=100,
           plain=False, animation=None, input_actions=None, max_after_input=None,
           final_contains=None, absent=None, max_first_output_seconds=None):
    """One human-mode (decorated or undecorated) real-PTY proof case."""
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--no-ansi' if plain else '--ansi']
    capture = [
        sys.executable, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
        '--candidate', args.candidate, '--label', label, '--columns', str(columns), '--rows', '50',
        '--timeout', '120', '--idle-timeout', '45',
    ]
    if not args.visible:
        capture.append('--no-live')
    input_plan = input_actions
    if input_plan is None and key is not None:
        input_plan = [{'wait_for': prompt, 'send': 'y' if key == 'n' else key}]
        if key == 'n':
            input_plan += [{'wait_for': 'Yes', 'send': 'n'}, {'wait_for': 'No', 'send': '\r'}]
        elif key == 'y':
            input_plan.append({'wait_for': 'Yes', 'send': '\r'})
    if input_plan is not None:
        save(out / 'inputs.json', input_plan)
        capture += ['--input-plan', str(out / 'inputs.json')]
    capture += ['--', sys.executable, str(SELF), '--child', str(out / 'terminal.json'), *command]
    print('$ ' + shlex.join(command), flush=True)
    result = subprocess.run(capture, cwd=source, env=env, capture_output=True, timeout=150)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal'], (label, 'termios not restored')
    if max_after_input is not None:
        events = [json.loads(line) for line in (out / 'capture/input-events.jsonl').read_text().splitlines()]
        sent = next(event for event in reversed(events) if event['bytes'] > 0)
        summary = json.loads((out / 'capture/summary.json').read_text())
        assert summary['duration_seconds'] - sent['elapsed'] <= max_after_input, (label, 'slow response to input')
    expectation = {'candidate': args.candidate, 'label': label, 'exit_code': expected}
    if contains:
        expectation['contains'] = contains
    if final_contains:
        expectation['final_contains'] = final_contains
    if absent:
        expectation['absent'] = absent
    if animation:
        expectation['animation_rows'] = [animation]
    if max_first_output_seconds is not None:
        expectation['max_first_output_seconds'] = max_first_output_seconds
    save(out / 'expectation.json', expectation)
    verify_result = subprocess.run(
        [sys.executable, str(recorder / 'verify.py'), '--capture', str(out / 'capture'),
         '--expect', str(out / 'expectation.json')],
        capture_output=True, text=True, timeout=30,
    )
    verify_payload = json.loads(verify_result.stdout)
    save(out / 'verify.json', verify_payload)
    assert verify_payload['passed'], (label, verify_payload['failures'])
    records.append({
        'label': label, 'command': command_name(argv), 'mode': 'plain' if plain else 'human',
        'argv': command, 'exit': expected, 'passed': True,
    })
    save(root / 'records.json', records)
    return out


def machine(label, argv, *, json_mode=True, expected=0, contains=None, absent=None,
            error_code=None, json_payload=None):
    """One JSON-mode or piped-plain-mode proof case, checking stdout/stderr separately."""
    command = ['php', str(launcher), *map(str, argv)]
    if json_mode:
        command.append('--json')
    result = subprocess.run(command, cwd=source, env=env, input=b'', capture_output=True, timeout=90)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert b'\x1b' not in result.stdout, (label, 'ANSI in stdout', result.stdout)
    if json_mode:
        assert result.stderr == b'', (label, 'stderr not empty for JSON mode', result.stderr)
        payload = json.loads(result.stdout)
        if error_code is not None:
            assert payload['error']['code'] == error_code, (label, payload)
            assert set(payload['error']) >= {'code', 'message', 'request_id'}, (label, payload)
            if payload['error']['request_id'] is not None:
                assert_request_id(payload['error']['request_id'], label=label)
        else:
            assert 'error' not in payload, (label, payload)
            assert_request_id(payload['request_id'], label=label)
        if json_payload is not None:
            stripped_actual = {k: v for k, v in payload.items() if k != 'request_id'}
            stripped_expected = {k: v for k, v in json_payload.items() if k != 'request_id'}
            assert stripped_actual == stripped_expected, (label, payload, json_payload)
        records.append({'label': label, 'command': command_name(argv), 'mode': 'json', 'exit': expected, 'passed': True})
        save(root / 'records.json', records)
        return payload
    text = result.stdout.decode()
    assert result.stderr == b'', (label, 'stderr not empty for pipe mode', result.stderr)
    for literal in (contains or []):
        assert literal in text, (label, 'missing', literal, text)
    for literal in (absent or []):
        assert literal not in text, (label, 'unexpected', literal, text)
    records.append({'label': label, 'command': command_name(argv), 'mode': 'pipe', 'exit': expected, 'passed': True})
    save(root / 'records.json', records)
    return result


def find_node(nodes, name):
    return next(node for node in nodes if node['name'] == name)


def dpkg_status(node, package):
    output = remote(node, "dpkg-query -W -f='${Status}' " + shlex.quote(package) + " 2>/dev/null || echo 'not-found'")
    return output.strip()


def stage_tools():
    nodes = read('node:list')['nodes']
    app_prod = find_node(nodes, 'app-prod')
    node_id = app_prod['id']

    # -- tool:manager:list --------------------------------------------------
    record(
        'manager-list-human', ['tool:manager:list', '--node', str(node_id)],
        ['MANAGER', 'STATUS', 'apt', 'active'],
    )
    record(
        'manager-list-undecorated', ['tool:manager:list', '--node', str(node_id)],
        ['MANAGER', 'apt'], plain=True,
    )
    machine(
        'manager-list-json', ['tool:manager:list', '--node', str(node_id)],
        json_mode=True,
    )
    machine(
        'manager-list-pipe', ['tool:manager:list', '--node', str(node_id)], json_mode=False,
        contains=['apt'], absent=['\x1b'],
    )
    machine(
        'manager-list-invalid-node', ['tool:manager:list', '--node', '0'], json_mode=True,
        expected=1, error_code='tool.node_id_invalid',
    )

    # -- tool:install (apt, real work, deliberately slow via progress) ------
    assert dpkg_status(app_prod, 'tree') != 'install ok installed'
    record(
        'install-apt-human', ['tool:install', 'tree', '--node', str(node_id), '--manager', 'apt'],
        ['Install Tool', 'Installed Tool', 'tree', 'apt', 'Status', 'installed'],
        max_first_output_seconds=2,
        animation={
            'name': 'tool-install-apt',
            'pattern': r'(?P<glyph>[○◉]) Installing Tool',
            'terminal_pattern': r'● Installed Tool',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 3.0,
        },
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'
    tools_after_install = read('tool:list', '--node', str(node_id))['tools']
    tree_tool = next(t for t in tools_after_install if t['package'] == 'tree')
    tool_id = tree_tool['id']

    machine(
        'install-apt-unchanged-json', ['tool:install', 'tree', '--node', str(node_id), '--manager', 'apt'],
        json_mode=True,
    )

    machine(
        'install-invalid-package-json',
        ['tool:install', 'bad\tname', '--node', str(node_id), '--manager', 'apt'],
        json_mode=True, expected=1, error_code='tool.package_invalid',
    )

    # -- tool:install (composer, provisioned on first use) -------------------
    managers_before = read('tool:manager:list', '--node', str(node_id))['managers']
    composer_before = next(m for m in managers_before if m['name'] == 'composer')
    assert composer_before['status'] == 'uninstalled', composer_before
    record(
        'install-composer-human', ['tool:install', 'psr/log', '--node', str(node_id), '--manager', 'composer'],
        ['Install Tool', 'Installed Tool', 'psr/log', 'composer'],
        max_first_output_seconds=2,
    )
    managers_after = read('tool:manager:list', '--node', str(node_id))['managers']
    composer_after = next(m for m in managers_after if m['name'] == 'composer')
    assert composer_after['status'] == 'active', composer_after
    composer_tools = read('tool:list', '--node', str(node_id))['tools']
    composer_tool = next(t for t in composer_tools if t['package'] == 'psr/log')

    # -- tool:list / tool:show -----------------------------------------------
    record(
        'list-tools-human', ['tool:list', '--node', str(node_id)],
        ['PACKAGE', 'tree', 'psr/log', 'apt', 'composer'],
    )
    machine('list-tools-json', ['tool:list', '--node', str(node_id)], json_mode=True)
    machine(
        'list-tools-pipe', ['tool:list', '--node', str(node_id)], json_mode=False,
        contains=['tree', 'psr/log'], absent=['\x1b'],
    )
    record(
        'show-tool-human', ['tool:show', str(tool_id)],
        ['ID', 'Node ID', 'Manager', 'Package', 'tree', 'apt', str(tool_id)],
    )
    record(
        'show-tool-undecorated', ['tool:show', str(tool_id)],
        ['Package', 'tree'], plain=True,
    )
    record(
        'show-tool-narrow', ['tool:show', str(tool_id)],
        ['Package', 'tree'], columns=40,
    )

    # -- tool:update -----------------------------------------------------------
    record(
        'update-tool-unchanged-human', ['tool:update', str(composer_tool['id'])],
        ['Update Tool', 'Updated Tool', 'psr/log', 'already current'],
        animation={
            'name': 'tool-update-composer',
            'pattern': r'(?P<glyph>[○◉]) Updating Tool',
            'terminal_pattern': r'● Updated Tool',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 3.0,
        },
    )
    record(
        'update-tool-undecorated', ['tool:update', str(tool_id)],
        ['Updated Tool', 'tree'], plain=True,
    )
    machine(
        'update-tool-pipe', ['tool:update', str(tool_id)], json_mode=False,
        contains=['tree'], absent=['\x1b'],
    )
    machine(
        'update-tool-unchanged-json', ['tool:update', str(composer_tool['id'])],
        json_mode=True,
    )

    # -- tool:install (brew, informational: ORB-360 needs to know if brew
    # works on these guests). Not a required coverage cell: brew provisions a
    # full homebrew-core tap on first use and its environment support is what
    # this case is meant to discover, not assume.
    brew_report = {'attempted': True, 'package': 'jq'}
    try:
        record(
            'install-brew-human', ['tool:install', 'jq', '--node', str(node_id), '--manager', 'brew'],
            ['Install Tool', 'Installed Tool', 'jq', 'brew'],
            max_first_output_seconds=2,
            animation={
                'name': 'tool-install-brew',
                'pattern': r'(?P<glyph>[○◉]) Installing Tool',
                'terminal_pattern': r'● Installed Tool',
                'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 5.0,
            },
        )
        brew_report['supported'] = True
    except AssertionError as error:
        brew_report['supported'] = False
        brew_report['error'] = repr(error)
    save(root / 'brew-support.json', brew_report)

    save(root / 'ids.json', {'app_prod_id': node_id, 'tree_tool_id': tool_id})


def stage_tools_consent():
    nodes = read('node:list')['nodes']
    app_prod = find_node(nodes, 'app-prod')
    node_id = app_prod['id']

    def install_disposable():
        payload = read(
            'tool:install', 'tree', '--node', str(node_id), '--manager', 'apt',
        )
        return payload['id']

    # -- unknown Tool fails with the Gateway's own not-found code, before any
    # prompt (no confirmation code, no RemoveToolRequest reaches the mutation)
    show_missing = subprocess.run(
        ['php', str(launcher), 'tool:remove', '999999', '--json'],
        cwd=source, env=env, input=b'', capture_output=True, timeout=60,
    )
    assert show_missing.returncode == 1, show_missing.stdout
    missing_payload = json.loads(show_missing.stdout)
    missing_code = missing_payload['error']['code']
    assert missing_code not in ('input.confirmation_required', 'input.cancelled'), missing_payload
    assert 'tool' in missing_code, ('expected a Tool-domain error code', missing_payload)
    records.append({'label': 'remove-not-found', 'command': 'tool:remove', 'mode': 'json', 'exit': 1, 'passed': True})
    save(root / 'records.json', records)

    # -- JSON automation without --yes: resolves, then refuses, no mutation --
    tool_id = install_disposable()
    machine(
        'remove-json-no-yes', ['tool:remove', str(tool_id)], json_mode=True, expected=1,
        error_code='input.confirmation_required',
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'
    machine('remove-cleanup-1', ['tool:remove', str(tool_id), '--yes'], json_mode=True)
    assert dpkg_status(app_prod, 'tree') != 'install ok installed'

    # -- interactive default-No: unchanged -----------------------------------
    tool_id = install_disposable()
    record(
        'remove-default-no', ['tool:remove', str(tool_id)], ['cancelled'],
        expected=1, key='\r', prompt='Yes',
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- interactive explicit No -----------------------------------------------
    record(
        'remove-explicit-no', ['tool:remove', str(tool_id)], ['cancelled'],
        expected=1, key='n', prompt='Yes',
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- Ctrl-C before mutation -------------------------------------------------
    # A raw 0x03 byte read by a prompt in raw mode is not a kernel SIGINT; the
    # prompt library itself recognizes Key::CTRL_C and confirmAction() catches
    # it the same way as a decline, returning exit 1 (CommandPromptsTest.php's
    # 'requires affirmative consent...' case: Ctrl-C -> status 1, not 130).
    record(
        'remove-ctrl-c', ['tool:remove', str(tool_id)], ['cancelled'],
        expected=1, input_actions=[{'wait_for': 'Yes', 'send': '\x03'}],
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- EOF before mutation ----------------------------------------------------
    record(
        'remove-eof', ['tool:remove', str(tool_id)], ['cancelled'],
        expected=1, input_actions=[{'wait_for': 'Yes', 'send': '\x04'}],
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- narrow-width consent prompt still names the target and effect -------
    record(
        'remove-narrow-decline', ['tool:remove', str(tool_id)], ['cancelled'],
        expected=1, key='\r', prompt='Yes', columns=40,
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- interactive accept: real mutation -------------------------------------
    record(
        'remove-accept-interactive', ['tool:remove', str(tool_id)], ['removed'],
        key='y', prompt='Yes',
        animation={
            'name': 'tool-remove',
            'pattern': r'(?P<glyph>[○◉]) Removing Tool',
            'terminal_pattern': r'● Removed Tool',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 3.0,
        },
    )
    assert dpkg_status(app_prod, 'tree') != 'install ok installed'

    # -- human --yes success, and JSON --yes success --------------------------
    tool_id = install_disposable()
    record(
        'remove-yes-human', ['tool:remove', str(tool_id), '--yes'], ['removed', 'tree'],
    )
    assert dpkg_status(app_prod, 'tree') != 'install ok installed'

    tool_id = install_disposable()
    machine('remove-yes-json', ['tool:remove', str(tool_id), '--yes'], json_mode=True)
    assert dpkg_status(app_prod, 'tree') != 'install ok installed'


def stage_metrics_status_creds():
    nodes = read('node:list')['nodes']
    app_dev = find_node(nodes, 'app-dev')

    record(
        'status-initial-human', ['metrics:status'],
        ['Enabled', 'yes', 'app-dev', 'Prometheus', 'Grafana'],
    )
    status_payload = machine('status-initial-json', ['metrics:status'], json_mode=True)
    assert status_payload['enabled'] is True
    assert status_payload['assignment']['node_name'] == 'app-dev'
    record('status-undecorated', ['metrics:status'], ['Enabled', 'app-dev'], plain=True)
    machine(
        'status-pipe', ['metrics:status'], json_mode=False,
        contains=['Enabled', 'app-dev'], absent=['\x1b'],
    )

    record(
        'credentials-show-human', ['metrics:credentials'],
        ['URL', 'Username', 'admin', 'Password'],
    )
    record(
        'credentials-show-undecorated', ['metrics:credentials'],
        ['URL', 'Username', 'admin'], plain=True,
    )
    creds = machine('credentials-show-json', ['metrics:credentials'], json_mode=True)
    original_password = creds['password']
    machine(
        'credentials-show-pipe', ['metrics:credentials'], json_mode=False,
        contains=['URL', 'Username', 'admin', original_password], absent=['\x1b'],
    )

    grafana = subprocess.run(
        ['curl', '-sk', '-u', 'admin:' + original_password, '-o', '/dev/null', '-w', '%{http_code}',
         'https://' + app_dev['wireguard_ip'] + ':3000/api/org'],
        capture_output=True, text=True, timeout=15,
    )
    assert grafana.stdout.strip() == '200', ('grafana auth (original password)', grafana.stdout, grafana.stderr)

    # -- reset: multi-step (generate, apply, verify, promote); real slow work
    record(
        'credentials-reset-human', ['metrics:credentials', '--reset'],
        ['URL', 'Username', 'Password', 'admin'],
        max_first_output_seconds=2,
        animation={
            'name': 'credentials-reset',
            'pattern': r'(?P<glyph>[○◉]) Resetting Metrics credentials',
            'terminal_pattern': r'● Reset Metrics credentials',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 2.0,
        },
    )
    reset_payload = machine('credentials-reset-json-reread', ['metrics:credentials'], json_mode=True)
    new_password = reset_payload['password']
    assert new_password != original_password, 'reset did not change the password'

    grafana_new = subprocess.run(
        ['curl', '-sk', '-u', 'admin:' + new_password, '-o', '/dev/null', '-w', '%{http_code}',
         'https://' + app_dev['wireguard_ip'] + ':3000/api/org'],
        capture_output=True, text=True, timeout=15,
    )
    assert grafana_new.stdout.strip() == '200', ('grafana auth (new password)', grafana_new.stdout)
    grafana_old = subprocess.run(
        ['curl', '-sk', '-u', 'admin:' + original_password, '-o', '/dev/null', '-w', '%{http_code}',
         'https://' + app_dev['wireguard_ip'] + ':3000/api/org'],
        capture_output=True, text=True, timeout=15,
    )
    assert grafana_old.stdout.strip() in ('401', '403'), ('old password should no longer authenticate', grafana_old.stdout)


def stage_metrics_exporters():
    nodes = read('node:list')['nodes']
    app_prod = find_node(nodes, 'app-prod')
    node_id = app_prod['id']

    def exporter_active():
        return remote(
            app_prod,
            "systemctl is-active prometheus-node-exporter 2>&1 || true",
        ).strip()

    assert exporter_active() != 'active', 'app-prod must start with no exporter for this case'

    def wait_for(expected_active):
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline and (exporter_active() == 'active') != expected_active:
            time.sleep(2)
        assert (exporter_active() == 'active') == expected_active, (
            'exporter did not converge', node_id, expected_active,
        )

    record(
        'exporter-enable-human', ['metrics:exporter:enable', str(node_id)],
        ['Enable Metrics exporter', 'Enabled Metrics exporter', 'Status', 'enabled'],
    )
    wait_for(True)

    status_payload = machine('exporter-status-reflects-json', ['metrics:status'], json_mode=True)
    row = next(r for r in status_payload['exporters'] if r['name'] == 'app-prod')
    assert row['desired'] is True and row['reason'] == 'explicit_enabled', row

    record(
        'exporter-disable-undecorated', ['metrics:exporter:disable', str(node_id)],
        ['Disabled Metrics exporter', 'disabled'], plain=True,
    )
    wait_for(False)

    machine(
        'exporter-enable-pipe', ['metrics:exporter:enable', str(node_id)], json_mode=False,
        contains=['enabled'], absent=['\x1b'],
    )
    wait_for(True)

    record(
        'exporter-disable-human', ['metrics:exporter:disable', str(node_id)],
        ['Disable Metrics exporter', 'Disabled Metrics exporter', 'Status', 'disabled'],
    )
    wait_for(False)

    machine(
        'exporter-invalid-node-json', ['metrics:exporter:enable', '999999'], json_mode=True,
        expected=1,
    )


def stage_metrics_lifecycle():
    nodes = read('node:list')['nodes']
    app_dev = find_node(nodes, 'app-dev')
    gateway = find_node(nodes, 'gateway')
    app_prod = find_node(nodes, 'app-prod')

    def containers_running(node):
        return remote(
            node,
            "docker ps --format '{{.Names}}' 2>/dev/null | grep -c '^orbit-metrics-' || true",
        ).strip()

    assert containers_running(app_dev) == '2', 'app-dev should start with Prometheus and Grafana running'

    # -- automation without --force keeps the stable code, makes no mutation
    machine(
        'disable-json-no-force', ['metrics:disable'], json_mode=True, expected=1,
        error_code='metrics.force_required',
    )
    machine(
        'disable-purge-without-force', ['metrics:disable', '--purge-data'], json_mode=True,
        expected=1, error_code='metrics.force_required',
    )
    assert containers_running(app_dev) == '2'

    # -- interactive decline / cancel: unchanged -------------------------------
    record(
        'disable-default-no', ['metrics:disable'], ['cancelled'],
        expected=1, key='\r', prompt='Disable Metrics?',
    )
    assert containers_running(app_dev) == '2'
    record(
        'disable-ctrl-c', ['metrics:disable'], ['cancelled'],
        expected=1, input_actions=[{'wait_for': 'Disable Metrics?', 'send': '\x03'}],
    )
    assert containers_running(app_dev) == '2'
    record(
        'disable-eof', ['metrics:disable'], ['cancelled'],
        expected=1, input_actions=[{'wait_for': 'Disable Metrics?', 'send': '\x04'}],
    )
    assert containers_running(app_dev) == '2'

    # -- interactive accept: real disable, naturally slow (stop, cleanup) -----
    record(
        'disable-accept-interactive', ['metrics:disable'], ['Disabled Metrics'],
        key='y', prompt='Disable Metrics?', max_first_output_seconds=2,
        animation={
            'name': 'metrics-disable',
            'pattern': r'(?P<glyph>[○◉]) Disabling Metrics',
            'terminal_pattern': r'● Disabled Metrics',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 2.0,
        },
    )

    deadline = time.monotonic() + 90
    while time.monotonic() < deadline and containers_running(app_dev) != '0':
        time.sleep(3)
    assert containers_running(app_dev) == '0', 'Metrics containers did not stop on app-dev'

    record(
        'status-after-disable-human', ['metrics:status'],
        ['Enabled', 'no', 'No Metrics exporters configured.'],
    )
    status_payload = machine('status-after-disable-json', ['metrics:status'], json_mode=True)
    assert status_payload['enabled'] is False and status_payload['assignment'] is None
    assert status_payload['exporters'] == []

    # -- enable requires a node -------------------------------------------------
    machine(
        'enable-node-required-json', ['metrics:enable'], json_mode=True, expected=1,
        error_code='metrics.node_required',
    )

    # -- interactive select: real keyboard search through the data list -------
    # DataTableRenderer shows "Press / to search" before typing and echoes the
    # typed query back once search mode is active (DataTableRenderer.php:83-84).
    # "app-prod" is a substring of no other node name, so one search term
    # narrows the list to exactly one row; the first Enter exits search and
    # the second Enter submits the (now sole, highlighted) selection.
    record(
        'enable-interactive-select', ['metrics:enable'], ['Enabled Metrics'],
        input_actions=[{'wait_for': 'Press / to search', 'send': '/app-prod\r\r'}],
        max_first_output_seconds=2,
    )

    deadline = time.monotonic() + 90
    while time.monotonic() < deadline and containers_running(app_prod) != '2':
        time.sleep(3)
    assert containers_running(app_prod) == '2', 'Metrics containers did not start on app-prod'

    # -- explicit non-interactive re-assignment (disable, then enable by ID) --
    machine('disable-force-json', ['metrics:disable', '--force'], json_mode=True)
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline and containers_running(app_prod) != '0':
        time.sleep(3)
    assert containers_running(app_prod) == '0'

    machine('enable-explicit-node-json', ['metrics:enable', str(gateway['id'])], json_mode=True)
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline and containers_running(gateway) != '2':
        time.sleep(3)
    assert containers_running(gateway) == '2', 'Metrics containers did not start on gateway'

    # -- a second enable while an assignment exists is a stable conflict -------
    machine(
        'role-conflict-second-enable', ['metrics:enable', str(app_dev['id'])], json_mode=True,
        expected=1, error_code='node.role_conflict',
    )
    assert containers_running(app_dev) == '0', 'role conflict must not mutate the untargeted Node'


def stage_coverage_report():
    stages = ['tools', 'tools-consent', 'metrics-status-creds', 'metrics-exporters', 'metrics-lifecycle']
    required_commands = {
        'tool:install': ['install-apt-human', 'install-apt-unchanged-json', 'install-composer-human', 'install-invalid-package-json'],
        'tool:list': ['list-tools-human', 'list-tools-json', 'list-tools-pipe'],
        'tool:manager:list': [
            'manager-list-human', 'manager-list-undecorated', 'manager-list-json',
            'manager-list-pipe', 'manager-list-invalid-node',
        ],
        'tool:remove': [
            'remove-not-found', 'remove-json-no-yes', 'remove-default-no', 'remove-explicit-no',
            'remove-ctrl-c', 'remove-eof', 'remove-narrow-decline', 'remove-accept-interactive',
            'remove-yes-human', 'remove-yes-json',
        ],
        'tool:show': ['show-tool-human', 'show-tool-undecorated', 'show-tool-narrow'],
        'tool:update': [
            'update-tool-unchanged-human', 'update-tool-undecorated', 'update-tool-pipe',
            'update-tool-unchanged-json',
        ],
        'metrics:credentials': [
            'credentials-show-human', 'credentials-show-undecorated', 'credentials-show-json',
            'credentials-show-pipe', 'credentials-reset-human',
        ],
        'metrics:disable': [
            'disable-json-no-force', 'disable-purge-without-force', 'disable-default-no',
            'disable-ctrl-c', 'disable-eof', 'disable-accept-interactive', 'disable-force-json',
        ],
        'metrics:enable': [
            'enable-node-required-json', 'enable-interactive-select', 'enable-explicit-node-json',
            'role-conflict-second-enable',
        ],
        'metrics:exporter:disable': ['exporter-disable-undecorated', 'exporter-disable-human'],
        'metrics:exporter:enable': ['exporter-enable-human', 'exporter-enable-pipe', 'exporter-invalid-node-json'],
        'metrics:status': [
            'status-initial-human', 'status-initial-json', 'status-undecorated', 'status-pipe',
            'status-after-disable-human', 'status-after-disable-json', 'exporter-status-reflects-json',
        ],
    }
    found = {command: [] for command in required_commands}
    missing = []
    not_proven = []
    for stage in stages:
        stage_root = args.state / stage
        records_path = stage_root / 'records.json'
        if not records_path.exists():
            not_proven.append({'stage': stage, 'reason': 'stage did not run or produced no records.json'})
            continue
        stage_records = json.loads(records_path.read_text())
        by_label = {entry['label']: entry for entry in stage_records}
        for label, entry in by_label.items():
            command = entry.get('command')
            if command in found and entry.get('passed') is True and entry.get('exit') is not None:
                found[command].append(label)
        for command, labels in required_commands.items():
            for label in labels:
                verify_path = stage_root / label / 'verify.json'
                if label in by_label and verify_path.exists():
                    payload = json.loads(verify_path.read_text())
                    if not payload.get('passed'):
                        not_proven.append({'command': command, 'label': label, 'reason': 'verify.json reports failure'})
    for command, labels in required_commands.items():
        for label in labels:
            if label not in found[command]:
                missing.append({'command': command, 'label': label})
    complete = not missing and not not_proven
    aggregate = {
        'commands': sorted(required_commands),
        'found': {command: sorted(set(labels)) for command, labels in found.items()},
        'missing': missing,
        'not_proven': not_proven,
        'complete': complete,
    }
    save(args.state / 'coverage-aggregate.json', aggregate)
    assert complete, ('coverage incomplete', missing, not_proven)
    print(json.dumps(aggregate, indent=2), flush=True)


STAGES = {
    'tools': stage_tools,
    'tools-consent': stage_tools_consent,
    'metrics-status-creds': stage_metrics_status_creds,
    'metrics-exporters': stage_metrics_exporters,
    'metrics-lifecycle': stage_metrics_lifecycle,
    'coverage-report': stage_coverage_report,
}

STAGES[args.stage]()
