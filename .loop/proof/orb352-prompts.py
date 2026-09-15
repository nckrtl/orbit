#!/usr/bin/env python3
"""Candidate-bound automated PTY prompt proof. Solo keyboard review is separate."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import secrets
import shutil
import subprocess
import sys
import termios


SOURCE = Path('/home/orbit/orbit')
FIXTURE = SOURCE / 'apps/cli/tests/Fixtures/Console/prompt-fixture.php'
SKILL = SOURCE / '.agents/skills/verifying-cli-output/scripts'
SELF = Path(__file__).resolve()


def save(path, value):
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')


def digest(path, root):
    return {'path': str(path.relative_to(root)), 'size': path.stat().st_size,
            'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def tty_state(fd):
    state = termios.tcgetattr(fd)
    state[6] = [value.hex() if isinstance(value, bytes) else value for value in state[6]]
    return state


def child(arguments):
    """Keep the fixture's native terminal cleanup distinct from collector cleanup."""
    scenario, marker, mode, runtime_path, redirect = arguments
    php = shutil.which('php')
    require(php is not None, 'PHP launcher is unavailable')
    stdin = open(os.devnull, 'rb') if redirect == 'yes' else None
    runtime = {
        'launcher': str(Path(php).resolve()),
        'argv': [php, str(FIXTURE), scenario, marker, mode],
        'cwd': str(SOURCE),
        'fixture_stdin_tty': os.isatty(stdin.fileno() if stdin else 0),
        'fixture_stdout_tty': os.isatty(1),
        'fixture_stderr_tty': os.isatty(2),
        'stty_before': tty_state(0),
        'input_source': {'eof': 'closed Unix socket peer',
                         'read-failure': 'closed stream resource'}.get(
                             scenario, 'devnull' if stdin else 'PTY'),
    }
    code = 125
    try:
        code = subprocess.run(runtime['argv'], cwd=SOURCE, stdin=stdin, check=False).returncode
        runtime['child_exit_code'] = code
        return code
    finally:
        runtime['stty_after'] = tty_state(0)
        save(Path(runtime_path), runtime)
        if stdin:
            stdin.close()


def action(wait_for, send):
    return {'wait_for': wait_for, 'send': send}


def cases():
    text = [action('Fixture name', 'bad\r'),
            action('Enter accepted.', '\x7f\x7f\x7faccepted\r')]
    yield {'name': 'text-validation', 'scenario': 'text', 'input': text,
           'result': 'accepted', 'contains': ['Enter accepted.']}
    yield {'name': 'suggest-open-value', 'scenario': 'suggest',
           'input': [action('Fixture name', 'outside-suggestions\r')],
           'result': 'outside-suggestions'}
    yield {'name': 'datatable-filter-string-key', 'scenario': 'select', 'result': 'record-beta',
           'contains': ['Press / to search', 'ID', 'Name', 'Beta'],
           'input': [action('Press / to search', '/'), action('▏', 'Beta'),
                     action('Beta▏', '\r'), action('/ Beta', '\r')]}
    yield {'name': 'datatable-empty-search-clear-navigation', 'scenario': 'select', 'result': 94,
           'contains': ['No matching records found.', 'Alpha', 'Gamma', 'Press / to search'],
           'input': [action('Press / to search', '/'), action('▏', 'absent'),
                     action('No matching records found.', '\r'),
                     action('No matching records found.', '/'), action('▏', '\x1b'),
                     action('Press / to search', '\x1b[B'),
                     action('Press / to search', '\x1b[B'),
                     action('Press / to search', '\r')]}
    yield {'name': 'empty-selector', 'scenario': 'empty', 'abort': 'empty_selection',
           'absent': ['Choose fixture record']}
    for name, key in [('ctrl-c', '\x03'), ('ctrl-d', '\x04')]:
        yield {'name': name, 'scenario': 'cancel', 'abort': 'cancelled',
               'input': [action('Fixture name', 'typed' + key + '\r')]}
    yield {'name': 'genuine-stream-eof', 'scenario': 'eof', 'abort': 'eof'}
    yield {'name': 'closed-resource-read-failure', 'scenario': 'read-failure', 'abort': 'read_failed'}
    for mode in ['auto', 'plain']:
        yield {'name': 'password-' + mode, 'scenario': 'password', 'mode': mode,
               'password': True, 'result': 'submitted'}
    for mode in ['machine', 'no-interaction']:
        yield {'name': 'forbidden-' + mode, 'scenario': 'cancel', 'mode': mode,
               'abort': 'interaction_forbidden', 'absent': ['Fixture name', 'unsafe-default']}
    yield {'name': 'forbidden-piped-stdin', 'scenario': 'cancel', 'piped_stdin': True,
           'abort': 'interaction_forbidden', 'absent': ['Fixture name', 'unsafe-default']}
    yield {'name': 'plain-text-validation', 'scenario': 'text', 'mode': 'plain',
           'input': text, 'result': 'accepted', 'contains': ['Enter accepted.']}
    yield {'name': 'plain-cancel', 'scenario': 'cancel', 'mode': 'plain', 'abort': 'cancelled',
           'input': [action('Fixture name', '\x03')]}


