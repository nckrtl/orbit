#!/usr/bin/env python3
"""Record actual CLI processes against a controlled disposable TLS Gateway fixture.
Real Gateway state transitions are separately exercised by orb354-actual.py.
"""
import argparse
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


if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    proc = subprocess.Popen(sys.argv[3:])
    signal.signal(signal.SIGINT, signal.SIG_IGN)
    code = proc.wait()
    after = termios.tcgetattr(0)
    left, right = before[:], after[:]
    if sys.platform == 'darwin':
        left[3] &= ~termios.PENDIN
        right[3] &= ~termios.PENDIN
    save(Path(sys.argv[2]), {'before': repr(before), 'after': repr(after), 'equal': left == right, 'exit_code': code})
    sys.exit(code if left == right else 90)

parser = argparse.ArgumentParser()
parser.add_argument('--source', type=Path, default=Path('/home/orbit/orbit'))
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--candidate', required=True)
parser.add_argument('--group', choices=['rendering', 'modes', 'liveness', 'errors', 'consent', 'empty', 'invalid'], required=True)
args = parser.parse_args()
os.umask(0o077)
source, root = args.source, args.state / args.group
root.mkdir(parents=True)
candidate = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip()
assert candidate == args.candidate
assert not subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()
recorder = source / '.agents/skills/verifying-cli-output/scripts'
launcher = source / 'apps/cli/orbit'
cases = json.loads(Path(__file__).with_name('orb354-cases.json').read_text())
assert len(cases) == 23 and len({c['argv'][0] for c in cases}) == 23
request_id = '0198e15c-bf97-7c23-8f1f-61b8fe67a844'
node = {'id': 2, 'name': 'ux-node', 'status': 'active', 'wireguard_ip': '10.44.0.2', 'lan_ip': '10.0.0.2'}
app = {'id': 3, 'name': 'UX proof', 'slug': 'ux-proof', 'repository_url': 'https://example.test/ux.git',
       'default_branch': 'stable', 'root': 'public', 'defaults': {'php_version': '8.5'}}
cluster = {'id': 3, 'name': 'ux-cluster', 'tld': 'proof', 'state': 'inactive', 'nodes': [node], 'router': None}
route = {'id': 11, 'app_id': 3, 'node_id': 2, 'cluster_id': None, 'generation_basis_node_id': 2,
         'domain': 'ux.test', 'provenance': 'explicit', 'publication': 'private', 'public_publication': 'inactive',
         'status': 'ready', 'failed_step': None, 'error_code': None, 'replaces_route_id': 10, 'replaced_by_route_id': None,
         'replacement_step': None, 'target_set_step': None, 'target': {'id': 12, 'app_instance_id': 7, 'position': 0},
         'targets': [{'id': 12, 'app_instance_id': 7, 'position': 0}, {'id': 13, 'app_instance_id': 9, 'position': 1}]}
activity = {'id': 42, 'request_id': '33333333-3333-4333-8333-333333333333', 'command': 'process:start',
            'caller_node_id': 2, 'target_node_id': 3, 'caller_ip': '10.44.0.2', 'status': 'failed', 'duration_ms': 12,
            'exit_code': 1, 'error_code': 'process.start_failed', 'subject_type': 'Process', 'subject_id': 7,
            'properties': [], 'occurred_at': '2026-09-15T12:00:00+00:00'}
fixtures = {'apps': app, 'clusters': cluster, 'routes': route, 'activities': activity}
cert, key = root / 'tls.pem', root / 'tls.key'
subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-keyout', str(key),
                '-out', str(cert), '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1'],
               check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
state = {}


