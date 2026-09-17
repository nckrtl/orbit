#!/usr/bin/env python3
"""ORB-360 Herdr CLI UX proof driver.

Every stage runs on `gateway`. `/home/orbit/orbit` is NOT a shared mount in
a real proof topology — `WorktreeSynchronizer::syncCommit` copies the
candidate into each checkout Node separately — so a stage cannot hand data
to a stage running on a different Node by writing a file under the
checkout (that only ever worked on a discovery guest's shared virtiofs
mount, and would rehearse clean while the real prove failed). Stages that
need `app-dev`-side state (`external-session`, `discover-panes`,
`verify-external-survives`, and the guest-Process check inline inside
`consent`) reach it over SSH from `gateway` instead, using Orbit's own
Node SSH key
(`ORBIT_HOME/ssh/id_ed25519`) to log in as the Node's registered managed
user — the same user Orbit itself uses to provision the Node, and the same
approach ORB-361 used for `app-prod` — then `sudo` for anything that needs
root (writing the external session's systemd unit, `systemctl start`).
Cross-stage handoff on `gateway` itself (a later stage process needs a
fact an earlier one produced) goes through the local `--state` directory
via `state_write()`/`state_read()`, the same mechanism `prepare` already
used for `prepare.json` — never the checkout mount.

`herdr:observe` signs a grant for whatever pane/terminal the caller
supplies without validating them against the live snapshot — that check
happens later at the WebSocket Node adapter, which this CLI-level proof
does not reach (apps/gateway/app/Actions/Herdr/IssueObservationGrantAction.php).
Its success path does not depend on `discover-panes` finding a real pane.
`herdr:session:destroy`'s live-pane guard is different: it reads the real
snapshot (App\\Actions\\Herdr\\RemoveHerdrSessionAction via
HerdrSessionInspector), so proving `herdr.session_in_use` for real does
depend on `discover-panes` finding an actual pane.

Coverage is tracked per (command, mode) cell, not per stage: every
`record()`/`pipe()` call derives its command from `argv[0]` and its mode
from `decorated`/`json_mode`, and `coverage-report` cross-checks the
observed cells against `EXPECTED_MODE_MATRIX`. A path that could not be
proven for real is recorded with `not_proven()`, never faked as `passed`.

See /Users/nckrtl/.codex/work/cli-ux-restoration/orb360-live-binary-gap.md
for the investigation behind running the full live path, and
orb360-fixture-review-677cc767.md for the review this design answers.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import time
from pathlib import Path

CHECKOUT = Path('/home/orbit/orbit')
RECORDER = CHECKOUT / '.agents/skills/verifying-cli-output/scripts'
VENV_PYTHON = Path('/home/orbit/.local/state/orbit-cli-ux/ORB-360/venv/bin/python')
HERDR_BIN = Path('/home/linuxbrew/.linuxbrew/bin/herdr')
NODE = 'app-dev'

MANAGED_SESSION = 'commander-tasks'
EXTERNAL_SESSION = 'watchtower-external'
CONSENT_DECLINE_SESSION = 'consent-decline'
CONSENT_ACCEPT_SESSION = 'consent-accept'
CONSENT_HUMAN_YES_SESSION = 'consent-human-yes'
CONSENT_PIPE_SESSION = 'consent-pipe'

MODES = ('human-decorated', 'human-undecorated', 'json', 'pipe-plain')
EXPECTED_MODE_MATRIX = {
    'herdr:session:create': set(MODES),
    'herdr:session:adopt': set(MODES),
    'herdr:session:list': set(MODES),
    'herdr:session:show': set(MODES),
    'herdr:session:restart': set(MODES),
    'herdr:session:destroy': set(MODES),
    'herdr:observe': set(MODES),
}

FAILURES: list[str] = []


def fail(label: str, message: str) -> None:
    FAILURES.append(f'{label}: {message}')
    print(json.dumps({'label': label, 'failed': message}), file=sys.stderr, flush=True)


def stage_dir(state: Path, stage: str) -> Path:
    directory = state / stage
    directory.mkdir(parents=True, exist_ok=True)
    return directory


def append_record(root: Path, entry: dict) -> None:
    path = root / 'records.json'
    records = json.loads(path.read_text()) if path.exists() else []
    records.append(entry)
    path.write_text(json.dumps(records, indent=2) + '\n')


def not_proven(root: Path, label: str, command: str, mode: str, reason: str) -> None:
    """Record a path that could not be exercised for real. Never `passed`,
    and deliberately does not call `fail()`: the rest of the proof keeps
    running so it can still prove what it can, and `coverage-report` is
    the single place that turns an unproven cell into a failed proof."""
    append_record(root, {
        'label': label, 'command': command, 'mode': mode, 'passed': False,
        'not_proven': True, 'reason': reason,
    })
    print(json.dumps({'label': label, 'not_proven': reason}), file=sys.stderr, flush=True)


def orbit_home(state: Path) -> Path:
    return state / 'orbit-home'


def child_env(state: Path) -> dict:
    env = dict(os.environ)
    env['ORBIT_HOME'] = str(orbit_home(state))
    return env


def cli_json(state: Path, argv: list[str], *, timeout: float = 60) -> tuple[int, dict | None, str, str]:
    """Run one `orbit ... --json` call directly (no PTY, no assertions) and return its result."""
    result = subprocess.run(['orbit', *argv, '--json'], capture_output=True, text=True, env=child_env(state), timeout=timeout)
    payload = None
    if result.stdout.strip():
        try:
            payload = json.loads(result.stdout)
        except json.JSONDecodeError:
            payload = None
    return result.returncode, payload, result.stdout, result.stderr


def save_raw_output(root: Path, label: str, stdout: str, stderr: str) -> None:
    """Persist the raw stdout/stderr next to a recorded case that was driven
    through `cli_json()` (whose own return value is transient), matching
    what `pipe()` keeps on disk for every case it drives directly."""
    case_dir = root / label
    case_dir.mkdir(parents=True, exist_ok=True)
    (case_dir / 'stdout.txt').write_text(stdout)
    (case_dir / 'stderr.txt').write_text(stderr)


def state_write(state: Path, name: str, payload: dict) -> None:
    """Cross-stage handoff on `gateway` itself: each stage is its own process
    invocation, but they all run on the same Node and share the same local
    `--state` directory (never the checkout mount)."""
    (state / name).write_text(json.dumps(payload, indent=2) + '\n')


def state_read(state: Path, name: str) -> dict | None:
    path = state / name
    if not path.exists():
        return None
    return json.loads(path.read_text())


def ssh_identity() -> tuple[Path, Path]:
    # The LIVE ORBIT_HOME, not `prepare`'s frozen `state/orbit-home` copy:
    # the Gateway's own Node-management SSH (RemoteToolCommandRunner,
    # NativeSshExecutor) reads `config('orbit.home')` directly and keeps
    # adding to its `known_hosts` as later stages (`install` etc.) reach
    # app-dev, so only the live file is guaranteed current by the time a
    # later stage's `remote()` call runs. Matches ORB-361's `remote()`.
    home = Path(os.environ.get('ORBIT_HOME', '/home/orbit/.orbit'))
    return home / 'ssh/id_ed25519', home / 'ssh/known_hosts'


def remote(host: str, user: str, command: str, *, input_text: str | None = None,
           timeout: float = 30) -> tuple[int, str, str]:
    """Run one command on `app-dev` over SSH as its registered managed user
    (the same account Orbit itself uses to provision the Node), matching
    ORB-361's `remote()` helper for `app-prod`. Returns the raw exit code
    instead of asserting success: some callers (`systemctl is-active`)
    treat a non-zero exit as a legitimate, meaningful answer."""
    identity, known_hosts = ssh_identity()
    argv = ['ssh', '-i', str(identity),
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known_hosts),
            '-o', 'ConnectTimeout=10', f'{user}@{host}', command]
    result = subprocess.run(argv, input=input_text, capture_output=True, text=True, timeout=timeout)
    return result.returncode, result.stdout, result.stderr


def app_dev_target(state: Path) -> tuple[str, str]:
    """The `app-dev` Node's WireGuard address and registered managed user,
    as `prepare` recorded them in `prepare.json`."""
    info = prepared(state)
    return info['node_ip'], info['node_user']


def json_field_contains(payload: dict, *, exclude: set[str] = frozenset()) -> list[str]:
    """Every non-null top-level JSON field value (`health`'s nested values
    included), stringified for a `contains` check, minus any key the
    caller excludes because it is regenerated on every separate call (a
    fresh `request_id`, or a freshly issued grant's `nonce`/`expires_at`/
    `observer_url`) and so cannot match between two independent live
    invocations of the same command. Proves a decorated detail tree keeps
    full field parity with JSON generically, rather than a hand-picked
    spot check that only names the fields someone thought to check."""
    values: list[str] = []
    for key, value in payload.items():
        if key in exclude:
            continue
        if key == 'health' and isinstance(value, dict):
            values.extend(str(v) for v in value.values() if v is not None)
            continue
        if value is not None:
            values.append(str(value))
    return values


# docs/reference/cli-ux.md, Progress and liveness: "Active indicators
# alternate about every 300 milliseconds." A step whose observed active
# window is shorter than this cannot show even one alternation — that is a
# fact about the capture, not a choice made about the command in advance.
ALTERNATION_INTERVAL_SECONDS = 0.300


def measure_active_seconds(capture_dir: Path, running_label: str) -> float:
    """The real elapsed span, read back from this capture's own frames,
    during which the progress row showed its running label. Zero or one
    matching frame (the span is 0.0) means the step completed inside a
    single captured frame — too fast to alternate even once, regardless of
    which command it was."""
    frames_path = capture_dir / 'frames.jsonl'
    if not frames_path.exists():
        return 0.0
    pattern = re.compile(rf'[○◉]\s+{re.escape(running_label)}')
    elapsed = [
        frame['elapsed']
        for frame in (json.loads(line) for line in frames_path.read_text().splitlines())
        if any(pattern.search(line) for line in frame['lines'])
    ]
    return elapsed[-1] - elapsed[0] if len(elapsed) >= 2 else 0.0


def progress_checks(waiting: str, running: str, completed: str, *, max_first_output_seconds: float = 2.0) -> dict:
    """Liveness assertions for a decorated `sendWithProgress` capture: the
    single `request` row must reach its completed label without an unknown
    or forbidden transition, and should show at least one glyph change
    while running when the guest is slow enough to observe it. A guest fast
    enough to skip the running frame entirely still passes: `waiting ->
    completed` is an allowed transition, and only the completed state is
    required.

    The animation check itself is added by `record()`, after capture,
    only when `measure_active_seconds()` finds the row's real running
    window at or above `ALTERNATION_INTERVAL_SECONDS` — never decided here
    in advance for a particular command. `record()` writes the measurement
    into the case directory either way."""
    labels = [waiting, running, completed]
    escaped = [re.escape(label) for label in labels]
    alternatives = '|'.join(escaped)
    return {
        'max_first_output_seconds': max_first_output_seconds,
        'state_rows': [{
            'name': 'request',
            # `sendWithProgress`'s title equals the step's own waiting label
            # (ProgressDisplay's `┌  {title}` header line), and the finished
            # footer (`└  {outcome}`) reuses the completed label too — both
            # would otherwise also match `alternatives` and trip "ambiguous
            # state row". Only the step's own `├  {glyph} {label}` row ever
            # has a glyph directly before the label, so require one.
            'pattern': rf'[○◉●]\s+(?P<state>{alternatives})',
            'states': labels,
            'transitions': [[waiting, running], [running, completed], [waiting, completed]],
            'required': [completed],
        }],
        '_progress': {
            'running_label': running,
            'animation_rows': [{
                'name': 'request',
                'pattern': rf'(?P<glyph>[○◉])\s+{re.escape(running)}',
                'terminal_pattern': re.escape(completed),
                'minimum_changes': 1,
                'min_interval': 0.05,
                'max_interval': 0.5,
            }],
        },
    }


def record(
    state: Path,
    stage: str,
    label: str,
    candidate: str,
    argv: list[str],
    *,
    decorated: bool = True,
    columns: int = 100,
    rows: int = 40,
    timeout: float = 60,
    idle_timeout: float = 20,
    input_plan: list[dict] | None = None,
    exit_code: int = 0,
    contains: list[str] | None = None,
    absent: list[str] | None = None,
    extra_expectation: dict | None = None,
) -> bool:
    """Capture one case in a real PTY through the shared recorder skill.
    Returns whether it passed. Streams cannot be checked separately for a
    PTY capture (cli-ux.md: "a combined PTY transcript cannot prove channel
    placement") — that check belongs to `pipe()`."""
    root = stage_dir(state, stage)
    case_dir = root / label
    case_dir.mkdir(parents=True, exist_ok=True)
    capture_dir = case_dir / 'capture'

    child_argv = ['orbit', *argv, '--ansi' if decorated else '--no-ansi']
    command = argv[0]
    mode = 'human-decorated' if decorated else 'human-undecorated'

    cmd = [
        str(VENV_PYTHON), str(RECORDER / 'capture.py'),
        '--output-dir', str(capture_dir),
        '--candidate', candidate,
        '--label', label,
        '--columns', str(columns),
        '--rows', str(rows),
        '--timeout', str(timeout),
        '--idle-timeout', str(idle_timeout),
        '--no-live',
    ]
    input_plan_path = None
    if input_plan is not None:
        input_plan_path = case_dir / 'inputs.json'
        input_plan_path.write_text(json.dumps(input_plan))
        cmd += ['--input-plan', str(input_plan_path)]
    cmd += ['--', *child_argv]

    result = subprocess.run(cmd, capture_output=True, text=True, env=child_env(state))
    # capture.py's own exit code mirrors the captured child's real exit code
    # (124/125 are its own timeout/crash signals), so this must compare
    # against the caller's expected `exit_code`, not a fixed {0, 124, 125}:
    # a case that legitimately expects a non-zero exit (every declined
    # `destroy`, every `tool-not-installed` case) would otherwise always
    # fail here before verify.py ever gets to judge it for real.
    if result.returncode in (124, 125):
        fail(label, f'capture.py failed to complete cleanly (exit {result.returncode}): {result.stderr.strip()}')
        return False
    if result.returncode != exit_code:
        fail(label, f'expected exit {exit_code}, got {result.returncode}: {result.stderr.strip()}')
        return False

    expectation = {'candidate': candidate, 'label': label, 'exit_code': exit_code}
    if contains:
        expectation['contains'] = contains
    if absent:
        expectation['absent'] = absent
    progress = None
    if extra_expectation:
        extra_expectation = dict(extra_expectation)
        progress = extra_expectation.pop('_progress', None)
        expectation.update(extra_expectation)
    if progress is not None:
        active_seconds = measure_active_seconds(capture_dir, progress['running_label'])
        animation_required = active_seconds >= ALTERNATION_INTERVAL_SECONDS
        (case_dir / 'progress-measurement.json').write_text(json.dumps({
            'running_label': progress['running_label'],
            'active_seconds': active_seconds,
            'alternation_interval_seconds': ALTERNATION_INTERVAL_SECONDS,
            'animation_required': animation_required,
        }, indent=2) + '\n')
        if animation_required:
            expectation['animation_rows'] = progress['animation_rows']
    expectation_path = case_dir / 'expectation.json'
    expectation_path.write_text(json.dumps(expectation, indent=2) + '\n')

    verify_result = subprocess.run(
        [str(VENV_PYTHON), str(RECORDER / 'verify.py'), '--capture', str(capture_dir), '--expect', str(expectation_path)],
        capture_output=True, text=True,
    )
    passed = verify_result.returncode == 0
    (case_dir / 'verify.json').write_text(json.dumps({
        'candidate': candidate, 'label': label, 'passed': passed,
        'failures': [] if passed else [(verify_result.stdout + verify_result.stderr).strip()],
    }, indent=2) + '\n')

    append_record(root, {'label': label, 'command': command, 'mode': mode, 'argv': child_argv,
                          'candidate': candidate, 'passed': passed})

    if not passed:
        fail(label, f'verify.py failed: {verify_result.stdout.strip()}\n{verify_result.stderr.strip()}')

    return passed


def pipe(
    state: Path,
    stage: str,
    label: str,
    candidate: str,
    argv: list[str],
    *,
    expected_exit: int,
    contains: list[str] | None = None,
    json_mode: bool = False,
    expect_stderr_empty: bool = True,
) -> bool:
    """Run one case with stdout/stderr redirected to plain, separate pipes
    (no PTY). Every Orbit CLI failure — JSON or human — renders through
    `$command->getOutput()`, which is the stdout channel; nothing is
    intentionally written to stderr. Assert that channel placement
    directly instead of only saving stderr for later."""
    root = stage_dir(state, stage)
    case_dir = root / label
    case_dir.mkdir(parents=True, exist_ok=True)

    argv = [*argv, '--json'] if json_mode and '--json' not in argv else argv
    child_argv = ['orbit', *argv]
    command = argv[0]
    mode = 'json' if json_mode else 'pipe-plain'
    # No PTY here, so Symfony's Terminal::getWidth() has no ioctl geometry to
    # read and falls back to 80 columns (its documented default) unless
    # COLUMNS is set — narrower than every `record()` capture in this file
    # (100 by default), so a table wraps a value here that never wraps
    # there. Match the human-mode width so pipe-plain reflects the same
    # reasonable terminal a real script-driven caller would run under.
    pipe_env = {**child_env(state), 'COLUMNS': '100'}
    result = subprocess.run(child_argv, capture_output=True, text=True, env=pipe_env, timeout=60)
    (case_dir / 'stdout.txt').write_text(result.stdout)
    (case_dir / 'stderr.txt').write_text(result.stderr)

    passed = result.returncode == expected_exit
    if not passed:
        fail(label, f'expected exit {expected_exit}, got {result.returncode}: {result.stderr.strip()}')

    if passed and '\x1b[' in result.stdout:
        passed = False
        fail(label, 'piped stdout contains an ANSI escape sequence')

    if passed and expect_stderr_empty and result.stderr.strip() != '':
        passed = False
        fail(label, f'expected an empty stderr channel, got: {result.stderr.strip()!r}')

    payload = None
    if passed and json_mode:
        try:
            payload = json.loads(result.stdout)
        except json.JSONDecodeError as exc:
            passed = False
            fail(label, f'stdout is not valid JSON: {exc}')

    if passed and contains:
        haystack = result.stdout if not json_mode else json.dumps(payload)
        for needle in contains:
            if needle not in haystack:
                passed = False
                fail(label, f'expected {needle!r} in piped output')
                break

    append_record(root, {
        'label': label, 'command': command, 'mode': mode, 'argv': child_argv, 'candidate': candidate,
        'exit': result.returncode, 'stderr_empty': result.stderr.strip() == '', 'passed': passed,
    })

    return passed


def orbit_list(state: Path) -> str:
    # Symfony's JSON list descriptor does not respect Command::isHidden(),
    # unlike its text descriptor (verified locally: `list --format=json`
    # includes herdr:* even with the extension disabled, `list` alone does
    # not), so the hidden/visible guard check must use the text format.
    result = subprocess.run(['orbit', 'list'], capture_output=True, text=True, env=child_env(state), timeout=30)
    return result.stdout


def stage_prepare(state: Path, candidate: str) -> None:
    state.mkdir(parents=True, exist_ok=True)
    source = Path(os.environ.get('ORBIT_HOME', '/home/orbit/.orbit'))
    destination = orbit_home(state)
    if destination.exists():
        shutil.rmtree(destination)
    destination.mkdir(parents=True)
    # `GatewayConfigLock::ensurePrivateDirectory()` (called on every
    # `LocalExtensionState` read, not just writes) requires this directory
    # itself to be private (0700, owned by the effective user) or every
    # `extension:*` call fails closed with `extension.config_invalid`.
    # `mkdir()` alone leaves it at the umask-derived default (typically
    # 0755); `shutil.copytree()` used to inherit the live home's own 0700
    # for free, which copying only two files does not.
    destination.chmod(0o700)

    # Only `config.json` (gateway profiles) and `extensions.json` (local
    # extension enable/disable state) are ever read from `config('orbit.home')`
    # by the CLI itself (AppServiceProvider.php) — copy just those two, not
    # the whole live home. The Gateway's own key material under this same
    # directory (`ssh/`, `ca/`, `wireguard/`, `gateway.app-key`,
    # `gateway.sqlite`) belongs to the co-located Gateway daemon, which reads
    # its own live `ORBIT_HOME` directly and never this frozen copy — proof
    # exports must not carry it.
    config_source = source / 'config.json'
    if not config_source.exists():
        fail('prepare', f'no gateway profile found at {config_source}')
        return
    shutil.copy2(config_source, destination / 'config.json')

    extensions_source = source / 'extensions.json'
    if extensions_source.exists():
        shutil.copy2(extensions_source, destination / 'extensions.json')

    result = subprocess.run(
        ['orbit', 'node:list', '--json'], capture_output=True, text=True, env=child_env(state), timeout=30,
    )
    if result.returncode != 0:
        fail('prepare', f'orbit node:list --json failed: {result.stderr.strip()}')
        return

    try:
        nodes = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        fail('prepare', f'orbit node:list --json did not return JSON: {exc}')
        return

    entries = nodes.get('nodes', nodes.get('data', []))
    matched = next((entry for entry in entries if entry.get('name') == NODE), None)
    if matched is None:
        fail('prepare', f'registered Node {NODE!r} not found among {[e.get("name") for e in entries]!r}')
        return

    node_id = matched.get('id')
    node_user = matched.get('user')
    node_ip = matched.get('wireguard_ip')
    if not isinstance(node_id, int) or not isinstance(node_user, str) or not isinstance(node_ip, str) or not node_ip:
        fail('prepare', f'Node {NODE!r} is missing id, user, or wireguard_ip: {matched!r}')
        return

    payload = {'candidate': candidate, 'node': NODE, 'node_id': node_id, 'node_user': node_user, 'node_ip': node_ip}
    state_write(state, 'prepare.json', payload)
    print(json.dumps({'stage': 'prepare', 'passed': True, 'node': NODE, 'node_id': node_id}), flush=True)


def prepared(state: Path) -> dict:
    return json.loads((state / 'prepare.json').read_text())


def stage_guard(state: Path, candidate: str) -> None:
    user = prepared(state)['node_user']
    commands = [
        ('herdr:session:create', ['commander-tasks', '--node', NODE, '--user', user]),
        ('herdr:session:adopt', ['commander-tasks', '--node', NODE, '--user', user]),
        ('herdr:session:list', ['--node', NODE]),
        ('herdr:session:show', ['commander-tasks', '--node', NODE]),
        ('herdr:session:restart', ['commander-tasks', '--node', NODE]),
        ('herdr:session:destroy', ['commander-tasks', '--node', NODE, '--yes']),
        ('herdr:observe', ['commander-tasks', '--node', NODE, '--pane', 'w1:p1', '--terminal', 'term-abc',
                            '--cols', '120', '--rows', '40', '--origin', 'https://tasks.commander.test']),
    ]

    # Disable first so the "hidden" assertions below hold regardless of
    # whatever state a fresh ORBIT_HOME happened to start in.
    disable_first = subprocess.run(
        ['orbit', 'extension:disable', 'herdr', '--json'], capture_output=True, text=True, env=child_env(state), timeout=30,
    )
    if disable_first.returncode != 0:
        fail('guard', f'extension:disable herdr failed before the guard check: {disable_first.stderr.strip()}')
        return

    listing_disabled = orbit_list(state)
    if 'herdr:' in listing_disabled:
        fail('guard', 'a herdr:* command is still visible in `orbit list --json` while the extension is disabled')

    for name, args in commands:
        pipe(state, 'guard', f'{name}-disabled', candidate, [name, *args],
             expected_exit=1, contains=['extension.disabled'], json_mode=True)

    record(state, 'guard', 'extension-enable-human', candidate, ['extension:enable', 'herdr'],
           decorated=True, contains=['herdr'], exit_code=0)

    listing_enabled = orbit_list(state)
    if 'herdr:session:create' not in listing_enabled:
        fail('guard', 'herdr:session:create is not visible in `orbit list --json` after the extension is enabled')

    pipe(state, 'guard', 'extension-list-json', candidate, ['extension:list'],
         expected_exit=0, contains=['herdr'], json_mode=True)

    record(state, 'guard', 'extension-disable-human', candidate, ['extension:disable', 'herdr'],
           decorated=True, contains=['herdr'], exit_code=0)

    listing_disabled_again = orbit_list(state)
    if 'herdr:' in listing_disabled_again:
        fail('guard', 'a herdr:* command is still visible in `orbit list --json` after disabling again')

    pipe(state, 'guard', 'herdr-session-list-disabled-again', candidate,
         ['herdr:session:list', '--node', NODE],
         expected_exit=1, contains=['extension.disabled'], json_mode=True)

    reenable = subprocess.run(
        ['orbit', 'extension:enable', 'herdr', '--json'], capture_output=True, text=True, env=child_env(state), timeout=30,
    )
    if reenable.returncode != 0:
        fail('guard', f'extension:enable herdr failed while restoring state for later stages: {reenable.stderr.strip()}')


def stage_tool_not_installed(state: Path, candidate: str) -> None:
    user = prepared(state)['node_user']
    pipe(state, 'tool-not-installed', 'create-json', candidate,
         ['herdr:session:create', 'commander-tasks', '--node', NODE, '--user', user],
         expected_exit=1, contains=['herdr.tool_not_installed'], json_mode=True)

    pipe(state, 'tool-not-installed', 'adopt-json', candidate,
         ['herdr:session:adopt', 'commander-tasks', '--node', NODE, '--user', user],
         expected_exit=1, contains=['herdr.tool_not_installed'], json_mode=True)

    record(state, 'tool-not-installed', 'create-human', candidate,
           ['herdr:session:create', 'commander-tasks', '--node', NODE, '--user', user],
           decorated=True, exit_code=1, contains=['not installed'])

    record(state, 'tool-not-installed', 'create-undecorated', candidate,
           ['herdr:session:create', 'commander-tasks', '--node', NODE, '--user', user],
           decorated=False, exit_code=1, contains=['not installed'])


def stage_unknown_session(state: Path, candidate: str) -> None:
    for name, args in [
        ('show', ['herdr:session:show', 'missing-session', '--node', NODE]),
        ('restart', ['herdr:session:restart', 'missing-session', '--node', NODE]),
        ('destroy', ['herdr:session:destroy', 'missing-session', '--node', NODE, '--yes']),
        ('observe', ['herdr:observe', 'missing-session', '--node', NODE, '--pane', 'w1:p1', '--terminal', 'term-abc',
                      '--cols', '120', '--rows', '40', '--origin', 'https://tasks.commander.test']),
    ]:
        pipe(state, 'unknown-session', f'{name}-json', candidate, args,
             expected_exit=1, contains=['herdr.session_not_found'], json_mode=True)

    pipe(state, 'unknown-session', 'list-empty-json', candidate, ['herdr:session:list', '--node', NODE],
         expected_exit=0, contains=['sessions'], json_mode=True)

    record(state, 'unknown-session', 'list-empty-human', candidate, ['herdr:session:list', '--node', NODE],
           decorated=True, exit_code=0, contains=['No matching records found.'])


def stage_invalid_input(state: Path, candidate: str) -> None:
    pipe(state, 'invalid-input', 'session-name-invalid-json', candidate,
         ['herdr:session:show', 'Not_Valid', '--node', NODE],
         expected_exit=1, contains=['herdr.session_invalid'], json_mode=True)

    pipe(state, 'invalid-input', 'observe-missing-pane-json', candidate,
         ['herdr:observe', 'commander-tasks', '--node', NODE, '--terminal', 'term-abc',
          '--cols', '120', '--rows', '40', '--origin', 'https://tasks.commander.test'],
         expected_exit=1, contains=['herdr.grant_invalid'], json_mode=True)

    pipe(state, 'invalid-input', 'observe-unsafe-origin-json', candidate,
         ['herdr:observe', 'commander-tasks', '--node', NODE, '--pane', 'w1:p1', '--terminal', 'term-abc',
          '--cols', '120', '--rows', '40', '--origin', 'http://tasks.commander.test'],
         expected_exit=1, contains=['herdr.grant_invalid'], json_mode=True)

    pipe(state, 'invalid-input', 'destroy-without-yes-json', candidate,
         ['herdr:session:destroy', 'missing-session', '--node', NODE],
         expected_exit=1, contains=['herdr.session_not_found'], json_mode=True)


def stage_install(state: Path, candidate: str) -> None:
    # Prerequisite setup for the live path, not ORB-360 command coverage
    # (R10): `tool:install` itself is ORB-359 scope. This stage is
    # deliberately excluded from EXPECTED_MODE_MATRIX and from
    # coverage-report's completeness check.
    info = prepared(state)
    node_id = info['node_id']

    record(state, 'install', 'tool-install-human', candidate,
           ['tool:install', 'herdr', '--node', str(node_id), '--manager', 'brew'],
           decorated=True, timeout=300, idle_timeout=120, exit_code=0, contains=['herdr'])

    exit_code, payload, stdout, stderr = cli_json(state, ['tool:list', '--node', str(node_id)], timeout=60)
    root = stage_dir(state, 'install')
    if exit_code != 0 or payload is None:
        fail('install', f'tool:list --node {node_id} --json failed: exit={exit_code} {stdout!r} {stderr!r}')
        return

    tools = payload.get('tools', payload.get('data', []))
    herdr_tool = next((t for t in tools if t.get('package') == 'herdr'), None)
    if herdr_tool is None:
        fail('install', f'no herdr Tool row found after install: {payload!r}')
        return

    installed = herdr_tool.get('status') == 'installed'
    save_raw_output(root, 'tool-status-json', stdout, stderr)
    append_record(root, {'label': 'tool-status-json', 'candidate': candidate,
                          'exit': exit_code, 'tool': herdr_tool, 'passed': installed})

    if not installed:
        fail('install', f'herdr Tool did not reach installed status: {herdr_tool!r}')


def stage_external_session(state: Path, candidate: str) -> None:
    ip, user = app_dev_target(state)

    exit_code, _, _ = remote(ip, user, f'test -e {shlex.quote(str(HERDR_BIN))}', timeout=15)
    if exit_code != 0:
        fail('external-session', f'{HERDR_BIN} is missing on app-dev; herdr install may not have completed on this Node')
        state_write(state, 'external-session.json', {'started': False})
        return

    unit = (
        '[Unit]\n'
        'Description=ORB-360 proof external Herdr session (not Orbit-managed)\n\n'
        '[Service]\n'
        'Type=simple\n'
        f'User={user}\n'
        f'ExecStart={HERDR_BIN} --session {EXTERNAL_SESSION} server\n'
        'Restart=on-failure\n'
        'RestartSec=2\n\n'
        '[Install]\n'
        'WantedBy=multi-user.target\n'
    )
    unit_path = '/etc/systemd/system/orb360-external-herdr.service'
    write_code, _, write_err = remote(ip, user, f'sudo tee {shlex.quote(unit_path)}', input_text=unit, timeout=15)
    if write_code != 0:
        fail('external-session', f'could not write {unit_path} on app-dev: {write_err.strip()}')
        state_write(state, 'external-session.json', {'started': False})
        return

    remote(ip, user, 'sudo systemctl daemon-reload', timeout=15)
    start_code, _, start_err = remote(ip, user, 'sudo systemctl start orb360-external-herdr.service', timeout=15)
    if start_code != 0:
        fail('external-session', f'systemctl start failed on app-dev: {start_err.strip()}')
        state_write(state, 'external-session.json', {'started': False})
        return

    for _ in range(15):
        status_code, status_out, _ = remote(ip, user, 'systemctl is-active orb360-external-herdr.service', timeout=15)
        if status_code == 0 and status_out.strip() == 'active':
            break
        time.sleep(1)
    else:
        fail('external-session', 'orb360-external-herdr.service did not reach the active state on app-dev')
        state_write(state, 'external-session.json', {'started': False})
        return

    state_write(state, 'external-session.json', {'started': True, 'session': EXTERNAL_SESSION, 'unit': unit_path, 'user': user})
    print(json.dumps({'stage': 'external-session', 'passed': True}), flush=True)


def stage_create(state: Path, candidate: str) -> None:
    info = prepared(state)
    user = info['node_user']
    root = stage_dir(state, 'create')
    args = ['herdr:session:create', MANAGED_SESSION, '--node', NODE, '--user', user, '--publish-observer']

    exit_code, payload, stdout, stderr = cli_json(state, args, timeout=120)
    if payload is None:
        fail('create', f'herdr:session:create --json did not return JSON: stdout={stdout!r} stderr={stderr!r}')
        return

    observer_unsupported = False
    if 'error' in payload:
        code = payload['error'].get('code')
        observer_unsupported = code == 'herdr.observer_unsupported'
        if not observer_unsupported:
            fail('create', f'herdr:session:create failed: {json.dumps(payload)}')
            save_raw_output(root, 'create-managed-json', stdout, stderr)
            append_record(root, {'label': 'create-managed-json', 'command': 'herdr:session:create', 'mode': 'json',
                                  'candidate': candidate, 'exit': exit_code, 'error_code': code, 'passed': False})
            return
    elif exit_code != 0:
        fail('create', f'herdr:session:create exited {exit_code} without an error envelope: {json.dumps(payload)}')
        return

    protocol = payload.get('protocol') if 'error' not in payload else None
    process_id = payload.get('process_id') if 'error' not in payload else None
    save_raw_output(root, 'create-managed-json', stdout, stderr)
    append_record(root, {'label': 'create-managed-json', 'command': 'herdr:session:create', 'mode': 'json',
                          'candidate': candidate, 'exit': exit_code, 'observer_unsupported': observer_unsupported,
                          'protocol': protocol, 'process_id': process_id, 'passed': True})

    expected_exit = 1 if observer_unsupported else 0
    expected_contains = ['herdr.observer_unsupported'] if observer_unsupported else [MANAGED_SESSION, NODE]

    # Create is idempotent for a compatible session, so repeating it for the
    # human and pipe captures below observes the same real result again
    # rather than mutating anything further. That also means every field
    # the earlier JSON call returned (`request_id` excepted: it is fresh on
    # every call) must reappear here, proving the detail tree keeps full
    # field parity with JSON, not just the session/Node spot check above.
    extra = None
    human_contains = expected_contains
    if not observer_unsupported:
        extra = progress_checks('Create Herdr session', 'Creating Herdr session', 'Created Herdr session')
        human_contains = [*expected_contains, *json_field_contains(payload, exclude={'request_id'})]

    record(state, 'create', 'create-managed-human', candidate, args,
           decorated=True, timeout=60, idle_timeout=30, exit_code=expected_exit, contains=human_contains,
           extra_expectation=extra)

    record(state, 'create', 'create-managed-undecorated', candidate, args,
           decorated=False, timeout=60, idle_timeout=30, exit_code=expected_exit, contains=expected_contains)

    pipe(state, 'create', 'create-managed-pipe-plain', candidate, args,
         expected_exit=expected_exit, contains=expected_contains, json_mode=False)


def probe_command(ip: str, user: str, argv: list[str]) -> tuple[int, str, str]:
    """Run one `herdr` invocation on app-dev over SSH, logged in as the
    session-owning user directly (no local `sudo -u`: the SSH login itself
    is already that user, unlike the old co-located design)."""
    command = ' '.join(shlex.quote(str(part)) for part in argv)
    return remote(ip, user, command, timeout=20)


def snapshot_panes(ip: str, user: str) -> list[dict]:
    exit_code, stdout, stderr = probe_command(ip, user, [str(HERDR_BIN), '--session', MANAGED_SESSION, 'api', 'snapshot'])
    if exit_code != 0:
        return []
    try:
        snapshot = json.loads(stdout)
    except json.JSONDecodeError:
        return []
    return snapshot.get('result', {}).get('snapshot', {}).get('panes', [])


def stage_discover_panes(state: Path, candidate: str) -> None:
    # Discovery here only feeds `live-pane-destroy`'s herdr.session_in_use
    # guard test: `herdr:observe` does not need a real pane (see module
    # docstring), so its stage does not depend on this one.
    ip, user = app_dev_target(state)

    debug = {'help': {}, 'attempts': []}
    for label, argv in [
        ('herdr-help', [str(HERDR_BIN), '--help']),
        ('herdr-session-help', [str(HERDR_BIN), '--session', MANAGED_SESSION, '--help']),
        ('herdr-session-api-help', [str(HERDR_BIN), '--session', MANAGED_SESSION, 'api', '--help']),
        # Real Herdr subcommands (confirmed via `herdr --help` on 0.8.2;
        # re-confirm on the installed 0.9.1 at rehearsal — the actual
        # syntax below follows Orbit's own `--session NAME SUBCOMMAND`
        # convention and is adjusted here if 0.9.1's --help differs).
        ('herdr-session-tab-help', [str(HERDR_BIN), '--session', MANAGED_SESSION, 'tab', '--help']),
        ('herdr-session-pane-help', [str(HERDR_BIN), '--session', MANAGED_SESSION, 'pane', '--help']),
        ('herdr-session-workspace-help', [str(HERDR_BIN), '--session', MANAGED_SESSION, 'workspace', '--help']),
    ]:
        exit_code, stdout, stderr = probe_command(ip, user, argv)
        debug['help'][label] = {'exit': exit_code, 'stdout': stdout[:4000], 'stderr': stderr[:2000]}

    # Poll first: the server may open a default pane asynchronously.
    panes: list[dict] = []
    for _ in range(10):
        panes = snapshot_panes(ip, user)
        if panes:
            break
        time.sleep(2)

    # Real Herdr 0.9.1 commands to open a pane, confirmed live against this
    # session (see discover-panes-debug.json's "attempts" from the first
    # rehearsal on a fresh session, before this sequence was corrected):
    # `tab create` alone fails with workspace_not_found ("no active
    # workspace") — a workspace must exist and be focused first. `pane
    # split` needs an explicit `--direction`/target, so it is not a
    # reliable zero-argument fallback either. The real sequence is
    # `workspace create` (creates and focuses one) then `tab create`
    # (succeeds now that a workspace is active, and a tab carries a
    # default pane). `pane list` stays as a final read-only diagnostic.
    if not panes:
        for argv, mutates in [
            ([str(HERDR_BIN), '--session', MANAGED_SESSION, 'workspace', 'create'], True),
            ([str(HERDR_BIN), '--session', MANAGED_SESSION, 'tab', 'create'], True),
            ([str(HERDR_BIN), '--session', MANAGED_SESSION, 'pane', 'list'], False),
        ]:
            exit_code, stdout, stderr = probe_command(ip, user, argv)
            debug['attempts'].append({'argv': argv, 'exit': exit_code, 'stdout': stdout[:1000], 'stderr': stderr[:1000]})
            if mutates and exit_code == 0:
                time.sleep(1)
                panes = snapshot_panes(ip, user)
                if panes:
                    break

    state_write(state, 'discover-panes-debug.json', debug)

    if not panes:
        state_write(state, 'panes.json', {
            'discovered': False,
            'reason': 'no panes reported by the session after polling and best-effort open attempts; '
                      'see discover-panes-debug.json for the real herdr --help output and attempted commands',
        })
        print(json.dumps({'stage': 'discover-panes', 'passed': True, 'panes': 0, 'discovered': False}), flush=True)
        return

    pane = panes[0]
    state_write(state, 'panes.json', {'discovered': True, 'pane': pane.get('pane_id'), 'terminal': pane.get('terminal_id')})
    print(json.dumps({'stage': 'discover-panes', 'passed': True, 'panes': len(panes)}), flush=True)


def stage_observe(state: Path, candidate: str) -> None:
    panes = state_read(state, 'panes.json')
    if panes is None:
        fail('observe', 'panes.json was never written by discover-panes')
        return

    # Use the real discovered pane/terminal when available for a more
    # representative capture; a well-formed placeholder otherwise, since
    # the Gateway signs the grant without validating pane existence.
    if panes.get('discovered'):
        pane, terminal = panes['pane'], panes['terminal']
    else:
        pane, terminal = 'w1:p1', 'term-abc'

    common = ['--node', NODE, '--cols', '120', '--rows', '40', '--origin', 'https://tasks.commander.test']
    args = ['herdr:observe', MANAGED_SESSION, *common, '--pane', pane, '--terminal', terminal]

    pipe(state, 'observe', 'observe-real-pane-json', candidate, args,
         expected_exit=0, contains=['observer_url'], json_mode=True)

    # Issuing a grant is comparably light server work to adopt (measured
    # 0.240s active on the real proof topology, attempt d785c200) —
    # `record()` measures this itself from the real capture and only
    # requires animation_rows if that is still true here. But like
    # destroy, observe resolves the session read-only before the
    # progress-wrapped grant request, adding a real round trip before the
    # first progress frame (rehearsal on discovery `6bc9efbe` measured this
    # exceeding the 2s default) — same bound as restart/destroy.
    #
    # Every grant is freshly signed, so `nonce`, `expires_at`, and the
    # token embedded in `observer_url` cannot be pinned to one call's JSON
    # value the way create/adopt/show/restart's stable session fields can
    # (HerdrSessionCommandsTest's Pest test asserts full value equality
    # against one specific call's own JSON). Here, assert the detail
    # tree's shape for the fields it still carries (`observer_url` prints
    # on its own unprefixed line below the tree instead, per the
    # orchestrator's copy-paste finding on Gate A) plus the fields whose
    # value this call's own arguments fix in advance.
    record(state, 'observe', 'observe-real-pane-human', candidate, args,
           decorated=True, timeout=30, exit_code=0,
           contains=['Observation grant', 'Pane', 'Terminal', 'Scope', 'Columns', 'Rows', 'Expires',
                     'Nonce', 'Request ID', pane, terminal, 'terminal.observe', '120', '40', 'wss://'],
           extra_expectation=progress_checks('Issue observation grant', 'Issuing observation grant', 'Issued observation grant',
                                              max_first_output_seconds=5.0))

    record(state, 'observe', 'observe-real-pane-undecorated', candidate, args,
           decorated=False, timeout=30, exit_code=0, contains=['Observation grant', pane])

    pipe(state, 'observe', 'observe-real-pane-pipe-plain', candidate, args,
         expected_exit=0, contains=[pane], json_mode=False)


def stage_adopt(state: Path, candidate: str) -> None:
    info = prepared(state)
    user = info['node_user']
    external = state_read(state, 'external-session.json')
    if external is None or not external.get('started'):
        fail('adopt', 'external-session.json missing or the external Herdr service never started')
        return

    root = stage_dir(state, 'adopt')
    args = ['herdr:session:adopt', EXTERNAL_SESSION, '--node', NODE, '--user', user, '--publish-observer']
    exit_code, payload, stdout, stderr = cli_json(state, args, timeout=60)
    if payload is None:
        fail('adopt', f'herdr:session:adopt --json did not return JSON: {stdout!r} {stderr!r}')
        return

    observer_unsupported = 'error' in payload and payload['error'].get('code') == 'herdr.observer_unsupported'
    if 'error' in payload and not observer_unsupported:
        fail('adopt', f'herdr:session:adopt failed: {json.dumps(payload)}')
        save_raw_output(root, 'adopt-external-json', stdout, stderr)
        append_record(root, {'label': 'adopt-external-json', 'command': 'herdr:session:adopt', 'mode': 'json',
                              'candidate': candidate, 'exit': exit_code, 'passed': False})
        return

    if 'error' not in payload and payload.get('management') != 'external':
        fail('adopt', f'adopted session reported management={payload.get("management")!r}, expected "external"')

    save_raw_output(root, 'adopt-external-json', stdout, stderr)
    append_record(root, {'label': 'adopt-external-json', 'command': 'herdr:session:adopt', 'mode': 'json',
                          'candidate': candidate, 'exit': exit_code, 'observer_unsupported': observer_unsupported,
                          'passed': True})

    expected_exit = 1 if observer_unsupported else 0
    expected_contains = ['herdr.observer_unsupported'] if observer_unsupported else [EXTERNAL_SESSION, 'adopted']
    # Adopting an already-running external session is lighter server work
    # than create/restart/destroy: measured 0.51s total, 0.24s running, one
    # glyph value throughout on the real proof topology (attempt b88d983e)
    # — genuinely sub-second, not a flake. `record()` measures this itself
    # from the real capture and skips animation_rows only if it is still
    # true here; see `measure_active_seconds()`.
    extra = None if observer_unsupported else progress_checks(
        'Adopt Herdr session', 'Adopting Herdr session', 'Adopted Herdr session',
    )
    # Repeat-adopting the same external session for this decorated capture
    # observes the same real record again (like create's idempotency
    # above), so every field the JSON call above returned (`request_id`
    # excepted) must reappear here too.
    human_contains = expected_contains if observer_unsupported else [
        *expected_contains, *json_field_contains(payload, exclude={'request_id'}),
    ]

    record(state, 'adopt', 'adopt-external-human', candidate, args,
           decorated=True, timeout=60, exit_code=expected_exit, contains=human_contains, extra_expectation=extra)

    record(state, 'adopt', 'adopt-external-undecorated', candidate, args,
           decorated=False, timeout=60, exit_code=expected_exit, contains=expected_contains)

    pipe(state, 'adopt', 'adopt-external-pipe-plain', candidate, args,
         expected_exit=expected_exit, contains=expected_contains, json_mode=False)


def stage_list_populated(state: Path, candidate: str) -> None:
    exit_code, payload, stdout, stderr = cli_json(state, ['herdr:session:list', '--node', NODE], timeout=30)
    if payload is None or exit_code != 0:
        fail('list-populated', f'herdr:session:list --json failed: exit={exit_code} {stdout!r} {stderr!r}')
        return

    sessions = payload.get('sessions', payload.get('data', []))
    names = {entry.get('session') for entry in sessions}
    expected = {MANAGED_SESSION, EXTERNAL_SESSION}
    passed = expected.issubset(names)
    if not passed:
        fail('list-populated', f'expected sessions {expected} in the list, got {names}')

    args = ['herdr:session:list', '--node', NODE]
    list_root = stage_dir(state, 'list-populated')
    save_raw_output(list_root, 'list-json', stdout, stderr)
    append_record(list_root, {
        'label': 'list-json', 'command': 'herdr:session:list', 'mode': 'json', 'candidate': candidate,
        'exit': exit_code, 'sessions': sorted(names), 'passed': passed,
    })

    record(state, 'list-populated', 'list-human', candidate, args,
           decorated=True, exit_code=0, contains=[MANAGED_SESSION, EXTERNAL_SESSION],
           extra_expectation=progress_checks('List Herdr sessions', 'Listing Herdr sessions', 'Listed Herdr sessions'))

    record(state, 'list-populated', 'list-undecorated', candidate, args,
           decorated=False, exit_code=0, contains=[MANAGED_SESSION, EXTERNAL_SESSION])

    pipe(state, 'list-populated', 'list-pipe-plain', candidate, args,
         expected_exit=0, contains=[MANAGED_SESSION, EXTERNAL_SESSION], json_mode=False)


def stage_show(state: Path, candidate: str) -> None:
    args = ['herdr:session:show', MANAGED_SESSION, '--node', NODE]
    pipe(state, 'show', 'show-managed-json', candidate, args,
         expected_exit=0, contains=[MANAGED_SESSION, 'health'], json_mode=True)

    # A second, separate read-only show (its own request_id, everything
    # else about the session unchanged) supplies the full field set for the
    # decorated capture's coverage check below.
    show_exit, show_payload, show_stdout, show_stderr = cli_json(state, args, timeout=30)
    if show_payload is None or show_exit != 0:
        fail('show', f'herdr:session:show --json (coverage fetch) failed: exit={show_exit} {show_stdout!r} {show_stderr!r}')
        return

    # Show resolves the session read-only before the progress-wrapped
    # request too (same pattern as observe/restart/destroy), so it needs
    # the same bound past the 2s default.
    record(state, 'show', 'show-managed-human', candidate, args,
           decorated=True, exit_code=0,
           contains=[MANAGED_SESSION, NODE, 'Management', 'managed',
                     *json_field_contains(show_payload, exclude={'request_id'})],
           extra_expectation=progress_checks('Show Herdr session', 'Loading Herdr session', 'Loaded Herdr session',
                                              max_first_output_seconds=5.0))

    record(state, 'show', 'show-managed-undecorated', candidate, args,
           decorated=False, exit_code=0, contains=[MANAGED_SESSION, NODE, 'Management', 'managed'])

    pipe(state, 'show', 'show-managed-pipe-plain', candidate, args,
         expected_exit=0, contains=[MANAGED_SESSION], json_mode=False)

    # Narrow-width detail tree (R5): below the table minimum-width
    # threshold, the shared renderer falls back to a plain labeled record.
    record(state, 'show', 'show-managed-narrow', candidate, args,
           decorated=True, columns=40, exit_code=0, contains=[MANAGED_SESSION])


def stage_restart(state: Path, candidate: str) -> None:
    args = ['herdr:session:restart', MANAGED_SESSION, '--node', NODE]

    pipe(state, 'restart', 'restart-no-handoff-json', candidate, args,
         expected_exit=0, contains=[MANAGED_SESSION], json_mode=True)

    # Restart genuinely stops the running Herdr process before starting a
    # fresh one, so its first frame takes longer to render than create's or
    # destroy's single-step operations — measured ~2.5-2.7s live, comfortably
    # under a 5s bound but over the 2s default.
    extra = progress_checks('Restart Herdr session', 'Restarting Herdr session', 'Restarted Herdr session',
                             max_first_output_seconds=5.0)

    # A plain read-only show, once the restart above has settled, supplies
    # the full field set for the decorated capture's coverage check below
    # without restarting the session again just to inspect it.
    show_exit, show_payload, show_stdout, show_stderr = cli_json(
        state, ['herdr:session:show', MANAGED_SESSION, '--node', NODE], timeout=30,
    )
    if show_payload is None or show_exit != 0:
        fail('restart', f'herdr:session:show --json (coverage fetch) failed: exit={show_exit} {show_stdout!r} {show_stderr!r}')
        return
    human_contains = [MANAGED_SESSION, *json_field_contains(show_payload, exclude={'request_id'})]

    record(state, 'restart', 'restart-no-handoff-human', candidate, args,
           decorated=True, timeout=90, exit_code=0, contains=human_contains, extra_expectation=extra)

    record(state, 'restart', 'restart-handoff-human', candidate, [*args, '--handoff'],
           decorated=True, timeout=90, exit_code=0, contains=[MANAGED_SESSION], extra_expectation=extra)

    record(state, 'restart', 'restart-undecorated', candidate, args,
           decorated=False, timeout=90, exit_code=0, contains=[MANAGED_SESSION])

    pipe(state, 'restart', 'restart-pipe-plain', candidate, args,
         expected_exit=0, contains=[MANAGED_SESSION], json_mode=False)


def stage_consent(state: Path, candidate: str) -> None:
    info = prepared(state)
    user = info['node_user']

    def create_disposable(session: str) -> bool:
        exit_code, payload, stdout, stderr = cli_json(state, [
            'herdr:session:create', session, '--node', NODE, '--user', user,
        ], timeout=90)
        ok = payload is not None and ('error' not in payload or payload['error'].get('code') == 'herdr.observer_unsupported')
        if not ok:
            fail('consent', f'could not create disposable session {session!r}: exit={exit_code} {stdout!r} {stderr!r}')
        return ok

    # --- Decline sequence on a disposable session: no case here mutates. ---
    if not create_disposable(CONSENT_DECLINE_SESSION):
        return

    destroy_args = ['herdr:session:destroy', CONSENT_DECLINE_SESSION, '--node', NODE]

    pipe(state, 'consent', 'destroy-automation-no-yes-json', candidate, destroy_args,
         expected_exit=1, contains=['input.confirmation_required'], json_mode=True)

    record(state, 'consent', 'destroy-default-no', candidate, destroy_args,
           decorated=True, timeout=30, input_plan=[{'wait_for': 'No', 'send': '\r'}],
           exit_code=1, contains=['cancelled'])

    record(state, 'consent', 'destroy-default-no-narrow', candidate, destroy_args,
           decorated=True, columns=40, timeout=30, input_plan=[{'wait_for': 'No', 'send': '\r'}],
           exit_code=1, contains=['cancelled'])

    record(state, 'consent', 'destroy-ctrl-c', candidate, destroy_args,
           decorated=True, timeout=30, input_plan=[{'wait_for': 'No', 'send': '\x03'}],
           exit_code=1, contains=['cancelled'])

    record(state, 'consent', 'destroy-eof', candidate, destroy_args,
           decorated=True, timeout=30, input_plan=[{'wait_for': 'No', 'send': '\x04'}],
           exit_code=1, contains=['cancelled'])

    still_there_exit, still_there_payload, _, _ = cli_json(
        state, ['herdr:session:show', CONSENT_DECLINE_SESSION, '--node', NODE], timeout=30,
    )
    if still_there_exit != 0 or still_there_payload is None or 'error' in still_there_payload:
        fail('consent', f'the consent session did not survive the declined destroy attempts: {still_there_payload!r}')

    # R9: after the declined destroy cases, prove the guest Process, not
    # only Orbit's own record of it, was left alone. This must run here,
    # inline, and not as a later separate stage: the real destroy a few
    # lines below legitimately stops this same session's Process, so a
    # later stage checking the same unit would only ever see it correctly
    # stopped and could never actually prove the declines were harmless.
    decline_process_id = still_there_payload.get('process_id') if still_there_payload else None
    if decline_process_id is None:
        fail('consent', f'consent-decline session has no process_id to check on the guest: {still_there_payload!r}')
    else:
        ip, node_user = app_dev_target(state)
        unit = f'orbit-process-{decline_process_id}-herdr-{CONSENT_DECLINE_SESSION}.service'
        status_code, status_out, status_err = remote(ip, node_user, f'systemctl is-active {shlex.quote(unit)}', timeout=15)
        active = status_code == 0 and status_out.strip() == 'active'
        state_write(state, 'consent-unchanged.json', {'unit': unit, 'active': active})
        if not active:
            fail('consent', f'{unit} is not active on app-dev after the declined destroy attempts: {status_out!r} {status_err!r}')

    pipe(state, 'consent', 'destroy-yes-json', candidate, [*destroy_args, '--yes'],
         expected_exit=0, contains=[CONSENT_DECLINE_SESSION, 'request_id'], json_mode=True)

    gone_exit, gone_payload, _, _ = cli_json(state, ['herdr:session:show', CONSENT_DECLINE_SESSION, '--node', NODE], timeout=30)
    gone_code = gone_payload.get('error', {}).get('code') if gone_payload else None
    if gone_exit == 0 or gone_code != 'herdr.session_not_found':
        fail('consent', f'the consent session was not actually removed: exit={gone_exit} {gone_payload!r}')

    # --- Interactive accept on its own disposable session (R2). ---
    if create_disposable(CONSENT_ACCEPT_SESSION):
        accept_args = ['herdr:session:destroy', CONSENT_ACCEPT_SESSION, '--node', NODE]
        # Laravel Prompts' ConfirmPrompt toggles on 'y' and only submits on a
        # separate ENTER keystroke (vendor/laravel/prompts/src/ConfirmPrompt.php)
        # — sending both bytes in one `send` risks the terminal delivering
        # them in a single read before the raw-mode key reader can process
        # 'y', losing the toggle (observed live: it submitted the untouched
        # default "No" and hung afterward). Split into two steps: the box
        # fully redraws on every keypress and still contains "No" in either
        # toggle state (Themes/Default/ConfirmPromptRenderer.php), so waiting
        # for a second, fresh "No" after sending 'y' waits for that redraw
        # before sending the real ENTER.
        record(state, 'consent', 'destroy-interactive-accept', candidate, accept_args,
               decorated=True, timeout=30,
               input_plan=[{'wait_for': 'No', 'send': 'y'}, {'wait_for': 'No', 'send': '\r'}],
               exit_code=0, contains=['removed from Orbit'])

        accept_gone_exit, accept_gone_payload, _, _ = cli_json(
            state, ['herdr:session:show', CONSENT_ACCEPT_SESSION, '--node', NODE], timeout=30,
        )
        accept_gone_code = accept_gone_payload.get('error', {}).get('code') if accept_gone_payload else None
        if accept_gone_exit == 0 or accept_gone_code != 'herdr.session_not_found':
            fail('consent', f'the interactive-accept session was not actually removed: {accept_gone_payload!r}')

    # --- Human --yes success, with liveness checks (R2 + R4 + R5). ---
    if create_disposable(CONSENT_HUMAN_YES_SESSION):
        yes_args = ['herdr:session:destroy', CONSENT_HUMAN_YES_SESSION, '--node', NODE, '--yes']
        # Destroy resolves the session read-only before the destructive
        # operation even with --yes (contract: "Resolve the session
        # (read-only) before asking"), adding a real round trip before the
        # first progress frame — measured ~3.2s live, same as restart's
        # two-step latency; see that stage for the same bound.
        extra = progress_checks('Destroy Herdr session', 'Destroying Herdr session', 'Destroyed Herdr session',
                                 max_first_output_seconds=5.0)
        # F2: the removed record's tree shows only what stays true after
        # removal (ID, Session, Node, Node ID, User, Management, Request
        # ID) — assert that reduced shape directly, and that the stale
        # live-status fields the pre-removal Gateway response still
        # carries in JSON do not leak into human output.
        record(state, 'consent', 'destroy-yes-human', candidate, yes_args,
               decorated=True, timeout=30, exit_code=0,
               contains=['removed from Orbit', CONSENT_HUMAN_YES_SESSION, NODE, 'Management', 'Request ID'],
               absent=['Status', 'Process health', 'Listener health', 'Session health', 'Herdr version', 'Protocol'],
               extra_expectation=extra)

    # --- pipe-plain destroy success (R5 mode-matrix gap). ---
    if create_disposable(CONSENT_PIPE_SESSION):
        pipe_args = ['herdr:session:destroy', CONSENT_PIPE_SESSION, '--node', NODE, '--yes']
        pipe(state, 'consent', 'destroy-yes-pipe-plain', candidate, pipe_args,
             expected_exit=0, contains=[CONSENT_PIPE_SESSION], json_mode=False)


def stage_live_pane_destroy(state: Path, candidate: str) -> None:
    root = stage_dir(state, 'live-pane-destroy')
    panes = state_read(state, 'panes.json')

    if panes is None or not panes.get('discovered'):
        not_proven(root, 'destroy-blocked-json', 'herdr:session:destroy', 'json',
                    'no live pane was discovered, so herdr.session_in_use cannot be proven for real; '
                    'falling back to a plain successful destroy of the managed session below')
        args = ['herdr:session:destroy', MANAGED_SESSION, '--node', NODE, '--yes']
        pipe(state, 'live-pane-destroy', 'destroy-no-live-pane-json', candidate, args,
             expected_exit=0, json_mode=True)
        return

    args_no_accept = ['herdr:session:destroy', MANAGED_SESSION, '--node', NODE, '--yes']
    exit_code, payload, stdout, stderr = cli_json(state, args_no_accept, timeout=60)
    code = payload.get('error', {}).get('code') if payload else None

    if code != 'herdr.session_in_use':
        fail('live-pane-destroy',
             f'a live pane was discovered, so destroy without --accept-termination must fail with '
             f'herdr.session_in_use; got exit={exit_code} code={code!r} payload={payload!r}')
        save_raw_output(root, 'destroy-blocked-json', stdout, stderr)
        append_record(root, {'label': 'destroy-blocked-json', 'command': 'herdr:session:destroy', 'mode': 'json',
                              'candidate': candidate, 'exit': exit_code, 'error_code': code, 'passed': False})
        return

    save_raw_output(root, 'destroy-blocked-json', stdout, stderr)
    append_record(root, {'label': 'destroy-blocked-json', 'command': 'herdr:session:destroy', 'mode': 'json',
                          'candidate': candidate, 'exit': exit_code, 'error_code': code, 'passed': True})

    args_accept = [*args_no_accept, '--accept-termination']
    pipe(state, 'live-pane-destroy', 'destroy-accept-termination-json', candidate, args_accept,
         expected_exit=0, contains=[MANAGED_SESSION, 'request_id'], json_mode=True)


def stage_cleanup(state: Path, candidate: str) -> None:
    destroy_args = ['herdr:session:destroy', EXTERNAL_SESSION, '--node', NODE, '--yes']
    record(state, 'cleanup', 'destroy-adopted-undecorated', candidate, destroy_args,
           decorated=False, exit_code=0, contains=['removed from Orbit'])
    state_write(state, 'adopted-destroyed.json', {'destroyed': True})


def stage_verify_external_survives(state: Path, candidate: str) -> None:
    destroyed = state_read(state, 'adopted-destroyed.json')
    if destroyed is None or not destroyed.get('destroyed'):
        fail('verify-external-survives', 'cleanup did not record destroying the adopted session before this check ran')
        return

    ip, user = app_dev_target(state)
    status_code, status_out, status_err = remote(ip, user, 'systemctl is-active orb360-external-herdr.service', timeout=15)
    survived = status_code == 0 and status_out.strip() == 'active'
    state_write(state, 'external-survived.json', {'survived': survived, 'systemctl_output': status_out.strip()})
    if not survived:
        fail('verify-external-survives', f'external Herdr service is not active on app-dev after Orbit destroyed the adopted session: {status_out!r} {status_err!r}')
    print(json.dumps({'stage': 'verify-external-survives', 'passed': survived}), flush=True)


COVERAGE_STAGES = [
    'prepare', 'guard', 'tool-not-installed', 'unknown-session', 'invalid-input',
    'create', 'observe', 'adopt', 'list-populated', 'show', 'restart', 'consent',
    'live-pane-destroy', 'cleanup', 'verify-external-survives',
]


def stage_coverage_report(state: Path, candidate: str) -> None:
    matrix: dict[str, dict[str, list[dict]]] = {command: {mode: [] for mode in MODES} for command in EXPECTED_MODE_MATRIX}
    not_proven_entries: list[dict] = []
    stage_summary: dict[str, dict] = {}
    complete = True

    # `prepare` and `verify-external-survives` never call append_record():
    # they are pure guest-state checks with no (command, mode) cases of
    # their own, so an empty records.json is their normal, passing shape —
    # not evidence of failure. `verify-external-survives`'s real result is
    # independently checked below via external-survived.json.
    no_records_stages = ('prepare', 'verify-external-survives')

    for stage in COVERAGE_STAGES:
        records_path = state / stage / 'records.json'
        records = json.loads(records_path.read_text()) if records_path.exists() else []
        stage_passed = all(entry.get('passed') for entry in records) if records else stage in no_records_stages
        stage_summary[stage] = {'cases': len(records), 'passed': stage_passed}
        complete = complete and stage_passed

        for entry in records:
            if entry.get('not_proven'):
                not_proven_entries.append({'stage': stage, **entry})
                continue
            command = entry.get('command')
            mode = entry.get('mode')
            if command in matrix and mode in matrix[command] and entry.get('passed'):
                matrix[command][mode].append({'stage': stage, 'label': entry.get('label')})

    missing_cells = [
        f'{command} / {mode}'
        for command, modes in matrix.items()
        for mode in MODES
        if not modes[mode]
    ]
    if missing_cells:
        complete = False

    external_survived = state_read(state, 'external-survived.json')
    complete = complete and bool(external_survived and external_survived.get('survived'))

    consent_unchanged = state_read(state, 'consent-unchanged.json')
    complete = complete and bool(consent_unchanged and consent_unchanged.get('active'))

    if not_proven_entries:
        complete = False

    aggregate = {
        'candidate': candidate,
        'stages': stage_summary,
        'mode_matrix': {command: {mode: len(cases) for mode, cases in modes.items()} for command, modes in matrix.items()},
        'missing_cells': missing_cells,
        'not_proven': not_proven_entries,
        'external_survived': external_survived,
        'consent_unchanged': consent_unchanged,
        'complete': complete,
    }
    (state / 'coverage-aggregate.json').write_text(json.dumps(aggregate, indent=2) + '\n')

    if not complete:
        fail('coverage-report', f'not every command x mode cell was proven passing: {json.dumps(aggregate)}')


STAGES = {
    'prepare': stage_prepare,
    'guard': stage_guard,
    'tool-not-installed': stage_tool_not_installed,
    'unknown-session': stage_unknown_session,
    'invalid-input': stage_invalid_input,
    'install': stage_install,
    'external-session': stage_external_session,
    'create': stage_create,
    'discover-panes': stage_discover_panes,
    'observe': stage_observe,
    'adopt': stage_adopt,
    'list-populated': stage_list_populated,
    'show': stage_show,
    'restart': stage_restart,
    'consent': stage_consent,
    'live-pane-destroy': stage_live_pane_destroy,
    'cleanup': stage_cleanup,
    'verify-external-survives': stage_verify_external_survives,
    'coverage-report': stage_coverage_report,
}

# Mirrors ORB-360.json's acceptance order: every action runs on `gateway`
# (app-dev-side work goes over SSH from within the relevant stage), so a
# --rehearsal run just visits every stage in plan order on one guest.
STAGE_ORDER = list(STAGES)


def rehearse(state: Path, candidate: str) -> int:
    """Dry-run every stage in plan order, within one process on an
    already-acquired discovery guest — cheap iteration before spending a
    real `prove` attempt. Stops at the first stage with real failures, same
    as `prove`'s first-nonzero-exit rule; keeps going past `not_proven`
    entries since those do not fail their action by design."""
    results = []
    for stage in STAGE_ORDER:
        FAILURES.clear()
        STAGES[stage](state, candidate)
        passed = not FAILURES
        results.append({'stage': stage, 'passed': passed, 'failures': list(FAILURES)})
        print(json.dumps(results[-1]), flush=True)
        if not passed:
            break

    overall = all(r['passed'] for r in results)
    print(json.dumps({'rehearsal': True, 'passed': overall, 'stages': results}), flush=True)
    return 0 if overall else 1


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--state', required=True, type=Path)
    parser.add_argument('--stage', choices=sorted(STAGES))
    parser.add_argument('--rehearsal', action='store_true',
                         help='Dry-run every stage in plan order on an already-acquired discovery '
                              'guest instead of running exactly one --stage under `prove`.')
    args = parser.parse_args()

    if args.rehearsal:
        return rehearse(args.state, args.candidate)

    if args.stage is None:
        parser.error('--stage is required unless --rehearsal is set')

    STAGES[args.stage](args.state, args.candidate)

    if FAILURES:
        print(json.dumps({'stage': args.stage, 'passed': False, 'failures': FAILURES}), flush=True)
        return 1

    print(json.dumps({'stage': args.stage, 'passed': True}), flush=True)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
