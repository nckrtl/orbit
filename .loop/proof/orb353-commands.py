#!/usr/bin/env python3
"""Exercise the real ORB-353 CLI in disposable homes and record terminal evidence."""
import argparse
import fcntl
import hashlib
import http.server
import json
import os
from pathlib import Path
import signal
import ssl
import subprocess
import sys
import termios
import threading
import time


def save(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')


def child():
    runtime = Path(sys.argv[2])
    before = termios.tcgetattr(0)
    process = subprocess.Popen(sys.argv[3:])
    signal.signal(signal.SIGINT, signal.SIG_IGN)
    code = process.wait()
    after = termios.tcgetattr(0)
    normalized_before, normalized_after = before[:], after[:]
    if sys.platform == 'darwin':
        normalized_before[3] &= ~termios.PENDIN
        normalized_after[3] &= ~termios.PENDIN
    equal = normalized_before == normalized_after
    save(runtime, {'before': repr(before), 'after': repr(after), 'equal': equal,
                   'normalization': 'PENDIN only' if sys.platform == 'darwin' else 'none', 'exit_code': code})
    return code if equal else 90


if len(sys.argv) > 1 and sys.argv[1] == '--child':
    sys.exit(child())

parser = argparse.ArgumentParser()
parser.add_argument('--source', type=Path, default=Path('/home/orbit/orbit'))
parser.add_argument('--state', type=Path, default=Path('/home/orbit/.local/state/orbit-cli-ux/ORB-353'))
parser.add_argument('--candidate', required=True)
parser.add_argument('--group', choices=['rendering', 'prompts', 'liveness', 'modes', 'state', 'consent', 'refusals', 'adoption'], required=True)
parser.add_argument('--local', action='store_true')
args = parser.parse_args()
source, state = args.source, args.state
candidate = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip()
assert candidate == args.candidate
assert not subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()
root = state / args.group
root.mkdir(parents=True, mode=0o700)
recorder = source / '.agents/skills/verifying-cli-output/scripts'
launcher = source / 'apps/cli/orbit'
records = []
request_id = '0198e15c-bf97-7c23-8f1f-61b8fe67a844'

# A real TLS endpoint supplies controlled latency, empty fields, and failures.
# Normal Incus status cases additionally contact the actual disposable Gateway.
cert, key = root / 'tls.pem', root / 'tls.key'
subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                '-keyout', str(key), '-out', str(cert), '-subj', '/CN=127.0.0.1',
                '-addext', 'subjectAltName=IP:127.0.0.1'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
cert.chmod(0o600)
key.chmod(0o600)
server_state = {'delay': 0, 'failure': False, 'empty': False, 'requests': []}


class Endpoint(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        server_state['requests'].append(self.path)
        assert self.path == '/api/v1/gateway/status'
        time.sleep(server_state['delay'])
        failure = server_state['failure']
        payload = {'error': {'code': 'gateway.unavailable', 'message': 'Gateway is unavailable.', 'details': []}} if failure else {
            'data': {} if server_state['empty'] else {'name': 'disposable-gateway', 'status': 'ok', 'version': '0.1.0',
                                                     'php_version': '8.5.8', 'laravel_version': '13.26.1'},
            'meta': {'request_id': request_id}}
        body = json.dumps(payload).encode()
        self.send_response(503 if failure else 200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('X-Orbit-Request-Id', request_id)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *ignored):
        pass


server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Endpoint)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(cert, key)
server.socket = context.wrap_socket(server.socket, server_side=True)
threading.Thread(target=server.serve_forever, daemon=True).start()
url = f'https://127.0.0.1:{server.server_port}'


def run(name, command, *, mode='human', columns=100, inputs=None, expected=0, contains=None,
        active=False, changed=None, lock=None, delay=0, failure=False, empty=False, missing=False,
        malformed=False, actual=False, pin_denied=False):
    home = root / (name + '-home')
    home.mkdir(mode=0o700)
    pin = home / 'pinned.pem'
    if pin_denied:
        pin_directory = home / 'certificate'
        pin_directory.mkdir(mode=0o700)
        pin = pin_directory / 'pinned.pem'
    pin.write_bytes(cert.read_bytes())
    pin.chmod(0o600)
    if pin_denied:
        pin.parent.chmod(0o500)
    profile = {'url': url, 'ca_path': str(pin)}
    if actual and not args.local:
        original = json.loads((Path.home() / '.orbit/config.json').read_text())
        profile = original['gateways'][original['active_gateway']]
    config = {'active_gateway': 'secondary' if active else 'primary', 'gateways': {
        'primary': dict(profile), 'secondary': dict(profile)}}
    config_path = home / 'config.json'
    if not missing:
        config_path.write_text('{' if malformed else json.dumps(config))
        config_path.chmod(0o600)
    before = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in home.iterdir() if p.is_file()}
    environment = dict(os.environ, ORBIT_HOME=str(home), TERM='xterm-256color', LC_ALL='C.UTF-8', PAO_DISABLE='1')
    for key_name in ['NO_COLOR', 'CLICOLOR', 'FORCE_COLOR', 'COLUMNS', 'LINES']:
        environment.pop(key_name, None)
    server_state.update(delay=delay, failure=failure, empty=empty, requests=[])
    argv = ['php', str(launcher), *command]
    if mode == 'json':
        argv += ['--json', '--ansi']
    elif mode == 'plain':
        argv += ['--no-ansi']
    elif mode == 'pipe':
        argv += ['--ansi', '--no-interaction']
    else:
        argv += ['--ansi']
    lock_handle = None
    if lock:
        lock_handle = open(home / (lock + '.lock'), 'w')
        os.chmod(lock_handle.name, 0o600)
        fcntl.flock(lock_handle, fcntl.LOCK_EX)
        threading.Timer(2.4, lambda: fcntl.flock(lock_handle, fcntl.LOCK_UN)).start()
    output = root / name
    output.mkdir()
    save(output / 'case.json', {'candidate': candidate, 'launcher': str(launcher), 'argv': argv,
                              'mode': mode, 'columns': columns, 'expected_exit': expected, 'PAO_DISABLE': '1',
                              'actual_gateway': actual and not args.local})
    if mode in ['json', 'pipe']:
        process = subprocess.run(argv, cwd=source, env=environment, input=b'', capture_output=True, timeout=15)
        (output / 'stdout.bin').write_bytes(process.stdout)
        (output / 'stderr.bin').write_bytes(process.stderr)
        assert process.returncode == expected, (name, process.returncode, process.stdout, process.stderr)
        assert process.stderr == b'', (name, process.stderr)
        assert b'\x1b' not in process.stdout
        text = process.stdout.decode()
        if mode == 'json':
            payload = json.loads(text)
            assert isinstance(payload, dict)
            save(output / 'payload.json', payload)
        for item in contains or []:
            assert item in text, (name, item, text)
    else:
        capture = [sys.executable, str(recorder / 'capture.py'), '--output-dir', str(output / 'capture'),
                   '--candidate', candidate, '--label', name, '--columns', str(columns), '--rows', '40',
                   '--timeout', '15', '--idle-timeout', '10', '--no-live']
        if inputs:
            plan = output / 'input.json'
            save(plan, inputs)
            capture += ['--input-plan', str(plan)]
        capture += ['--', sys.executable, str(Path(__file__).resolve()), '--child', str(output / 'terminal.json'), *argv]
        result = subprocess.run(capture, cwd=source, env=environment, capture_output=True, timeout=25)
        assert result.returncode == expected, (name, result.returncode, result.stdout, result.stderr)
        assert json.loads((output / 'terminal.json').read_text())['equal']
        expectation = {'candidate': candidate, 'label': name, 'exit_code': expected, 'contains': contains or [],
                       'max_first_output_seconds': 1.5}
        if lock or delay:
            if mode == 'human' and expected == 0:
                running, completed = ('Requesting status', 'Received status') if delay else {
                    'gateway:use': ('Selecting profile', 'Selected profile'),
                    'gateway:remove': ('Removing profile', 'Removed profile'),
                    'extension:enable': ('Enabling extension', 'Enabled extension'),
                    'extension:disable': ('Disabling extension', 'Disabled extension')}[command[0]]
                expectation['animation_rows'] = [{'name': running, 'pattern': r'(?P<glyph>[○◉]) ' + running,
                    'terminal_pattern': '● ' + completed, 'minimum_changes': 3, 'min_interval': 0.15, 'max_interval': 0.65}]
        save(output / 'expectation.json', expectation)
        verified = subprocess.run([sys.executable, str(recorder / 'verify.py'), '--capture', str(output / 'capture'),
                                   '--expect', str(output / 'expectation.json')], capture_output=True, text=True)
        (output / 'verify.json').write_text(verified.stdout)
        assert verified.returncode == 0, (name, verified.stdout, verified.stderr)
        text = (output / 'capture/transcript.txt').read_text()
        if mode == 'plain':
            assert b'\x1b' not in (output / 'capture/raw.bin').read_bytes(), name
    if lock_handle:
        lock_handle.close()
    if pin_denied:
        assert pin.exists() and pin.read_bytes() == cert.read_bytes()
        assert 'secondary' not in json.loads(config_path.read_text())['gateways']
        pin.parent.chmod(0o700)
    after = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in home.iterdir() if p.is_file()}
    if changed is False:
        assert after == before, (name, before, after)
    if changed == 'remove':
        result_config = json.loads(config_path.read_text())
        assert 'secondary' not in result_config['gateways']
        assert result_config['active_gateway'] == (None if active else 'primary')
        assert not pin.exists()
    if changed == 'use':
        result_config = json.loads(config_path.read_text())
        assert result_config['active_gateway'] == 'secondary'
        assert result_config['gateways'] == config['gateways']
        assert pin.exists()
    if changed in ['enable', 'disable']:
        enabled = json.loads((home / 'extensions.json').read_text())['enabled']
        assert enabled == (['herdr'] if changed == 'enable' else [])
        assert (home / 'extensions.json').stat().st_mode & 0o777 == 0o600
        assert after['config.json'] == before['config.json']
        visibility = subprocess.run(['php', str(launcher), 'list', '--raw', '--no-ansi'],
                                    cwd=source, env=environment, capture_output=True, text=True, timeout=10)
        assert visibility.returncode == 0
        assert ('herdr:session:create' in visibility.stdout) == (changed == 'enable')
        (output / 'command-visibility.txt').write_text(visibility.stdout)
    save(output / 'state.json', {'before': before, 'after': after, 'requests': server_state['requests'], 'passed': True,
        'config_after': json.loads(config_path.read_text()) if config_path.exists() and not malformed else None,
        'certificate_exists': pin.exists()})
    records.append(name)
    print(json.dumps({'case': name, 'passed': True}), flush=True)