class Endpoint(http.server.BaseHTTPRequestHandler):
    def handle_request(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        state['requests'].append({'method': self.command, 'path': self.path, 'body': json.loads(body) if body else None})
        time.sleep(state.get('delay', 0))
        family = self.path.split('/')[3].split('?')[0]
        listing = self.path.split('?')[0] == '/api/v1/' + family
        data = fixtures[family]
        payload = {'data': ([] if state.get('empty') else [data]) if listing and self.command == 'GET' else data,
                   'meta': {'request_id': request_id}}
        failed = state.get('failure') is True or (state.get('failure') == 'mutation' and self.command != 'GET')
        if failed:
            payload = {'error': {'code': 'gateway.unavailable', 'message': 'Gateway is unavailable.',
                                 'details': {'token': 'must-not-appear'}}}
        encoded = json.dumps(payload).encode()
        self.send_response(503 if failed else 200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('X-Orbit-Request-Id', request_id)
        self.send_header('Content-Length', str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    do_GET = do_POST = do_PATCH = do_PUT = do_DELETE = handle_request

    def log_message(self, *unused):
        pass


server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Endpoint)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(cert, key)
server.socket = context.wrap_socket(server.socket, server_side=True)
threading.Thread(target=server.serve_forever, daemon=True).start()
records = []


def run(case, suffix, *, mode='human', columns=100, expected=0, inputs=None, consent=True, failure=False,
        empty=False, delay=0, invalid=False, contains=None):
    name = case['argv'][0].replace(':', '-') + '-' + suffix
    output, home = root / name, root / (name + '-home')
    output.mkdir()
    home.mkdir()
    save(home / 'config.json', {'active_gateway': 'proof', 'gateways': {
        'proof': {'url': f'https://127.0.0.1:{server.server_port}', 'ca_path': str(cert)}}})
    environment = dict(os.environ, ORBIT_HOME=str(home), TERM='xterm-256color', LC_ALL='C.UTF-8', PAO_DISABLE='1')
    for key_name in ['NO_COLOR', 'CLICOLOR', 'FORCE_COLOR', 'COLUMNS', 'LINES']:
        environment.pop(key_name, None)
    if mode in ['json', 'pipe']:
        environment['COLUMNS'] = str(columns)
    command = case['argv'][:]
    if not consent:
        command.remove(case['consent'])
    if invalid == 'parser':
        command = [command[0], '--invalid-fixture-option']
    elif invalid == 'input':
        command = case['invalid_argv']
    elif invalid == 'required':
        command = [command[0]]
    elif invalid == 'profile':
        (home / 'config.json').unlink()
    argv = ['php', str(launcher), *command]
    argv += ['--json', '--ansi'] if mode == 'json' else ['--no-ansi'] if mode == 'plain' else ['--ansi']
    if mode == 'pipe':
        argv += ['--no-interaction']
    state.update(requests=[], failure=failure, empty=empty, delay=delay)
    required = contains if contains is not None else case['contains']
    forbidden = ['must-not-appear']
    if failure:
        required = ['Gateway is unavailable.']
        forbidden += ['● ' + case['completed']]
    if empty:
        required = ['No ' + {'apps': 'Apps', 'clusters': 'Clusters', 'routes': 'Routes', 'activities': 'activities'}[case['family']] + ' found.']
    save(output / 'case.json', {'candidate': candidate, 'launcher': str(launcher), 'argv': argv, 'mode': mode,
                               'columns': columns, 'expected_exit': expected, 'PAO_DISABLE': '1', 'controlled_tls': True})
    if mode in ['json', 'pipe']:
        result = subprocess.run(argv, cwd=source, env=environment, input=b'', capture_output=True, timeout=20)
        (output / 'stdout.bin').write_bytes(result.stdout)
        (output / 'stderr.bin').write_bytes(result.stderr)
        assert result.returncode == expected, (name, result.returncode, result.stdout, result.stderr)
        assert result.stderr == b'' and b'\x1b' not in result.stdout, name
        content = result.stdout.decode()
        if mode == 'json':
            payload = json.loads(content)
            assert isinstance(payload, dict), name
            if expected == 0:
                data = fixtures[case['family']]
                if case['argv'][0].endswith(':list'):
                    exact = {case['family']: [] if empty else [data], 'request_id': request_id}
                else:
                    exact = dict(data)
                    exact['gateway_request_id' if case['family'] == 'activities' else 'request_id'] = request_id
            else:
                code, message, correlation = 'gateway.unavailable', 'Gateway is unavailable.', request_id
                if invalid:
                    correlation = None
                    if invalid == 'parser':
                        code, message = 'input.invalid', 'The "--invalid-fixture-option" option does not exist.'
                    elif invalid == 'required':
                        code, message = 'input.invalid', 'Not enough arguments (missing: "' + ', '.join(case['required']) + '").'
                    elif invalid == 'profile':
                        code, message = 'gateway.profile_missing', 'No active gateway profile.'
                    else:
                        code, message = case['invalid_error']
                elif not consent:
                    correlation = None
                    code, message = 'input.confirmation_required', 'Supply --yes to confirm this operation.'
                    if case['consent'] == '--force':
                        operation = {'cluster:destroy': 'removal', 'cluster:node:remove': 'Node detachment', 'cluster:router:unset': 'Router clearing'}[case['argv'][0]]
                        code, message = 'cluster.confirmation_required', 'Use --force to confirm Cluster ' + operation + '.'
                exact = {'error': {'code': code, 'message': message, 'request_id': correlation}}
            assert json.dumps(payload, sort_keys=True) == json.dumps(exact, sort_keys=True), (name, payload, exact)
            save(output / 'payload.json', payload)
        else:
            for value in required:
                assert value in content, (name, value, content)
        for value in forbidden:
            assert value not in content, (name, value)
    else:
        capture = [sys.executable, str(recorder / 'capture.py'), '--output-dir', str(output / 'capture'),
                   '--candidate', candidate, '--label', name, '--columns', str(columns), '--rows', '60',
                   '--timeout', '20', '--idle-timeout', '12', '--no-live']
        if inputs:
            save(output / 'input.json', inputs)
            capture += ['--input-plan', str(output / 'input.json')]
        capture += ['--', sys.executable, str(Path(__file__).resolve()), '--child', str(output / 'terminal.json'), *argv]
        result = subprocess.run(capture, cwd=source, env=environment, capture_output=True, timeout=30)
        assert result.returncode == expected, (name, result.returncode, result.stdout, result.stderr)
        assert json.loads((output / 'terminal.json').read_text())['equal'], name
        expectation = {'candidate': candidate, 'label': name, 'exit_code': expected, 'contains': required,
                       'absent': forbidden, 'max_first_output_seconds': 1.5}
        if delay and mode == 'human' and expected == 0:
            expectation['animation_rows'] = [{'name': case['running'], 'pattern': '(?P<glyph>[○◉]) ' + case['running'],
                'terminal_pattern': '● ' + case['completed'], 'minimum_changes': 3, 'min_interval': 0.15, 'max_interval': 0.65}]
        save(output / 'expectation.json', expectation)
        verified = subprocess.run([sys.executable, str(recorder / 'verify.py'), '--capture', str(output / 'capture'),
                                   '--expect', str(output / 'expectation.json')], capture_output=True, text=True)
        (output / 'verify.json').write_text(verified.stdout)
        assert verified.returncode == 0, (name, verified.stdout, verified.stderr)
        if mode == 'plain':
            assert b'\x1b' not in (output / 'capture/raw.bin').read_bytes(), name
    requests = state['requests'][:]
    expected_requests = []
    if not invalid:
        preflight = case.get('consent') and (case['consent'] == '--force' or not consent)
        if preflight:
            expected_requests.append({'method': 'GET', 'path': '/api/v1/' + case['family'] + '/' + case['argv'][1], 'body': None})
        if not preflight or expected == 0 or failure == 'mutation':
            expected_requests.append({'method': case['method'], 'path': '/api/v1' + case['path'], 'body': case['body']})
    assert json.dumps(requests, sort_keys=True) == json.dumps(expected_requests, sort_keys=True), (name, requests, expected_requests)
    save(output / 'requests.json', requests)
    save(output / 'result.json', {'passed': True, 'expected_exit': expected, 'candidate': candidate})
    records.append(name)
    print(json.dumps({'case': name, 'passed': True}), flush=True)


for case in cases:
    if args.group == 'rendering':
        for columns in [100, 24]:
            run(case, str(columns), columns=columns,
                contains=case['contains'] if columns == 100 else [case['contains'][0].split()[0]])
        run(case, 'plain', mode='plain')
    elif args.group == 'modes':
        run(case, 'json', mode='json')
        run(case, 'pipe', mode='pipe')
    elif args.group == 'liveness':
        run(case, 'delayed', delay=2.1)
    elif args.group == 'errors':
        run(case, 'human', failure='mutation' if case.get('consent') == '--force' else True, expected=1)
        run(case, 'json', mode='json', failure='mutation' if case.get('consent') == '--force' else True, expected=1)
        if case.get('consent') == '--force':
            run(case, 'preflight-human', failure=True, expected=1)
            run(case, 'preflight-json', mode='json', failure=True, expected=1)
    elif args.group == 'empty' and case['argv'][0].endswith(':list'):
        run(case, 'human', empty=True)
        run(case, 'json', mode='json', empty=True)
    elif args.group == 'invalid':
        run(case, 'invalid', mode='json', invalid='parser', expected=1)
        run(case, 'profile-missing', mode='json', invalid='profile', expected=1)
        if case['required']:
            run(case, 'required-missing', mode='json', invalid='required', expected=1)
        if case.get('invalid_argv'):
            run(case, 'input-invalid', mode='json', invalid='input', expected=1)
    elif args.group == 'consent' and case.get('consent'):
        for suffix, key in [('default-no', '\r'), ('ctrl-c', '\x03'), ('eof', '\x04')]:
            run(case, suffix, consent=False, expected=1, inputs=[{'wait_for': case['prompt'], 'send': key}], contains=['cancelled.'])
        run(case, 'yes', consent=False, inputs=[{'wait_for': case['prompt'], 'send': 'y'}, {'wait_for': 'Yes', 'send': '\r'}])
        run(case, 'plain-yes', mode='plain', consent=False,
            inputs=[{'wait_for': case['prompt'], 'send': 'y'}, {'wait_for': 'Yes', 'send': '\r'}])
        run(case, 'json-refusal', mode='json', consent=False, expected=1)
save(root / 'result.json', {'candidate': candidate, 'group': args.group, 'cases': records, 'passed': True})
server.shutdown()