def run_case(root, candidate, spec, environment):
    directory = root / spec['name']
    directory.mkdir(mode=0o700)
    recording = directory / 'capture'
    marker = directory / 'post-prompt-marker.json'
    runtime_path = directory / 'runtime.json'
    mode = spec.get('mode', 'auto')
    expected_exit = 20 if 'abort' in spec else 0
    secret = 'disposable-' + secrets.token_hex(12) if spec.get('password') else None
    inputs = [action('Fixture password', secret + '\r')] if secret else spec.get('input', [])
    input_path = directory / ('transient-private-input.json' if secret else 'input.json')
    command = [sys.executable, str(SELF), '--child', spec['scenario'], str(marker), mode,
               str(runtime_path), 'yes' if spec.get('piped_stdin') else 'no']
    capture_command = [sys.executable, str(SKILL / 'capture.py'), '--candidate', candidate,
                       '--label', spec['name'], '--output-dir', str(recording),
                       '--columns', '80', '--rows', '24', '--timeout', '15',
                       '--idle-timeout', '5', '--no-live']
    if inputs:
        save(input_path, inputs)
        input_path.chmod(0o600)
        capture_command += ['--input-plan', str(input_path)]
    capture_command += ['--', *command]
    definition = {
        'candidate': candidate, 'label': spec['name'], 'capture_argv': capture_command,
        'fixture_scenario': spec['scenario'], 'fixture_mode': mode,
        'expected_child_exit': expected_exit, 'columns': 80, 'rows': 24,
        'input': [{'wait_for': 'Fixture password', 'send': '<runtime-disposable-password+ENTER>'}]
                 if secret else inputs,
        'input_plan_removed_after_run': bool(secret),
        'evidence_kind': 'automated child PTY; no claim of operator keyboard review',
    }
    save(directory / 'case.json', definition)
    contains = list(spec.get('contains', []))
    if 'abort' in spec:
        contains.append('"aborted":' + json.dumps(spec['abort'], separators=(',', ':')))
    else:
        contains.append('"result":' + json.dumps(spec['result'], separators=(',', ':')))
    expectation = {'candidate': candidate, 'label': spec['name'], 'exit_code': expected_exit,
                   'contains': contains, 'absent': spec.get('absent', []),
                   'max_first_output_seconds': 5, 'max_idle_gap_seconds': 5}
    save(directory / 'expectation.json', expectation)
    failures = []
    checks = {}
    try:
        with (directory / 'capture.stdout').open('wb') as stdout, \
                (directory / 'capture.stderr').open('wb') as stderr:
            capture = subprocess.run(capture_command, cwd=SOURCE, env=environment,
                                     stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr,
                                     timeout=22, check=False)
        checks['collector_status'] = capture.returncode
        require(capture.returncode == expected_exit, 'Collector exit differs from expected child exit')
        with (directory / 'verify.json').open('wb') as stdout, \
                (directory / 'verify.stderr').open('wb') as stderr:
            verified = subprocess.run([sys.executable, str(SKILL / 'verify.py'), '--capture',
                                       str(recording), '--expect', str(directory / 'expectation.json')],
                                      cwd=SOURCE, env=environment, stdout=stdout, stderr=stderr,
                                      timeout=10, check=False)
        require(verified.returncode == 0, 'Restored verifier rejected capture; inspect verify.json')
        runtime = json.loads(runtime_path.read_text())
        require(runtime['child_exit_code'] == expected_exit, 'Fixture child status differs')
        require(runtime['fixture_stdin_tty'] == (not spec.get('piped_stdin', False)),
                'Fixture stdin mode differs from the named case')
        require(runtime['fixture_stdout_tty'] and runtime['fixture_stderr_tty'],
                'Prompt output does not have the declared PTY')
        require(runtime['stty_before'] == runtime['stty_after'],
                'Fixture changed terminal settings before collector recovery')
        checks['native_terminal_restored'] = True
        frames = [json.loads(line) for line in (recording / 'frames.jsonl').read_text().splitlines()]
        require(not frames[-1]['cursor']['hidden'], 'Child left its cursor hidden')
        checks['final_cursor_visible'] = True
        raw = (recording / 'raw.bin').read_bytes()
        if mode in ['plain', 'machine', 'no-interaction'] or spec.get('piped_stdin'):
            require(b'\x1b' not in raw, 'Plain or forbidden scope emitted terminal escapes')
            checks['raw_has_no_escape'] = True
        if mode == 'plain':
            require(raw.count(b'Fixture name') <= 3 and raw.count(b'Fixture password') <= 2,
                    'Plain prompt repeated active frames')
            checks['plain_frames_bounded'] = True
        if 'abort' in spec:
            require(not marker.exists(), 'Aborted prompt created a post-prompt mutation marker')
            checks['post_prompt_marker_absent'] = True
        else:
            require(marker.is_file(), 'Submitted prompt did not create its marker')
            require(json.loads(marker.read_text()) == {'scenario': spec['scenario'], 'result': spec['result']},
                    'Post-prompt marker result differs from the admitted stable value')
            checks['post_prompt_marker_matches'] = True
    except (AssertionError, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
        failures.append(str(error))
    finally:
        if secret:
            try:
                require((recording / 'raw.bin').is_file(), 'Password raw recording is missing')
                for path in recording.iterdir():
                    require(secret.encode() not in path.read_bytes(), 'Password appeared in recording artifact')
                checks['password_absent_from_raw_and_recording'] = True
            except (AssertionError, OSError) as error:
                failures.append(str(error))
            input_path.unlink(missing_ok=True)
    report = {'candidate': candidate, 'label': spec['name'], 'passed': not failures,
              'checks': checks, 'failures': failures}
    save(directory / 'assertions.json', report)
    files = [digest(path, root) for path in sorted(directory.rglob('*')) if path.is_file()]
    manifest = {**report, 'files': files}
    save(directory / 'manifest.json', manifest)
    return manifest


def main():
    if len(sys.argv) > 1 and sys.argv[1] == '--child':
        require(len(sys.argv) == 7, 'Invalid internal prompt fixture arguments')
        return child(sys.argv[2:])
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output_root', type=Path)
    parser.add_argument('candidate', help='Exact 40-character candidate SHA')
    args = parser.parse_args()
    require(re.fullmatch('[0-9a-f]{40}', args.candidate), 'Candidate must be an exact SHA')
    require(sys.prefix != sys.base_prefix, 'Use the prepared proof virtualenv Python')
    require(SELF.parent == Path('/var/lib/orbit-e2e/proof'), 'Runner must use staged proof fixture path')
    observed_paths = ['apps/cli/app', 'apps/cli/tests/Fixtures/Console/prompt-fixture.php',
                      '.agents/skills/verifying-cli-output/scripts']
    root = args.output_root.resolve() / 'prompts'
    root.mkdir(mode=0o700, parents=True, exist_ok=False)
    source_identity = runpy.run_path(str(SELF.with_name('orb352-identity.py')))['verify_source'](SOURCE, args.candidate, root)
    environment = dict(os.environ, TERM='xterm-256color', LC_ALL='C.UTF-8')
    for key in ['NO_COLOR', 'CLICOLOR', 'FORCE_COLOR']:
        environment.pop(key, None)
    identity = {'candidate': args.candidate, 'source_identity': source_identity, 'python': sys.executable, 'fixture_root': str(SELF.parent),
                'source_root': str(SOURCE), 'source_paths_checked_clean': observed_paths,
                'environment': {key: environment.get(key) for key in ['TERM', 'LC_ALL', 'NO_COLOR', 'CLICOLOR', 'FORCE_COLOR']},
                'source_files': [digest(path, SOURCE) for path in [FIXTURE, SKILL / 'capture.py', SKILL / 'verify.py']],
                'runner': digest(SELF, SELF.parent)}
    save(root / 'identity.json', identity)
    results = []
    with (root / 'cases.jsonl').open('w') as index:
        for spec in cases():
            manifest = run_case(root, args.candidate, spec, environment)
            index.write(json.dumps(manifest, ensure_ascii=False) + '\n')
            index.flush()
            print(json.dumps(manifest, ensure_ascii=False), flush=True)
            results.append({'label': manifest['label'], 'passed': manifest['passed'],
                            'manifest': digest(root / manifest['label'] / 'manifest.json', root)})
    summary = {'candidate': args.candidate, 'passed': all(row['passed'] for row in results),
               'cases': results, 'limitations': [
                   'Automated PTY input supplements separate live Solo keyboard inspection.',
                   'EOF is a real closed Unix socket used by InputTerminal; it is not Ctrl-D or PTY hangup.',
                   'PTY merges child stdout and stderr; separate-channel product contract proof is owned by modes.',
                   'These test-only adapter cases do not establish public command-family adoption.'],
               'files': [digest(root / name, root) for name in ['identity.json', 'cases.jsonl']]}
    save(root / 'summary.json', summary)
    print(json.dumps(summary), flush=True)
    return 0 if summary['passed'] else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (AssertionError, OSError, ValueError, subprocess.SubprocessError) as error:
        print(json.dumps({'passed': False, 'error': str(error)}), file=sys.stderr)
        raise SystemExit(1)