commands = [
    ('gateway-use', ['gateway:use', 'secondary'], 'Gateway [secondary] is active.', 'use'),
    ('gateway-remove', ['gateway:remove', 'secondary', '--yes'], 'Gateway [secondary] removed.', 'remove'),
    ('gateway-status', ['gateway:status'], 'Gateway:', False),
    ('extension-enable', ['extension:enable', 'herdr'], 'Orbit extension [herdr] is enabled.', 'enable'),
    ('extension-disable', ['extension:disable', 'herdr'], 'Orbit extension [herdr] is disabled.', 'disable'),
    ('extension-list', ['extension:list'], 'herdr', False),
]
if args.group == 'rendering':
    for name, command, outcome, change in commands:
        for width in [80, 24]:
            # Long outcomes wrap; match separate words in narrow layouts.
            run(f'{name}-{width}', command, columns=width, contains=[outcome] if width == 80 else [outcome.split()[0]],
                changed=change, actual=name == 'gateway-status')
    run('status-empty-fields', ['gateway:status'], empty=True, contains=['Gateway: primary', '—'], changed=False)
elif args.group in ['prompts', 'consent']:
    cases = [('accept', 'y\r', 0, 'remove'), ('default-no', '\r', 1, False),
             ('decline', 'n\r', 1, False), ('ctrl-c', '\x03', 1, False), ('eof', '\x04', 1, False)]
    for name, keys, code, change in cases:
        mode = 'human' if args.group == 'consent' else 'plain'
        run(name, ['gateway:remove', 'secondary'], mode=mode, inputs=([{'wait_for': 'pinned certificate?', 'send': 'y'}, {'wait_for': 'Yes', 'send': '\r'}] if name == 'accept' else ([{'wait_for': 'pinned certificate?', 'send': 'y'}, {'wait_for': 'Yes', 'send': 'n'}, {'wait_for': 'No', 'send': '\r'}] if name == 'decline' else [{'wait_for': 'pinned certificate?', 'send': keys}])),
            expected=code, contains=['Gateway [secondary] removed.' if code == 0 else 'Gateway profile removal cancelled.'], changed=change)
    if args.group == 'consent':
        run('active-accepted', ['gateway:remove', 'secondary', '--force'], active=True,
            inputs=[{'wait_for': 'pinned certificate?', 'send': 'y'}, {'wait_for': 'Yes', 'send': '\r'}], contains=['Gateway [secondary] removed.'], changed='remove')
