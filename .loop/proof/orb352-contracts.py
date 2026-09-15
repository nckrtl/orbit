#!/usr/bin/env python3
"""Usage: VENV/bin/python orb352-contracts.py modes|contracts NEW_OUTPUT_ROOT SHA.

Private --child and --exec entries retain actual descriptor facts before exec.
They write evidence files only; they never print wrapper diagnostics into the
command's stdout/stderr. Every command still runs inside the restored recorder.
"""

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import runpy
import shutil
import subprocess
import sys
import time


SOURCE = Path('/home/orbit/orbit')
SCRIPTS = SOURCE / '.agents/skills/verifying-cli-output/scripts'
RUNTIME = SOURCE / 'apps/cli/tests/Fixtures/Console/runtime-fixture.php'
LAUNCHER = SOURCE / 'apps/cli/orbit'
UNKNOWN = 'orb352-not-a-command'
UNKNOWN_MESSAGE = f'Command "{UNKNOWN}" is not defined. Run "orbit list" to see available commands.'
PARSER = {'error': {'code': 'input.invalid', 'message': 'The "--unexpected" option does not exist.', 'request_id': None}}
ANSI = re.compile(rb'\x1b\[[0-?]*[ -/]*[@-~]')


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def save(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def manifest(directory):
    return [{'path': str(path.relative_to(directory)), 'bytes': path.stat().st_size, 'sha256': sha(path)}
            for path in sorted(directory.rglob('*')) if path.is_file() and path != directory / 'manifest.json']


def execute(config_path):
    config = json.loads(config_path.read_text())
    facts = {'pid': os.getpid(), 'argv': config['argv'], 'cwd': os.getcwd(), 'descriptors': {}}
    for fd, name in enumerate(['stdin', 'stdout', 'stderr']):
        try:
            size = os.get_terminal_size(fd)
            dimensions = {'columns': size.columns, 'rows': size.lines}
        except OSError:
            dimensions = None
        facts['descriptors'][name] = {'isatty': os.isatty(fd), 'dimensions': dimensions}
    save(config_path.parent / 'child-fds.json', facts)
    os.execv(config['argv'][0], config['argv'])


def child(config_path):
    config = json.loads(config_path.read_text())
    started = time.monotonic()
    process = subprocess.Popen(
        [sys.executable, str(Path(__file__).resolve()), '--exec', str(config_path)],
        stdin=subprocess.PIPE if config['stdin'] == 'pipe' else None,
        stdout=subprocess.PIPE if config['stdout'] == 'pipe' else None,
        stderr=subprocess.PIPE if config['stderr'] == 'pipe' else None,
    )
    timed_out = False
    try:
        stdout, stderr = process.communicate(input=b'' if config['stdin'] == 'pipe' else None, timeout=20)
    except subprocess.TimeoutExpired:
        timed_out = True
        process.kill()
        stdout, stderr = process.communicate(timeout=3)
    for name, value in [('stdout', stdout), ('stderr', stderr)]:
        if value is not None:
            (config_path.parent / (name + '.bin')).write_bytes(value)
    save(config_path.parent / 'child-result.json', {'exit_code': process.returncode,
         'pid': process.pid, 'duration_seconds': time.monotonic() - started, 'timed_out': timed_out})
    return 124 if timed_out else process.returncode if process.returncode >= 0 else 128 - process.returncode


def command_cases(action, php):
    if action == 'contracts':
        arguments = [
            ('default', [], 0), ('version', ['--version'], 0),
            ('help', ['help', 'activity:list'], 0), ('list', ['list'], 0),
            ('completion', ['completion', 'bash'], 0),
            # Match the generated Bash client's protocol: the cursor is after
            # token 0, but empty COMP_WORDS entries are not sent as input tokens.
            ('complete', ['_complete', '--no-interaction', '--shell=bash', '--api-version=1', '--current=1', '--input=orbit'], 0),
            ('unknown', [UNKNOWN], 1),
            ('parser', ['activity:list', '--unexpected', '--json'], 1),
            ('list-json', ['list', '--format=json'], 0),
        ]
        cases = []
        for name, args, status in arguments:
            # Both streams are retained independently. The output appropriate
            # for the command is terminal-attached in the PTY variant.
            for variant in ['terminal', 'pipe', 'forced-ansi-pipe']:
                argv = [php, str(LAUNCHER), *args]
                if variant == 'forced-ansi-pipe':
                    argv.append('--ansi')
                cases.append({'name': name + '-' + variant, 'kind': name, 'argv': argv,
                    'stdin': 'tty' if variant == 'terminal' else 'pipe',
                    'stdout': 'tty' if variant == 'terminal' and name != 'unknown' else 'pipe',
                    'stderr': 'tty' if variant == 'terminal' and name == 'unknown' else 'pipe',
                    'expected_status': status, 'variant': variant})
        return cases
    definitions = [
        ('terminal', [], 'tty', 'tty', 'pipe'),
        ('terminal-json', ['--json', '--ansi'], 'tty', 'tty', 'pipe'),
        ('terminal-nointeraction', ['--no-interaction'], 'tty', 'tty', 'pipe'),
        ('terminal-short-nointeraction', ['-n'], 'tty', 'tty', 'pipe'),
        ('terminal-plain', ['--plain'], 'tty', 'tty', 'pipe'),
        ('stdin-pipe', [], 'pipe', 'tty', 'pipe'),
        ('stdin-pipe-json', ['--json'], 'pipe', 'tty', 'pipe'),
        ('stdout-pipe-stderr-tty', [], 'tty', 'pipe', 'tty'),
        ('selected-stderr-tty', ['--stderr'], 'tty', 'pipe', 'tty'),
        ('selected-stderr-tty-stdin-pipe', ['--stderr'], 'pipe', 'pipe', 'tty'),
        ('selected-stderr-tty-json', ['--stderr', '--json'], 'tty', 'pipe', 'tty'),
        ('selected-stderr-tty-plain', ['--stderr', '--plain'], 'tty', 'pipe', 'tty'),
        ('all-pipes-forced-ansi', ['--ansi'], 'pipe', 'pipe', 'pipe'),
        ('all-pipes-json', ['--json', '--ansi'], 'pipe', 'pipe', 'pipe'),
    ]
    return [{'name': name, 'kind': 'modes', 'argv': [php, str(RUNTIME), 'modes', *flags],
             'stdin': stdin, 'stdout': stdout, 'stderr': stderr, 'expected_status': 0,
             'variant': 'mode', 'flags': flags}
            for name, flags, stdin, stdout, stderr in definitions]


def read_channels(directory, case):
    # Exactly one output descriptor may be attached to this PTY. This avoids
    # pretending a merged terminal transcript distinguishes stdout from stderr.
    require(sum(case[channel] == 'tty' for channel in ['stdout', 'stderr']) <= 1, 'Ambiguous terminal channel capture')
    raw = (directory / 'capture/raw.bin').read_bytes()
    channels = {}
    for name in ['stdout', 'stderr']:
        channels[name] = raw if case[name] == 'tty' else (directory / (name + '.bin')).read_bytes()
    if case['stdout'] == case['stderr'] == 'pipe':
        require(raw == b'', 'Wrapper polluted terminal while both child outputs were piped')
    return channels


def clean(data):
    return ANSI.sub(b'', data).decode('utf-8', errors='strict').replace('\r\n', '\n')


def check_fds(directory, case):
    facts = json.loads((directory / 'child-fds.json').read_text())
    require(facts['argv'] == case['argv'], 'Exec command identity differs')
    for name in ['stdin', 'stdout', 'stderr']:
        actual = facts['descriptors'][name]
        require(actual['isatty'] == (case[name] == 'tty'), 'Actual descriptor mode differs: ' + name)
        if actual['isatty']:
            require(actual['dimensions'] == {'columns': 120, 'rows': 40}, 'Actual child PTY dimensions differ')
    return facts


def check_modes(channels, facts, case):
    flags = case['flags']
    selected = 'stderr' if '--stderr' in flags else 'stdout'
    opposite = 'stdout' if selected == 'stderr' else 'stderr'
    require(channels[opposite] == b'', 'Mode fixture polluted the unselected channel')
    require(b'\x1b' not in channels[selected], 'Mode facts contain ANSI or prompt output')
    payload = json.loads(channels[selected])
    machine = '--json' in flags
    expected = {
        'machine': machine,
        'mayPrompt': not machine and facts['descriptors']['stdin']['isatty'] and not any(flag in flags for flag in ['--no-interaction', '-n']),
        'decorated': not machine and '--plain' not in flags and facts['descriptors'][selected]['isatty'],
        'mayRepaint': not machine and '--plain' not in flags and facts['descriptors'][selected]['isatty'],
        'columns': 120 if any(value['isatty'] for value in facts['descriptors'].values()) else 80,
    }
    require(payload == expected, 'ConsoleMode differs from actual descriptors/options: ' + repr((payload, expected)))
    require(len(channels[selected].decode().splitlines()) == 1, 'Mode fixture printed repeated frames')
    return {'selected_channel': selected, 'actual_facts': payload, 'expected_facts': expected}


def check_contracts(channels, case):
    kind = case['kind']
    stdout, stderr = channels['stdout'], channels['stderr']
    for name, data in channels.items():
        if case[name] == 'pipe' or kind in ['parser', 'list-json', 'complete', 'completion']:
            require(b'\x1b' not in data, 'Pipe or machine/framework serialization contains ANSI: ' + name)
        require(b'PHP Warning' not in data and b'PHP Fatal error' not in data, 'PHP diagnostic in command channel')
    if kind == 'unknown':
        require(stdout == b'', 'Unknown command polluted stdout')
        require(clean(stderr) == UNKNOWN_MESSAGE + '\n', 'Unknown-command status guidance differs')
        return {'channel': 'stderr', 'message': UNKNOWN_MESSAGE, 'product_envelope': False}
    require(stderr == b'', 'Unexpected stderr on framework/product JSON surface')
    text = clean(stdout)
    if kind == 'parser':
        require(json.loads(stdout) == PARSER, 'Prebinding parser error envelope/code/request_id differs')
        require(len(text.splitlines()) == 1, 'Parser emitted human usage or multiple JSON values')
        return {'channel': 'stdout', 'envelope': PARSER}
    if kind == 'list-json':
        payload = json.loads(stdout)
        require(isinstance(payload, dict) and {'application', 'commands', 'namespaces'} <= payload.keys(), 'Framework list JSON structure changed')
        require('error' not in payload and 'data' not in payload, 'Framework list gained a product envelope')
        commands = payload['commands']
        require(isinstance(commands, list) and all(isinstance(item, dict) and isinstance(item.get('name'), str) for item in commands), 'Framework commands schema changed')
        names = [item['name'] for item in commands]
        require(len(names) == len(set(names)) and {'activity:list', 'gateway:status', 'tool:update'} <= set(names), 'Framework list lost or duplicated commands')
        return {'framework_keys': sorted(payload), 'commands': len(names), 'product_envelope': False}
    require(not text.lstrip().startswith('{'), 'Human/framework surface gained a product envelope')
    if kind in ['default', 'list']:
        require('Orbit' in text and 'USAGE:' in text, 'Framework summary header or usage missing')
        names = re.findall(r'^  ([a-z][a-z0-9:_-]+)\s+\S', text, re.MULTILINE)
        require({'activity:list', 'gateway:status', 'tool:update'} <= set(names), 'Summary lost complete command rows')
        require(len(names) == len(set(names)), 'Summary repeated command rows')
        return {'format': 'framework summary', 'complete_command_rows': names}
    if kind == 'version':
        require(re.fullmatch(r'Orbit\s+[^\n]+\n', text) is not None, 'Version output is not one native application/version line')
    elif kind == 'help':
        require('Usage:' in text and 'activity:list' in text and 'Options:' in text and '--json' in text, 'Framework help sections or product option missing')
        require('Request ID:' not in text and 'Working...' not in text, 'Help contains product decorations')
    elif kind == 'completion':
        require('COMP_WORDS' in text and '_complete' in text and 'complete -F' in text, 'Completion is not the framework Bash script')
        # PTY ONLCR converts LF to CRLF. Validate the reconstructed script, while
        # retaining the untouched channel bytes as the recording evidence.
        result = subprocess.run(['bash', '-n'], input=text.encode('utf-8'), capture_output=True)
        require(result.returncode == 0 and result.stderr == b'', 'Generated Bash completion syntax is invalid')
        return {'format': kind, 'product_envelope': False,
                'syntax_source': 'stdout with PTY CRLF normalized to LF', 'bash_syntax_exit': result.returncode}
    elif kind == 'complete':
        names = text.splitlines()
        require(names and all(re.fullmatch('[a-z][a-z0-9:_-]*', name) for name in names), 'Completion suggestions gained headers or envelopes')
        require({'activity:list', 'activity:show', 'gateway:status'} <= set(names), 'Completion lost exact command suggestions')
        require(len(names) == len(set(names)), 'Completion repeated suggestions')
    return {'format': kind, 'product_envelope': False}


def main():
    require(len(sys.argv) == 4 and sys.argv[1] in ['modes', 'contracts'], __doc__)
    action, output, candidate = sys.argv[1], Path(sys.argv[2]).resolve(), sys.argv[3]
    require(re.fullmatch('[0-9a-f]{40}', candidate) is not None, 'Exact SHA required')
    output.mkdir(parents=True, exist_ok=False)
    source_identity = runpy.run_path(str(Path(__file__).with_name('orb352-identity.py')))['verify_source'](SOURCE, candidate, output)
    php = shutil.which('php')
    require(php is not None, 'PHP launcher missing')
    spec = importlib.util.spec_from_file_location('orbit_verify', SCRIPTS / 'verify.py')
    verifier = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(verifier)
    save(output / 'identity.json', {'candidate': candidate, 'source': str(SOURCE), 'source_identity': source_identity, 'php': php,
         'php_realpath': str(Path(php).resolve()), 'python': sys.executable,
         'runner_sha256': sha(Path(__file__)), 'launcher_sha256': sha(LAUNCHER),
         'runtime_fixture_sha256': sha(RUNTIME), 'capture_sha256': sha(SCRIPTS / 'capture.py'),
         'verify_sha256': sha(SCRIPTS / 'verify.py')})
    environment = {**os.environ, 'TERM': 'xterm-256color'}
    for key in ['NO_COLOR', 'CLICOLOR', 'FORCE_COLOR', 'COLUMNS', 'LINES', 'ORBIT_UX_TRACE', 'ORBIT_UX_FAULT', 'SYMFONY_COMPLETION_DEBUG']:
        environment.pop(key, None)
    reports = []
    for case in command_cases(action, php):
        directory = output / case['name']
        directory.mkdir()
        # Every case gets a fresh non-secret Orbit home, without changing HOME.
        environment['ORBIT_HOME'] = str(directory / 'orbit-home')
        config_path = directory / 'case.json'
        save(config_path, {**case, 'candidate': candidate, 'columns': 120, 'rows': 40,
             'environment': {key: environment.get(key) for key in ['TERM', 'NO_COLOR', 'ORBIT_HOME', 'COLUMNS', 'LINES']}})
        invocation = [sys.executable, str(SCRIPTS / 'capture.py'), '--output-dir', str(directory / 'capture'),
             '--candidate', candidate, '--label', action + '-' + case['name'], '--columns', '120', '--rows', '40',
             '--timeout', '25', '--idle-timeout', '22', '--', sys.executable, str(Path(__file__).resolve()), '--child', str(config_path)]
        save(directory / 'recorder-invocation.json', invocation)
        completed = subprocess.run(invocation, cwd=SOURCE, env=environment)
        try:
            require(completed.returncode == case['expected_status'], 'Capture status differs from expected child status')
            child_result = json.loads((directory / 'child-result.json').read_text())
            require(child_result['exit_code'] == case['expected_status'] and not child_result['timed_out'], 'Actual PHP child status differs or timed out')
            # Piped-output cases deliberately have an empty PTY. The verifier
            # still checks capture identity, status, complete drain and frames;
            # exact stdout/stderr assertions below establish their output.
            expectation = {'candidate': candidate, 'label': action + '-' + case['name'],
                 'exit_code': case['expected_status'], 'absent': ['PHP Fatal error', 'PHP Warning', 'Traceback (most recent call last)']}
            save(directory / 'expectation.json', expectation)
            verification = verifier.verify(directory / 'capture', expectation)
            save(directory / 'verify.json', verification)
            require(verification['passed'], str(verification['failures']))
            facts = check_fds(directory, case)
            channels = read_channels(directory, case)
            assertions = check_modes(channels, facts, case) if action == 'modes' else check_contracts(channels, case)
            summary = json.loads((directory / 'capture/summary.json').read_text())
            require(summary['input_bytes_sent'] == 0, 'Unexpected input was sent during non-prompt case')
            report = {'case': case['name'], 'passed': True, 'actual_child_exit': child_result['exit_code'],
                 'channels': {name: {'bytes': len(value), 'sha256': hashlib.sha256(value).hexdigest(),
                               'source': 'capture/raw.bin' if case[name] == 'tty' else name + '.bin'} for name, value in channels.items()},
                 'descriptor_facts': facts['descriptors'], 'assertions': assertions}
        except Exception as error:
            report = {'case': case['name'], 'passed': False, 'failure': str(error)}
        save(directory / 'assertions.json', report)
        save(directory / 'manifest.json', manifest(directory))
        reports.append(report)
        print(json.dumps({'case': case['name'], 'passed': report['passed'], 'manifest': str(directory / 'manifest.json'),
             'manifest_bytes': (directory / 'manifest.json').stat().st_size, 'manifest_sha256': sha(directory / 'manifest.json')}), flush=True)
    save(output / 'result.json', {'action': action, 'candidate': candidate, 'passed': all(report['passed'] for report in reports),
         'cases': reports, 'scope': 'Common entry points and actual descriptor mode facts; no family behavior adoption verdict.'})
    save(output / 'manifest.json', manifest(output))
    print(json.dumps({'result': str(output / 'result.json'), 'manifest': str(output / 'manifest.json'),
         'manifest_bytes': (output / 'manifest.json').stat().st_size, 'manifest_sha256': sha(output / 'manifest.json'),
         'cases': len(reports), 'passed': sum(report['passed'] for report in reports)}), flush=True)
    return 0 if all(report['passed'] for report in reports) else 1


if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] in ['--exec', '--child']:
        config_path = Path(sys.argv[2])
        try:
            sys.exit(execute(config_path) if sys.argv[1] == '--exec' else child(config_path))
        except Exception as error:
            save(config_path.parent / ('wrapper-error-' + sys.argv[1][2:] + '.json'), {'error': str(error)})
            sys.exit(125)
    sys.exit(main())
