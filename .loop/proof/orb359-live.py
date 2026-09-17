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
parser.add_argument(
    '--rehearsal', action='store_true',
    help='Skip the candidate git-identity and clean-tree checks. For a dry run against a '
         "discovery guest, whose /home/orbit/orbit is a live bind mount of the Beast worktree "
         'and cannot report its own git identity from inside the guest. The real plan never '
         'passes this flag.',
)
args = parser.parse_args()

os.umask(0o077)
source = Path('/home/orbit/orbit')
if not args.rehearsal:
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
           final_contains=None, absent=None, max_first_output_seconds=None, state_rows=None):
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
    if state_rows:
        expectation['state_rows'] = state_rows
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
        # Every failure case must name its verified exact code; a case that
        # only asserts the envelope shape is a soft pass.
        assert expected == 0 or error_code is not None, (label, 'a failure case must set error_code')
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
    # A shell `|| echo 'not-found'` on the remote side would mask any remote
    # failure (dpkg database locked, dpkg-query missing, ssh flake that still
    # exits 0) as indistinguishable from "the package was never installed".
    # Capture the remote command's own exit code explicitly instead, so a
    # dpkg-query failure other than "unknown package" (exit 1) raises here
    # rather than passing a resulting-state assertion it did not prove.
    output = remote(
        node,
        "dpkg-query -W -f='${Status}' " + shlex.quote(package) + "; printf '\\nRC=%s' $?",
    )
    body, marker_present, marker = output.rpartition('RC=')
    assert marker_present, ('dpkg-query exit marker missing (ssh or remote shell problem)', node['name'], package, output)
    code = int(marker)
    assert code in (0, 1), ('dpkg-query failed unexpectedly', node['name'], package, code, body)
    return 'not-found' if code == 1 else body.strip()


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

    # -- tool:install (apt, real work, deliberately slow via progress). Drives
    # the manager data list and the package prompt interactively instead of
    # passing --manager/package, since no fixture case exercised either
    # before this round: eligible managers are sorted by name (apt, brew,
    # composer), so Enter on the highlighted first row selects apt without a
    # search; the Package TextPrompt then reads 'tree' the same way the Node
    # search term is typed elsewhere in this script.
    assert dpkg_status(app_prod, 'tree') != 'install ok installed'
    record(
        'install-apt-human', ['tool:install', '--node', str(node_id)],
        ['Tool manager', 'Package', 'Install Tool', 'Installed Tool', 'tree', 'apt', 'Status', 'installed'],
        max_first_output_seconds=2,
        input_actions=[
            {'wait_for': 'Press / to search', 'send': '\n'},
            {'wait_for': 'Package', 'send': 'tree'},
            {'wait_for': 'tree', 'send': '\n'},
        ],
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

    # -- SIGINT while an install is pending. The CLI must exit 130 with
    # "Operation interrupted." and restored termios; the Gateway-side apt
    # operation the interrupted request started may still finish on its own
    # (confirmed live: the server completes anyway even though the CLI
    # already returned), so the resulting state check accepts either outcome
    # instead of asserting one, and only fails when dpkg itself cannot answer.
    record(
        'install-sigint', ['tool:install', 'zip', '--node', str(node_id), '--manager', 'apt'],
        ['Operation interrupted.'],
        expected=130,
        input_actions=[{'wait_for': 'Installing Tool', 'send': '\x03'}],
    )
    # dpkg_status() already raises on an unproven (SSH/remote) failure, so any
    # string it returns here is a real, verified answer; a completed install
    # is the only state that needs cleaning up (a partial or absent one does
    # not, and dpkg reports several distinct "not really installed" strings
    # depending on how far the interrupted operation got).
    zip_status = dpkg_status(app_prod, 'zip')
    if zip_status == 'install ok installed':
        zip_tools = read('tool:list', '--node', str(node_id))['tools']
        zip_tool = next(t for t in zip_tools if t['package'] == 'zip')
        read('tool:remove', str(zip_tool['id']), '--yes')
        assert dpkg_status(app_prod, 'zip') != 'install ok installed'

    # -- tool:install (composer). Role convergence on app-prod already
    # requires Composer for its own deploy tooling, so the manager can start
    # 'active' rather than 'uninstalled' here (docs/reference/tools.md:
    # "Materialized on first use or when a role requires it"). Assert the
    # manager ends active and the Tool row records a real install, without
    # assuming which state it started in.
    managers_before = read('tool:manager:list', '--node', str(node_id))['managers']
    composer_before = next(m for m in managers_before if m['name'] == 'composer')
    assert composer_before['status'] in ('uninstalled', 'active'), composer_before
    record(
        'install-composer-human', ['tool:install', 'psr/log', '--node', str(node_id), '--manager', 'composer'],
        ['Install Tool', 'Installed Tool', 'psr/log', 'composer'],
        max_first_output_seconds=2,
        animation={
            'name': 'tool-install-composer',
            'pattern': r'(?P<glyph>[○◉]) Installing Tool',
            'terminal_pattern': r'● Installed Tool',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 5.0,
        },
    )
    managers_after = read('tool:manager:list', '--node', str(node_id))['managers']
    composer_after = next(m for m in managers_after if m['name'] == 'composer')
    assert composer_after['status'] == 'active', composer_after
    composer_tools = read('tool:list', '--node', str(node_id))['tools']
    composer_tool = next(t for t in composer_tools if t['package'] == 'psr/log')
    assert composer_tool['status'] == 'installed', composer_tool

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
    # unchanged now settles Skipped (F1), so the row shows the waiting-tense
    # "Update Tool" label rather than a false past-tense "Updated Tool" claim,
    # and the footer reads "Tool already up to date." instead of the generic
    # completed label. The glyph is still the filled terminal "●"; only "○"
    # (waiting, before the request starts) or "◉"/"○" (running, alternating)
    # would ever precede "Update Tool" otherwise, so '● Update Tool' is
    # unambiguous for the settled terminal frame.
    record(
        'update-tool-unchanged-human', ['tool:update', str(composer_tool['id'])],
        ['Update Tool', 'Tool already up to date.', 'psr/log', 'already current'],
        absent=['Updated Tool'],
        animation={
            'name': 'tool-update-composer',
            'pattern': r'(?P<glyph>[○◉]) Updating Tool',
            'terminal_pattern': r'● Update Tool',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 3.0,
        },
    )
    # A freshly installed apt package is already at its current candidate
    # version, so this update is unchanged too (F1: settles Skipped, "Update
    # Tool" row label, not "Updated Tool").
    record(
        'update-tool-undecorated', ['tool:update', str(tool_id)],
        ['Update Tool', 'tree'], absent=['Updated Tool'], plain=True,
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
    remove_question = f'Remove Tool [tree] (apt on Node #{node_id}) by uninstalling it and deleting its record?'

    def install_disposable():
        payload = read(
            'tool:install', 'tree', '--node', str(node_id), '--manager', 'apt',
        )
        return payload['id']

    # -- unknown Tool fails with the Gateway's own not-found code, before any
    # prompt (no confirmation code, no RemoveToolRequest reaches the mutation).
    # The real Gateway answers a nonexistent numeric Tool ID with the generic
    # http.404, not a tool.*-domain code (confirmed on rehearsal #2); ORB-359
    # is CLI-rendering-only, so this fixture matches that existing contract
    # rather than assuming or inventing a domain-specific one.
    show_missing = subprocess.run(
        ['php', str(launcher), 'tool:remove', '999999', '--json'],
        cwd=source, env=env, input=b'', capture_output=True, timeout=60,
    )
    assert show_missing.returncode == 1, show_missing.stdout
    missing_payload = json.loads(show_missing.stdout)
    missing_code = missing_payload['error']['code']
    assert missing_code not in ('input.confirmation_required', 'input.cancelled'), missing_payload
    assert missing_code == 'http.404', ('expected the current not-found code', missing_payload)
    records.append({'label': 'remove-not-found', 'command': 'tool:remove', 'mode': 'json', 'exit': 1, 'passed': True})
    save(root / 'records.json', records)

    # -- the same not-found failure in a real TTY, before any prompt ---------
    record(
        'remove-not-found-tty', ['tool:remove', '999999'], ['Request ID'],
        absent=['Remove Tool', 'uninstalling', 'Yes'],
        expected=1,
    )

    # -- JSON automation without --yes: resolves, then refuses, no mutation --
    tool_id = install_disposable()
    machine(
        'remove-json-no-yes', ['tool:remove', str(tool_id)], json_mode=True, expected=1,
        error_code='input.confirmation_required',
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- the same automation refusal in a human pipe and with --no-interaction
    machine(
        'remove-pipe-no-yes', ['tool:remove', str(tool_id)], json_mode=False, expected=1,
        contains=['Supply --yes to confirm this operation.'], absent=['\x1b'],
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'
    record(
        'remove-no-interaction-tty', ['tool:remove', str(tool_id), '--no-interaction'],
        ['Supply --yes to confirm this operation.'],
        absent=['Remove Tool', 'uninstalling', 'Yes'],
        expected=1,
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    machine('remove-cleanup-1', ['tool:remove', str(tool_id), '--yes'], json_mode=True)
    assert dpkg_status(app_prod, 'tree') != 'install ok installed'

    # -- interactive default-No: unchanged -----------------------------------
    tool_id = install_disposable()
    record(
        'remove-default-no', ['tool:remove', str(tool_id)], ['cancelled', remove_question],
        expected=1, key='\r', prompt='Yes',
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- interactive explicit No -----------------------------------------------
    record(
        'remove-explicit-no', ['tool:remove', str(tool_id)], ['cancelled', remove_question],
        expected=1, key='n', prompt='Yes',
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- Ctrl-C before mutation -------------------------------------------------
    # A raw 0x03 byte read by a prompt in raw mode is not a kernel SIGINT; the
    # prompt library itself recognizes Key::CTRL_C and confirmAction() catches
    # it the same way as a decline, returning exit 1 (CommandPromptsTest.php's
    # 'requires affirmative consent...' case: Ctrl-C -> status 1, not 130).
    record(
        'remove-ctrl-c', ['tool:remove', str(tool_id)], ['cancelled', remove_question],
        expected=1, input_actions=[{'wait_for': 'Yes', 'send': '\x03'}],
    )
    assert dpkg_status(app_prod, 'tree') == 'install ok installed'

    # -- EOF before mutation ----------------------------------------------------
    record(
        'remove-eof', ['tool:remove', str(tool_id)], ['cancelled', remove_question],
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
        animation={
            'name': 'tool-remove-yes',
            'pattern': r'(?P<glyph>[○◉]) Removing Tool',
            'terminal_pattern': r'● Removed Tool',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 3.0,
        },
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
        ['curl', '-s', '-u', 'admin:' + original_password, '-o', '/dev/null', '-w', '%{http_code}',
         'http://' + app_dev['wireguard_ip'] + ':3000/api/org'],
        capture_output=True, text=True, timeout=15,
    )
    assert grafana.stdout.strip() == '200', ('grafana auth (original password)', grafana.stdout, grafana.stderr)

    # -- reset: multi-step (generate, apply, verify, promote); real slow work
    record(
        'credentials-reset-human', ['metrics:credentials', '--reset'],
        ['URL', 'Username', 'Password', 'admin'],
        max_first_output_seconds=2,
        # Confirmed live (rehearsal #3): the real reset completes too quickly
        # for a reliable animation_rows assertion (insufficient glyph
        # alternation). Keep a state_rows check instead: it only requires the
        # progress tree to reach its settled success glyph, proving the
        # recording captures a real completed run rather than asserting a
        # cadence this fast operation can't reliably produce.
        state_rows=[{
            'name': 'credentials-reset',
            'pattern': r'(?P<state>[○◉●]) Reset(?:ting)? Metrics credentials',
            'states': ['○', '◉', '●'],
            'transitions': [['○', '◉'], ['◉', '○'], ['○', '●'], ['◉', '●']],
            'required': ['●'],
        }],
    )
    reset_payload = machine('credentials-reset-json-reread', ['metrics:credentials'], json_mode=True)
    new_password = reset_payload['password']
    assert new_password != original_password, 'reset did not change the password'

    grafana_new = subprocess.run(
        ['curl', '-s', '-u', 'admin:' + new_password, '-o', '/dev/null', '-w', '%{http_code}',
         'http://' + app_dev['wireguard_ip'] + ':3000/api/org'],
        capture_output=True, text=True, timeout=15,
    )
    assert grafana_new.stdout.strip() == '200', ('grafana auth (new password)', grafana_new.stdout)
    grafana_old = subprocess.run(
        ['curl', '-s', '-u', 'admin:' + original_password, '-o', '/dev/null', '-w', '%{http_code}',
         'http://' + app_dev['wireguard_ip'] + ':3000/api/org'],
        capture_output=True, text=True, timeout=15,
    )
    assert grafana_old.stdout.strip() in ('401', '403'), ('old password should no longer authenticate', grafana_old.stdout)

    # -- reset in JSON mode: same multi-step reset, machine-readable schema --
    json_reset_payload = machine('credentials-reset-json', ['metrics:credentials', '--reset'], json_mode=True)
    newest_password = json_reset_payload['password']
    assert newest_password != new_password, 'JSON reset did not change the password'
    grafana_newest = subprocess.run(
        ['curl', '-s', '-u', 'admin:' + newest_password, '-o', '/dev/null', '-w', '%{http_code}',
         'http://' + app_dev['wireguard_ip'] + ':3000/api/org'],
        capture_output=True, text=True, timeout=15,
    )
    assert grafana_newest.stdout.strip() == '200', ('grafana auth (JSON-reset password)', grafana_newest.stdout)


def stage_metrics_exporters():
    # app-prod carries the app-prod role, so ADR 0003's default projection
    # ("A role-bearing node with no preference is selected") already selects
    # it: prometheus-node-exporter is active with reason=role_default before
    # this stage runs (confirmed live against the discovery topology). So the
    # first real, observable transition here is disable (role_default ->
    # explicit disabled, active -> inactive); enable afterward is the second
    # real transition (explicit disabled -> explicit enabled, back to active).
    nodes = read('node:list')['nodes']
    app_prod = find_node(nodes, 'app-prod')
    node_id = app_prod['id']

    def exporter_active():
        return remote(
            app_prod,
            "systemctl is-active prometheus-node-exporter 2>&1 || true",
        ).strip()

    assert exporter_active() == 'active', 'app-prod should start exporter-active by role_default'

    status_before = machine('exporter-status-before-json', ['metrics:status'], json_mode=True)
    row_before = next(r for r in status_before['exporters'] if r['name'] == 'app-prod')
    assert row_before['desired'] is True and row_before['reason'] == 'role_default', row_before

    def wait_for(expected_active):
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline and (exporter_active() == 'active') != expected_active:
            time.sleep(2)
        assert (exporter_active() == 'active') == expected_active, (
            'exporter did not converge', node_id, expected_active,
        )

    record(
        'exporter-disable-human', ['metrics:exporter:disable', str(node_id)],
        ['Disable Metrics exporter', 'Disabled Metrics exporter', 'Status', 'disabled'],
        animation={
            'name': 'exporter-disable',
            'pattern': r'(?P<glyph>[○◉]) Disabling Metrics exporter',
            'terminal_pattern': r'● Disabled Metrics exporter',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 5.0,
        },
    )
    wait_for(False)

    status_payload = machine('exporter-status-reflects-json', ['metrics:status'], json_mode=True)
    row = next(r for r in status_payload['exporters'] if r['name'] == 'app-prod')
    assert row['desired'] is False and row['reason'] == 'explicit_disabled', row

    record(
        'exporter-enable-undecorated', ['metrics:exporter:enable', str(node_id)],
        ['Enabled Metrics exporter', 'enabled'], plain=True,
    )
    wait_for(True)

    machine(
        'exporter-disable-pipe', ['metrics:exporter:disable', str(node_id)], json_mode=False,
        contains=['disabled'], absent=['\x1b'],
    )
    wait_for(False)

    record(
        'exporter-enable-human', ['metrics:exporter:enable', str(node_id)],
        ['Enable Metrics exporter', 'Enabled Metrics exporter', 'Status', 'enabled'],
        animation={
            'name': 'exporter-enable',
            'pattern': r'(?P<glyph>[○◉]) Enabling Metrics exporter',
            'terminal_pattern': r'● Enabled Metrics exporter',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 5.0,
        },
    )
    wait_for(True)

    machine(
        'exporter-invalid-node-json', ['metrics:exporter:enable', '999999'], json_mode=True,
        expected=1, error_code='http.404',
    )

    # -- JSON-mode success schema for both exporter mutations -----------------
    machine('exporter-disable-json', ['metrics:exporter:disable', str(node_id)], json_mode=True)
    wait_for(False)
    machine('exporter-enable-json', ['metrics:exporter:enable', str(node_id)], json_mode=True)
    wait_for(True)


def stage_metrics_lifecycle():
    nodes = read('node:list')['nodes']
    app_dev = find_node(nodes, 'app-dev')
    gateway = find_node(nodes, 'gateway')
    app_prod = find_node(nodes, 'app-prod')
    disable_question = 'Disable Metrics? Data: preserve. Assignment: remove.'

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

    # -- the same automation refusal in a human pipe and with --no-interaction,
    # before any status read (F2b: no ShowMetricsStatusRequest without --force)
    machine(
        'disable-pipe-no-force', ['metrics:disable'], json_mode=False, expected=1,
        contains=['Non-interactive Metrics disable requires --force.'], absent=['\x1b'],
    )
    record(
        'disable-no-interaction-tty', ['metrics:disable', '--no-interaction'],
        ['Non-interactive Metrics disable requires --force.'],
        absent=['Disable Metrics?', 'preserve'],
        expected=1,
    )
    assert containers_running(app_dev) == '2'

    # -- interactive decline / cancel: unchanged -------------------------------
    record(
        'disable-default-no', ['metrics:disable'], ['cancelled', disable_question],
        expected=1, key='\r', prompt='Disable Metrics?',
    )
    assert containers_running(app_dev) == '2'
    record(
        'disable-ctrl-c', ['metrics:disable'], ['cancelled', disable_question],
        expected=1, input_actions=[{'wait_for': 'Disable Metrics?', 'send': '\x03'}],
    )
    assert containers_running(app_dev) == '2'
    record(
        'disable-eof', ['metrics:disable'], ['cancelled', disable_question],
        expected=1, input_actions=[{'wait_for': 'Disable Metrics?', 'send': '\x04'}],
    )
    assert containers_running(app_dev) == '2'

    # -- interactive accept: real disable, naturally slow (stop, cleanup) -----
    record(
        'disable-accept-interactive', ['metrics:disable'], ['Disabled Metrics'],
        key='y', prompt='Disable Metrics?', max_first_output_seconds=2,
        # This case animated correctly for its real 16 s run (alternating
        # every ~0.30 s from 2.45 s to 16.27 s) and is NOT too fast. Rehearsal
        # #4 failed only because the shared Animation helper clears the
        # progress tree in one PTY read and redraws the settled frame in the
        # next; the recorder caught that ~10 ms blank frame between them.
        # This is a shared-renderer artifact (apps/cli/app/Support/Console/
        # Animation.php, not ORB-359 command code), documented and approved
        # as an exception in orb359-shared-animation-flicker-finding.md
        # (Solo 1755, 2026-09-17). state_rows only requires the progress tree
        # to reach its settled success glyph, tolerating that transient blank
        # frame instead of requiring strict glyph-to-glyph adjacency.
        state_rows=[{
            'name': 'metrics-disable',
            'pattern': r'(?P<state>[○◉●]) Disabl(?:e|ing|ed) Metrics',
            'states': ['○', '◉', '●'],
            'transitions': [['○', '◉'], ['◉', '○'], ['○', '●'], ['◉', '●']],
            'required': ['●'],
        }],
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

    # -- cancel while the Node data list itself is showing, before any selection
    record(
        'enable-node-list-ctrl-c', ['metrics:enable'], ['Input cancelled.'],
        expected=1, input_actions=[{'wait_for': 'Press / to search', 'send': '\x03'}],
    )
    assert containers_running(app_dev) == '0', 'cancel at the Node list must not mutate anything'

    # -- interactive select: real keyboard search through the data list -------
    # DataTableRenderer shows "Press / to search" before typing and echoes the
    # typed query back once search mode is active (DataTableRenderer.php:83-84).
    # "app-prod" is a substring of no other node name, so one search term
    # narrows the list to exactly one row; the first Enter exits search and
    # the second Enter submits the (now sole, highlighted) selection.
    #
    # DataTablePrompt::handleBrowseKey()/handleSearchKey() match a whole
    # event's bytes against single-character Key constants (Prompt::runLoop()
    # never splits terminal()->read()'s return value before dispatching the
    # 'key' event). Only TypedValue's own listener splits a multi-byte event
    # with mb_str_split() to accumulate typed text. So '/', and each Enter,
    # must each arrive as their own event (rehearsal #5: sending
    # '/app-prod\r\r' as one action never matched '/' at all and hung for the
    # full idle timeout); the search text itself is safe to send as one
    # batch. Key::ENTER is literally "\n" (Key.php:37); sent directly rather
    # than relying on a raw pty's ICRNL translation of "\r".
    record(
        'enable-interactive-select', ['metrics:enable'], ['Enabled Metrics'],
        input_actions=[
            {'wait_for': 'Press / to search', 'send': '/'},
            {'wait_for': 'Node', 'send': 'app-prod'},
            {'wait_for': 'app-prod', 'send': '\n'},
            {'wait_for': 'app-prod', 'send': '\n'},
        ],
        max_first_output_seconds=2,
        animation={
            'name': 'metrics-enable-select',
            'pattern': r'(?P<glyph>[○◉]) Enabling Metrics',
            'terminal_pattern': r'● Enabled Metrics',
            'minimum_changes': 2, 'min_interval': 0.15, 'max_interval': 5.0,
        },
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

    # Restore the topology's baseline Metrics assignment (app-dev) so the
    # harness's post-proof role/publication verification sees the same
    # assignment it started with. mutates: true covers Tool installs and
    # exporter/credential state; the Metrics *role* assignment itself is
    # restored deliberately rather than left on gateway.
    machine('lifecycle-restore-disable', ['metrics:disable', '--force'], json_mode=True)
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline and containers_running(gateway) != '0':
        time.sleep(3)
    assert containers_running(gateway) == '0'

    machine('lifecycle-restore-enable', ['metrics:enable', str(app_dev['id'])], json_mode=True)
    deadline = time.monotonic() + 90
    while time.monotonic() < deadline and containers_running(app_dev) != '2':
        time.sleep(3)
    assert containers_running(app_dev) == '2', 'Metrics did not restore to app-dev'


def stage_coverage_report():
    stages = ['tools', 'tools-consent', 'metrics-status-creds', 'metrics-exporters', 'metrics-lifecycle']
    required_commands = {
        'tool:install': [
            'install-apt-human', 'install-apt-unchanged-json', 'install-composer-human',
            'install-invalid-package-json', 'install-sigint',
        ],
        'tool:list': ['list-tools-human', 'list-tools-json', 'list-tools-pipe'],
        'tool:manager:list': [
            'manager-list-human', 'manager-list-undecorated', 'manager-list-json',
            'manager-list-pipe', 'manager-list-invalid-node',
        ],
        'tool:remove': [
            'remove-not-found', 'remove-not-found-tty', 'remove-json-no-yes', 'remove-pipe-no-yes',
            'remove-no-interaction-tty', 'remove-default-no', 'remove-explicit-no',
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
            'credentials-show-pipe', 'credentials-reset-human', 'credentials-reset-json',
        ],
        'metrics:disable': [
            'disable-json-no-force', 'disable-purge-without-force', 'disable-pipe-no-force',
            'disable-no-interaction-tty', 'disable-default-no',
            'disable-ctrl-c', 'disable-eof', 'disable-accept-interactive', 'disable-force-json',
        ],
        'metrics:enable': [
            'enable-node-required-json', 'enable-node-list-ctrl-c', 'enable-interactive-select',
            'enable-explicit-node-json', 'role-conflict-second-enable',
        ],
        'metrics:exporter:disable': ['exporter-disable-human', 'exporter-disable-pipe', 'exporter-disable-json'],
        'metrics:exporter:enable': [
            'exporter-enable-undecorated', 'exporter-enable-human', 'exporter-enable-json',
            'exporter-invalid-node-json',
        ],
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