elif args.group == 'liveness':
    for name, command, outcome, change in commands:
        if name == 'extension-list':
            continue
        run(name, command, contains=[outcome], changed=change,
            delay=2 if name == 'gateway-status' else 0,
            lock=None if name == 'gateway-status' else ('extensions.json' if name.startswith('extension') else 'config.json'))
    run('status-failure', ['gateway:status'], delay=2, failure=True, expected=1,
        contains=['Gateway is unavailable.', 'Gateway status unavailable.', request_id], changed=False)
elif args.group == 'modes':
    for name, command, outcome, change in commands:
        for mode in ['json', 'pipe', 'plain']:
            token = 'primary' if name == 'gateway-status' else ('secondary' if name.startswith('gateway') else 'herdr')
            run(name + '-' + mode, command, mode=mode, contains=[token], changed=change, actual=name == 'gateway-status')
elif args.group == 'state':
    for mode in ['json', 'human']:
        run('certificate-cleanup-failure-' + mode, ['gateway:remove', 'secondary', '--yes'],
            pin_denied=True, mode=mode, expected=1,
            contains=['Gateway profile was removed, but its pinned certificate could not be deleted.'])
    run('force-consent', ['gateway:remove', 'secondary', '--force', '--yes'], active=True, mode='json', contains=['secondary'], changed='remove')
    for command in ['gateway:use', 'gateway:remove']:
        run(command.replace(':', '-') + '-unknown', [command, 'absent'], mode='json', expected=1,
            contains=['gateway.profile_not_found'], changed=False)
    for command in ['extension:enable', 'extension:disable']:
        run(command.replace(':', '-') + '-unknown', [command, 'unknown'], mode='json', expected=1,
            contains=['extension.unknown'], changed=False)
    run('status-no-profile', ['gateway:status'], missing=True, mode='json', expected=1,
        contains=['gateway.profile_missing'], changed=False)
elif args.group == 'refusals':
    for mode in ['json', 'pipe']:
        run('consent-' + mode, ['gateway:remove', 'secondary'], mode=mode, expected=1,
            contains=['Supply --yes'], changed=False)
        run('force-without-consent-' + mode, ['gateway:remove', 'secondary', '--force'], active=True,
            mode=mode, expected=1, contains=['Supply --yes'], changed=False)
    run('consent-without-force', ['gateway:remove', 'secondary', '--yes'], active=True,
        mode='json', expected=1, contains=['gateway.profile_active'], changed=False)
    run('invalid-config', ['gateway:remove', 'secondary'], malformed=True,
        mode='json', expected=1, contains=['gateway.config_invalid'], changed=False)
    for command in ['gateway:use', 'gateway:remove', 'extension:enable', 'extension:disable']:
        run(command.replace(':', '-') + '-missing', [command], mode='json', expected=1,
            contains=['input.invalid'], changed=False)
elif args.group == 'adoption':
    for group in ['rendering', 'prompts', 'liveness', 'modes', 'state', 'consent', 'refusals']:
        summary = json.loads((state / group / 'summary.json').read_text())
        assert summary['candidate'] == candidate and summary['passed'] and summary['cases']
        records += summary['cases']

save(root / 'summary.json', {'candidate': candidate, 'group': args.group, 'passed': True, 'cases': records,
                             'source': str(source), 'launcher': str(launcher), 'local_preflight': args.local})
server.shutdown()
