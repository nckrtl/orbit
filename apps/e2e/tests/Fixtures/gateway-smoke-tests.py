"""Exercise bin/gateway-smoke against a fake Gateway: an HTTP server, an Orbit CLI, and systemctl."""
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

REPOSITORY = Path(sys.argv.pop(1)).resolve()
SMOKE = REPOSITORY / 'bin/gateway-smoke'
SHA = '2f214816deaed1d961f4a64c7f40762088c64226'
RELEASE = SHA[:12]
ASSET = '/assets/index-AbCdEf12.js'
INDEX = f'<!doctype html><html><head><script type="module" src="{ASSET}"></script></head><body><div id="app"></div></body></html>'.encode()
ASSET_BODY = b'console.log("orbit");\n'
READ_CHECKS = ['deploy_verify', 'node_list', 'tasks_list', 'web', 'scheduler', 'tasks_tick', 'agent_view']

FAKE_ORBIT = r'''#!/usr/bin/env python3
import json, os, sys, time
from pathlib import Path
fake = Path(os.environ['SMOKE_FAKE'])
command = sys.argv[1]
with open(fake / 'orbit.log', 'a') as log:
    log.write(json.dumps(sys.argv[1:]) + '\n')
with open(fake / 'orbit.pids', 'a') as pids:
    pids.write(f'{os.getpid()}\n')
path = fake / 'orbit' / (command.replace(':', '-') + '.json')
responses = json.loads(path.read_text())
if isinstance(responses, list):
    counter = fake / 'orbit' / (command.replace(':', '-') + '.count')
    index = int(counter.read_text()) if counter.exists() else 0
    counter.write_text(str(index + 1))
    responses = responses[min(index, len(responses) - 1)]
time.sleep(responses.get('sleep', 0))
sys.stdout.write(json.dumps(responses['stdout']))
sys.exit(responses.get('exit', 0))
'''

FAKE_SYSTEMCTL = r'''#!/usr/bin/env python3
import os, sys, time
from pathlib import Path
fake = Path(os.environ['SMOKE_FAKE']) / 'systemctl'
sleep = fake / 'sleep'
if sleep.exists():
    time.sleep(float(sleep.read_text()))
arguments = sys.argv[1:]
if arguments[0] == 'list-units':
    sys.stdout.write((fake / 'list-units.txt').read_text())
elif arguments[0] == 'show':
    blocks = []
    for unit in arguments[arguments.index('--') + 1:]:
        path = fake / 'units' / (unit + '.env')
        blocks.append(path.read_text().strip() if path.exists() else f'Id={unit}\nLoadState=not-found\nActiveState=inactive\nSubState=dead\nExecMainStartTimestamp=')
    sys.stdout.write('\n\n'.join(blocks) + '\n')
else:
    sys.stderr.write('unsupported\n')
    sys.exit(1)
'''


FAKE_PHP = r'''#!/usr/bin/env python3
import json, os, sys, time
from pathlib import Path
# The smoke run gives artisan a clean environment, so the fake finds its state next to itself.
php = Path(__file__).resolve().parent.parent / 'fake/php'
with open(php / 'calls.log', 'a') as log:
    log.write(json.dumps({'argv': sys.argv[1:], 'cwd': os.getcwd(), 'environment': sorted(os.environ)}) + '\n')
sleep = php / 'sleep'
if sleep.exists():
    time.sleep(float(sleep.read_text()))
response = json.loads((php / 'schedule.json').read_text())
sys.stdout.write(response['stdout'])
sys.stderr.write(response.get('stderr', ''))
sys.exit(response.get('exit', 0))
'''

SCHEDULE = [
    {'expression': '* * * * *', 'command': "'/usr/bin/php8.5' 'artisan' tasks:tick", 'repeat_seconds': 10},
    {'expression': '*/10 * * * *', 'command': "'/usr/bin/php8.5' 'artisan' problems:collect", 'repeat_seconds': None},
]


def utc_iso(moment):
    return moment.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.%fZ')


def running(pid):
    """Whether a process exists and is not a zombie waiting for its parent."""
    if not Path('/proc').is_dir():
        try:
            os.kill(pid, 0)
        except ProcessLookupError:
            return False
        return True
    try:
        state = Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()[0]
    except (FileNotFoundError, ProcessLookupError, IndexError):
        return False
    return state != 'Z'


class Gateway(BaseHTTPRequestHandler):
    routes = {}

    def do_GET(self):
        route = self.routes.get(self.path.split('?')[0], {'status': 404, 'body': b'', 'type': 'text/plain'})
        time.sleep(route.get('delay', 0))
        body = route['body'] if isinstance(route['body'], bytes) else json.dumps(route['body']).encode()
        self.send_response(route['status'])
        self.send_header('Content-Type', route.get('type', 'application/json'))
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_args):
        return


class SmokeWorld:
    def __init__(self, root):
        self.root = Path(root)
        self.fake = self.root / 'fake'
        self.bin = self.root / 'bin'
        self.web = self.root / 'web'
        self.checkout = self.root / 'orbit'
        for directory in (self.fake / 'orbit', self.fake / 'systemctl/units', self.fake / 'php', self.bin, self.web / f'releases/{RELEASE}/assets'):
            directory.mkdir(parents=True)
        self.application = self.root / f'releases/{RELEASE}/apps/gateway'
        self.application.mkdir(parents=True)
        (self.application / 'artisan').write_text('<?php\n')
        self.checkout.symlink_to(self.root / f'releases/{RELEASE}')
        self.write_executable(self.bin / 'orbit', FAKE_ORBIT)
        self.write_executable(self.bin / 'systemctl', FAKE_SYSTEMCTL)
        self.write_executable(self.bin / 'php8.5', FAKE_PHP)
        self.schedule(SCHEDULE)
        # The scheduler's main process: its working directory is the release it runs from.
        self.processes = []
        self.scheduler_pid = self.process(self.checkout / 'apps/gateway')
        (self.web / f'releases/{RELEASE}/index.html').write_bytes(INDEX)
        (self.web / f'releases/{RELEASE}{ASSET}').write_bytes(ASSET_BODY)
        (self.web / 'current').symlink_to(f'releases/{RELEASE}')

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), type('Routes', (Gateway,), {'routes': {}}))
        self.server.daemon_threads = True
        self.server.block_on_close = False
        self.routes = self.server.RequestHandlerClass.routes
        self.url = f'http://127.0.0.1:{self.server.server_port}'
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

        self.now = datetime.now(timezone.utc)
        self.routes.update({
            '/up': {'status': 200, 'body': {'status': 'up'}},
            '/api/v1/gateway/status': {'status': 200, 'body': {'data': {'status': 'ok', 'version': SHA}}},
            '/': {'status': 200, 'body': INDEX, 'type': 'text/html; charset=utf-8'},
            ASSET: {'status': 200, 'body': ASSET_BODY, 'type': 'text/javascript'},
        })
        self.orbit('node:list', {'nodes': [{'id': 1, 'name': 'gateway'}], 'request_id': 'r'})
        self.orbit('tasks:list', {'task_groups': [], 'request_id': 'r'})
        self.orbit('tasks:status', self.tick(seconds_ago=5))
        self.units(['orbit-process-7-scheduler.service', 'orbit-process-9-scheduler.service', 'orbit-process-12-queue.service'])
        self.unit('orbit-process-7-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory=self.checkout / 'apps/gateway')
        self.unit('orbit-process-9-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory='/home/orbit/apps/docs')
        self.unit('orbit-process-12-queue.service', argv='/usr/bin/php artisan queue:work', directory=self.checkout / 'apps/gateway')
        self.unit('orbit-agent-view.service', argv='/usr/bin/php artisan orbit:agent-view', directory=self.checkout / 'apps/gateway')

    @staticmethod
    def write_executable(path, text):
        path.write_text(text)
        path.chmod(0o755)

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        for process in self.processes:
            process.kill()
            process.wait()

    def process(self, directory):
        process = subprocess.Popen(['sleep', '300'], cwd=directory)
        self.processes.append(process)
        return process.pid

    def schedule(self, events, exit_code=0, stderr=''):
        stdout = events if isinstance(events, str) else json.dumps(events)
        (self.fake / 'php/schedule.json').write_text(json.dumps({'stdout': stdout, 'exit': exit_code, 'stderr': stderr}))

    def php_calls(self):
        log = self.fake / 'php/calls.log'
        return [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []

    def tick(self, seconds_ago=None, enabled=True):
        last = None if seconds_ago is None else utc_iso(self.now - timedelta(seconds=seconds_ago))
        return {'enabled': enabled, 'assistance': [], 'last_tick_at': last, 'request_id': 'r'}

    def orbit(self, command, stdout, exit_code=0, sleep=0, sequence=None):
        responses = sequence if sequence is not None else {'stdout': stdout, 'exit': exit_code, 'sleep': sleep}
        (self.fake / 'orbit' / (command.replace(':', '-') + '.json')).write_text(json.dumps(responses))

    def orbit_failure(self, command, code, message='Refused.', exit_code=1):
        self.orbit(command, {'error': {'code': code, 'message': message, 'request_id': None}}, exit_code)

    def units(self, names):
        (self.fake / 'systemctl/list-units.txt').write_text(''.join(f'{name} loaded active running Orbit\n' for name in names))

    def unit(self, name, argv, directory, active='active', sub='running', started_seconds_ago=30, pid=None):
        started = int((self.now - timedelta(seconds=started_seconds_ago)).timestamp())
        if pid is None:
            pid = getattr(self, 'scheduler_pid', 0)
        (self.fake / 'systemctl/units' / (name + '.env')).write_text('\n'.join([
            f'Id={name}',
            f'MainPID={pid}',
            'LoadState=loaded',
            f'ActiveState={active}',
            f'SubState={sub}',
            f'ExecMainStartTimestamp=@{started}',
            f'ExecStart={{ path={argv.split()[0]} ; argv[]={argv} ; ignore_errors=no ; start_time=[n/a] ; stop_time=[n/a] ; pid=0 ; code=(null) ; status=0/0 }}',
            f'WorkingDirectory={directory}',
        ]) + '\n')

    def orbit_calls(self):
        log = self.fake / 'orbit.log'
        return [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []

    def smoke_argv(self, *arguments):
        return [str(SMOKE), '--sha', SHA,
                '--up-url', f'{self.url}/up', '--status-url', f'{self.url}/api/v1/gateway/status',
                '--web-url', f'{self.url}/', '--web-dir', str(self.web), '--checkout', str(self.checkout),
                '--orbit', str(self.bin / 'orbit'), *arguments]

    def smoke_environment(self):
        return {**os.environ, 'PATH': f'{self.bin}:{os.environ["PATH"]}', 'SMOKE_FAKE': str(self.fake), 'PYTHONDONTWRITEBYTECODE': '1', 'ORBIT_SMOKE_PHP': ''}

    def start_smoke(self, *arguments):
        return subprocess.Popen(self.smoke_argv(*arguments), stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=self.smoke_environment())

    def orbit_pids(self):
        path = self.fake / 'orbit.pids'
        return [int(line) for line in path.read_text().split()] if path.exists() else []

    def smoke(self, *arguments, timeout=60):
        started = time.monotonic()
        process = subprocess.run(
            self.smoke_argv(*arguments),
            capture_output=True, text=True, env=self.smoke_environment(), timeout=timeout,
        )
        process.elapsed = time.monotonic() - started
        process.json = json.loads(process.stdout) if process.stdout.strip().startswith('{') else None
        return process


class GatewaySmokeTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.mkdtemp(prefix='orbit-gateway-smoke-')
        self.world = SmokeWorld(self.directory)

    def tearDown(self):
        self.world.close()
        shutil.rmtree(self.directory, ignore_errors=True)

    def assert_only_failed(self, process, failed, status='failed'):
        self.assertEqual(process.returncode, 1, process.stderr)
        payload = process.json
        self.assertFalse(payload['passed'])
        self.assertEqual(payload['error'], 'checks_failed')
        self.assertEqual(payload['failed_checks'], failed)
        self.assertIn('Do not treat the release as healthy', process.stderr)
        for name in failed:
            self.assertEqual(payload['checks'][name]['status'], status, payload['checks'][name])
        for name in READ_CHECKS:
            if name not in failed:
                self.assertIn(payload['checks'][name]['status'], ('passed', 'skipped'), payload['checks'][name])
        return payload['checks']

    def test_every_read_check_passes_and_writes_nothing(self):
        process = self.world.smoke()

        self.assertEqual(process.returncode, 0, process.stdout + process.stderr)
        payload = process.json
        self.assertTrue(payload['passed'])
        self.assertEqual(payload['schema'], 1)
        self.assertEqual(payload['source'], 'live')
        self.assertEqual(payload['expected_sha'], SHA)
        self.assertEqual(list(payload['checks']), [*READ_CHECKS, 'documents'])
        self.assertEqual(payload['summary'], {'passed': 7, 'failed': 0, 'timeout': 0, 'skipped': 1})
        for name in READ_CHECKS:
            check = payload['checks'][name]
            self.assertEqual(check['status'], 'passed', check)
            self.assertIsNone(check['error'])
            self.assertEqual(set(check), {'status', 'error', 'message', 'duration_ms', 'detail'})
        self.assertEqual(payload['checks']['documents']['status'], 'skipped')
        self.assertEqual(payload['checks']['scheduler']['detail']['unit'], 'orbit-process-7-scheduler.service')
        self.assertEqual(payload['checks']['web']['detail']['current_release'], RELEASE)
        self.assertEqual(payload['checks']['deploy_verify']['detail']['app_version'], SHA)
        self.assertNotIn('error', payload)
        # A new scheduler first runs at the next full minute, so the release smoke does not wait for a tick.
        self.assertEqual(sorted({call[0] for call in self.world.orbit_calls()}), ['node:list', 'tasks:list'])
        self.assertTrue(all(call[-1] == '--json' for call in self.world.orbit_calls()))
        scheduler = payload['checks']['scheduler']['detail']
        self.assertEqual(scheduler['main_pid'], self.world.scheduler_pid)
        self.assertEqual(scheduler['process_path'], str(self.world.application.resolve()))
        tick = payload['checks']['tasks_tick']['detail']
        self.assertEqual(tick['scheduled'], 2)
        self.assertEqual(tick['tick_command'], "'/usr/bin/php8.5' 'artisan' tasks:tick")
        [call] = self.world.php_calls()
        self.assertEqual(call['argv'], [str(self.world.application.resolve() / 'artisan'), 'schedule:list', '--json', '--no-interaction'])
        self.assertEqual(call['cwd'], str(self.world.application.resolve()))
        # Like the release's own artisan commands, it reads the release's configuration, not the caller's environment.
        self.assertNotIn('SMOKE_FAKE', call['environment'])
        # Python itself may add LC_CTYPE when it coerces a C locale.
        self.assertLessEqual(set(call['environment']), {'HOME', 'PATH', 'LANG', 'NO_COLOR', 'LC_CTYPE'})

    def test_dry_run_calls_nothing(self):
        process = self.world.smoke('--dry-run', '--scheduler-unit', 'orbit-process-7-scheduler.service')

        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertTrue(process.json['dry_run'])
        self.assertEqual(process.json['source'], 'dry-run')
        self.assertFalse(process.json['checks']['documents']['runs'])
        self.assertEqual(process.json['checks']['scheduler']['unit'], 'orbit-process-7-scheduler.service')
        self.assertEqual(self.world.orbit_calls(), [])

    def test_deploy_verify_fails_on_another_live_version(self):
        self.world.routes['/api/v1/gateway/status'] = {'status': 200, 'body': {'data': {'status': 'ok', 'version': 'ffffffffffff'}}}

        checks = self.assert_only_failed(self.world.smoke(), ['deploy_verify'])

        self.assertEqual(checks['deploy_verify']['error'], 'version_mismatch')
        self.assertEqual(checks['deploy_verify']['detail']['app_version'], 'ffffffffffff')

    def test_deploy_verify_fails_when_up_is_down(self):
        self.world.routes['/up'] = {'status': 503, 'body': {'status': 'down'}}

        checks = self.assert_only_failed(self.world.smoke(), ['deploy_verify'])

        self.assertEqual(checks['deploy_verify']['error'], 'up_failed')

    def test_node_list_fails_with_the_cli_error(self):
        self.world.orbit_failure('node:list', 'gateway.unreachable', 'The Gateway did not answer.')

        checks = self.assert_only_failed(self.world.smoke(), ['node_list'])

        self.assertEqual(checks['node_list']['error'], 'cli_failed')
        self.assertIn('gateway.unreachable', checks['node_list']['message'])
        self.assertEqual(checks['node_list']['detail']['cli_error']['code'], 'gateway.unreachable')

    def test_node_list_fails_without_nodes(self):
        self.world.orbit('node:list', {'nodes': [], 'request_id': 'r'})

        checks = self.assert_only_failed(self.world.smoke(), ['node_list'])

        self.assertEqual(checks['node_list']['error'], 'cli_unexpected')

    def test_tasks_list_fails_with_the_cli_error_and_skips_when_tasks_are_disabled(self):
        self.world.orbit_failure('tasks:list', 'tasks.unavailable')
        checks = self.assert_only_failed(self.world.smoke(), ['tasks_list'])
        self.assertEqual(checks['tasks_list']['error'], 'cli_failed')

        self.world.orbit_failure('tasks:list', 'extension.disabled', 'The tasks extension is disabled.')
        self.world.orbit('tasks:status', self.world.tick(enabled=False))
        process = self.world.smoke('--wait-for-tick')

        self.assertEqual(process.returncode, 0, process.stdout)
        self.assertEqual(process.json['checks']['tasks_list']['status'], 'skipped')
        self.assertEqual(process.json['checks']['tasks_tick']['status'], 'skipped')
        self.assertEqual(process.json['summary']['skipped'], 3)

    def test_web_fails_when_caddy_serves_another_release(self):
        self.world.routes['/'] = {'status': 200, 'body': INDEX.replace(b'id="app"', b'id="old"'), 'type': 'text/html'}

        checks = self.assert_only_failed(self.world.smoke(), ['web'])

        self.assertEqual(checks['web']['error'], 'web_not_current')

    def test_web_fails_when_the_hashed_asset_is_missing(self):
        del self.world.routes[ASSET]

        checks = self.assert_only_failed(self.world.smoke(), ['web'])

        self.assertEqual(checks['web']['error'], 'web_asset_failed')
        self.assertEqual(checks['web']['detail']['asset']['status_code'], 404)

    def test_web_fails_without_a_current_release(self):
        (self.world.web / 'current').unlink()

        checks = self.assert_only_failed(self.world.smoke(), ['web'])

        self.assertEqual(checks['web']['error'], 'web_current_missing')

    def test_web_fails_when_current_is_the_build_of_another_commit(self):
        previous = self.world.web / 'releases/0123456789ab'
        shutil.copytree(self.world.web / f'releases/{RELEASE}', previous)
        (self.world.web / 'current').unlink()
        (self.world.web / 'current').symlink_to('releases/0123456789ab')

        checks = self.assert_only_failed(self.world.smoke(), ['web'])

        self.assertEqual(checks['web']['error'], 'web_release_mismatch')
        self.assertEqual(checks['web']['detail']['current_release'], '0123456789ab')
        self.assertEqual(checks['web']['detail']['expected_release'], RELEASE)

    def test_scheduler_fails_when_inactive_missing_or_ambiguous(self):
        world = self.world
        world.unit('orbit-process-7-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory=world.checkout / 'apps/gateway', active='failed', sub='failed')
        checks = self.assert_only_failed(world.smoke(), ['scheduler'])
        self.assertEqual(checks['scheduler']['error'], 'unit_inactive')

        world.units(['orbit-process-9-scheduler.service', 'orbit-process-12-queue.service'])
        checks = self.assert_only_failed(world.smoke(), ['scheduler'])
        self.assertEqual(checks['scheduler']['error'], 'scheduler_missing')

        world.units(['orbit-process-7-scheduler.service', 'orbit-process-8-scheduler.service'])
        world.unit('orbit-process-7-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory=world.checkout / 'apps/gateway')
        world.unit('orbit-process-8-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory=world.root / f'releases/{RELEASE}/apps/gateway')
        checks = self.assert_only_failed(world.smoke(), ['scheduler'])
        self.assertEqual(checks['scheduler']['error'], 'scheduler_ambiguous')

        process = world.smoke('--scheduler-unit', 'orbit-process-8-scheduler.service')
        self.assertEqual(process.returncode, 0, process.stdout)
        self.assertFalse(process.json['checks']['scheduler']['detail']['discovered'])

        checks = self.assert_only_failed(world.smoke('--scheduler-unit', 'orbit-process-99-gone.service'), ['scheduler'])
        self.assertEqual(checks['scheduler']['error'], 'unit_missing')

    def test_since_requires_restarted_units_and_a_later_tick(self):
        world = self.world
        since = utc_iso(world.now - timedelta(seconds=10))
        world.orbit('tasks:status', world.tick(seconds_ago=5))
        world.unit('orbit-process-7-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory=world.checkout / 'apps/gateway', started_seconds_ago=8)
        world.unit('orbit-agent-view.service', argv='/usr/bin/php artisan orbit:agent-view', directory=world.checkout / 'apps/gateway', started_seconds_ago=7)
        process = world.smoke('--since', since)
        self.assertEqual(process.returncode, 0, process.stdout)
        self.assertEqual(process.json['since'], since[:19] + 'Z')

        world.unit('orbit-agent-view.service', argv='/usr/bin/php artisan orbit:agent-view', directory=world.checkout / 'apps/gateway', started_seconds_ago=600)
        checks = self.assert_only_failed(world.smoke('--since', since), ['agent_view'])
        self.assertEqual(checks['agent_view']['error'], 'unit_not_restarted')

        world.unit('orbit-agent-view.service', argv='/usr/bin/php artisan orbit:agent-view', directory=world.checkout / 'apps/gateway', started_seconds_ago=7)
        world.orbit('tasks:status', world.tick(seconds_ago=20))
        process = world.smoke('--since', since, '--timeout', '3')
        self.assertEqual(process.returncode, 0, process.stdout)
        checks = self.assert_only_failed(world.smoke('--since', since, '--timeout', '3', '--wait-for-tick'), ['tasks_tick'])
        self.assertEqual(checks['tasks_tick']['error'], 'tick_stale')
        self.assertEqual(checks['tasks_tick']['detail']['tick_after'], since[:19] + 'Z')

    def test_tasks_tick_waits_for_a_fresh_tick(self):
        self.world.orbit('tasks:status', None, sequence=[
            {'stdout': self.world.tick(seconds_ago=600)},
            {'stdout': self.world.tick(seconds_ago=0)},
        ])

        process = self.world.smoke('--wait-for-tick')

        self.assertEqual(process.returncode, 0, process.stdout)
        self.assertEqual(process.json['checks']['tasks_tick']['detail']['reads'], 2)
        self.assertEqual(self.world.php_calls(), [])

    def test_tasks_tick_fails_when_no_tick_started_within_the_limit(self):
        self.world.orbit('tasks:status', self.world.tick(seconds_ago=None))

        checks = self.assert_only_failed(self.world.smoke('--timeout', '4', '--wait-for-tick'), ['tasks_tick'])

        self.assertEqual(checks['tasks_tick']['error'], 'tick_stale')
        self.assertIsNone(checks['tasks_tick']['detail']['last_tick_at'])
        self.assertGreaterEqual(checks['tasks_tick']['detail']['reads'], 2)

    def test_tasks_tick_fails_on_a_gateway_without_the_tick_record(self):
        self.world.orbit('tasks:status', {'enabled': True, 'assistance': [], 'request_id': 'r'})

        checks = self.assert_only_failed(self.world.smoke('--wait-for-tick'), ['tasks_tick'])

        self.assertEqual(checks['tasks_tick']['error'], 'tick_unreported')

    def test_tasks_tick_fails_when_the_release_schedules_no_tick(self):
        self.world.schedule([event for event in SCHEDULE if 'tasks:tick' not in event['command']])

        checks = self.assert_only_failed(self.world.smoke(), ['tasks_tick'])

        self.assertEqual(checks['tasks_tick']['error'], 'tick_unscheduled')
        self.assertEqual(checks['tasks_tick']['detail']['scheduled'], 1)

    def test_tasks_tick_matches_the_tick_command_and_no_similar_one(self):
        self.world.schedule([{'expression': '* * * * *', 'command': 'php artisan tasks:tick', 'repeat_seconds': 10}])
        process = self.world.smoke()
        self.assertEqual(process.returncode, 0, process.stdout)
        self.assertEqual(process.json['checks']['tasks_tick']['detail']['tick_command'], 'php artisan tasks:tick')

        self.world.schedule([{'expression': '* * * * *', 'command': 'php artisan tasks:tickets', 'repeat_seconds': None},
                             {'expression': '* * * * *', 'command': 'php artisan tasks:tick-report', 'repeat_seconds': None}])
        checks = self.assert_only_failed(self.world.smoke(), ['tasks_tick'])
        self.assertEqual(checks['tasks_tick']['error'], 'tick_unscheduled')

    def test_a_checkout_that_names_the_gateway_application_finds_the_same_release(self):
        # ORBIT_GATEWAY_CHECKOUT, the default of --checkout, names apps/gateway below the release link.
        process = self.world.smoke('--checkout', str(self.world.checkout / 'apps/gateway'))

        self.assertEqual(process.returncode, 0, process.stdout)
        self.assertEqual(process.json['checks']['scheduler']['detail']['release_path'], str(self.world.application.resolve()))
        self.assertEqual(process.json['checks']['tasks_tick']['detail']['application'], str(self.world.application.resolve()))

    def test_tasks_tick_fails_when_the_release_cannot_load_its_schedule(self):
        self.world.schedule('', exit_code=255, stderr='PHP Fatal error: Class "App\\Domain\\Tasks\\TaskSchedule" not found')

        checks = self.assert_only_failed(self.world.smoke(), ['tasks_tick'])

        self.assertEqual(checks['tasks_tick']['error'], 'schedule_unreadable')
        self.assertEqual(checks['tasks_tick']['detail']['exit_code'], 255)
        self.assertIn('TaskSchedule', checks['tasks_tick']['detail']['stderr'])

        self.world.schedule('Nothing is scheduled.')
        checks = self.assert_only_failed(self.world.smoke(), ['tasks_tick'])
        self.assertEqual(checks['tasks_tick']['error'], 'schedule_unreadable')

    def test_tasks_tick_fails_without_php(self):
        (self.world.bin / 'php8.5').unlink()
        # The host's own PHP must not be found, so PATH holds only the fakes and Python.
        python = self.world.root / 'python'
        python.mkdir()
        (python / 'python3').symlink_to(sys.executable)
        environment = {**self.world.smoke_environment(), 'PATH': f'{self.world.bin}:{python}'}
        process = subprocess.run(self.world.smoke_argv('--skip', 'deploy_verify'), capture_output=True, text=True, env=environment, timeout=60)

        checks = json.loads(process.stdout)['checks']
        self.assertEqual(checks['tasks_tick']['error'], 'php_unavailable')

    def test_scheduler_fails_when_its_process_runs_another_release(self):
        world = self.world
        previous = world.root / 'releases/0123456789ab/apps/gateway'
        previous.mkdir(parents=True)
        world.unit('orbit-process-7-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory=world.checkout / 'apps/gateway', pid=world.process(previous))

        checks = self.assert_only_failed(world.smoke(), ['scheduler'])

        self.assertEqual(checks['scheduler']['error'], 'scheduler_old_release')
        self.assertEqual(checks['scheduler']['detail']['process_path'], str(previous.resolve()))
        self.assertEqual(checks['scheduler']['detail']['release_path'], str(world.application.resolve()))

    def test_scheduler_fails_when_its_process_is_unknown(self):
        world = self.world
        world.unit('orbit-process-7-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory=world.checkout / 'apps/gateway', pid=0)
        checks = self.assert_only_failed(world.smoke(), ['scheduler'])
        self.assertEqual(checks['scheduler']['error'], 'scheduler_pid_unknown')

        gone = world.process(world.application)
        world.processes[-1].kill()
        world.processes[-1].wait()
        world.unit('orbit-process-7-scheduler.service', argv='/usr/bin/php artisan schedule:work', directory=world.checkout / 'apps/gateway', pid=gone)
        checks = self.assert_only_failed(world.smoke(), ['scheduler'])
        self.assertEqual(checks['scheduler']['error'], 'scheduler_release_unknown')

    def test_agent_view_fails_when_inactive(self):
        self.world.unit('orbit-agent-view.service', argv='/usr/bin/php artisan orbit:agent-view', directory=self.world.checkout / 'apps/gateway', active='activating', sub='auto-restart')

        checks = self.assert_only_failed(self.world.smoke(), ['agent_view'])

        self.assertEqual(checks['agent_view']['error'], 'unit_inactive')
        self.assertEqual(checks['agent_view']['detail']['sub_state'], 'auto-restart')

    def test_every_check_times_out_within_the_total_limit(self):
        world = self.world
        for route in world.routes.values():
            route['delay'] = 30
        for command in ('node:list', 'tasks:list', 'tasks:status', 'project:document:create'):
            world.orbit(command, {}, sleep=30)
        (world.fake / 'systemctl/sleep').write_text('30')
        (world.fake / 'php/sleep').write_text('30')

        process = world.smoke('--timeout', '3', '--write-check', '--smoke-project', 'gateway-smoke')

        names = [*READ_CHECKS, 'documents']
        checks = self.assert_only_failed(process, names, status='timeout')
        self.assertLess(process.elapsed, 10)
        self.assertEqual(process.json['summary'], {'passed': 0, 'failed': 0, 'timeout': 8, 'skipped': 0})
        for name in names:
            self.assertEqual(checks[name]['error'], 'timeout', checks[name])

    def test_termination_kills_every_running_check_command(self):
        world = self.world
        for command in ('node:list', 'tasks:list', 'tasks:status'):
            world.orbit(command, {}, sleep=30)

        process = world.start_smoke('--timeout', '60', '--wait-for-tick')
        deadline = time.monotonic() + 10
        while len(world.orbit_pids()) < 3 and time.monotonic() < deadline:
            time.sleep(0.05)
        commands = world.orbit_pids()
        process.send_signal(signal.SIGTERM)
        stdout, _stderr = process.communicate(timeout=10)

        self.assertEqual(len(commands), 3)
        self.assertEqual(process.returncode, 128 + signal.SIGTERM)
        self.assertEqual(json.loads(stdout)['error'], 'terminated')
        deadline = time.monotonic() + 3
        while any(running(pid) for pid in commands) and time.monotonic() < deadline:
            time.sleep(0.05)
        self.assertEqual([pid for pid in commands if running(pid)], [])

    def write_check_world(self):
        world = self.world
        world.orbit('project:document:create', {'data': {'id': 41, 'name': 'n', 'revision': 1}})
        world.orbit('project:document:update', {'data': {'id': 41, 'name': None, 'revision': 2}})
        world.orbit('project:document:show', {'data': {'id': 41, 'revision': 2}})
        world.orbit('project:document:remove', {'data': {'id': 41, 'removed': True, 'cleanup_pending': True}})

    def test_write_check_creates_reads_renames_and_removes_one_document(self):
        world = self.world
        # This fake echoes the written content and the new name back, as the Gateway does.
        echo = r'''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
fake = Path(os.environ['SMOKE_FAKE'])
with open(fake / 'orbit.log', 'a') as log:
    log.write(json.dumps(sys.argv[1:]) + '\n')
command, options = sys.argv[1], dict(a[2:].split('=', 1) for a in sys.argv[2:] if a.startswith('--') and '=' in a)
state = fake / 'document.json'
if command == 'project:document:create':
    state.write_text(json.dumps({'content': options['content'], 'name': sys.argv[3]}))
    print(json.dumps({'data': {'id': 41, 'name': sys.argv[3], 'revision': 1}}))
elif command == 'project:document:read':
    print(json.dumps({'data': {'entry_id': 41, 'revision': 1, 'content_text': json.loads(state.read_text())['content']}}))
elif command == 'project:document:update':
    print(json.dumps({'data': {'id': 41, 'name': options['name'], 'revision': 2}}))
elif command == 'project:document:remove':
    print(json.dumps({'data': {'id': 41, 'removed': True, 'cleanup_pending': True}}))
else:
    sys.exit(1)
'''
        documents = world.bin / 'orbit-documents'
        world.write_executable(documents, echo)

        process = world.smoke('--write-check', '--smoke-project', 'gateway-smoke', '--orbit', str(documents),
                              '--skip', 'node_list', '--skip', 'tasks_list', '--skip', 'tasks_tick')

        self.assertEqual(process.returncode, 0, process.stdout + process.stderr)
        documents_check = process.json['checks']['documents']
        self.assertEqual(documents_check['status'], 'passed', documents_check)
        self.assertEqual(documents_check['detail']['steps'], ['create', 'read', 'update', 'remove'])
        self.assertEqual(documents_check['detail']['cleanup'], 'not_needed')
        calls = [call for call in world.orbit_calls() if call[0].startswith('project:document:')]
        self.assertEqual([call[0] for call in calls], ['project:document:create', 'project:document:read', 'project:document:update', 'project:document:remove'])
        self.assertTrue(all(call[1] == 'gateway-smoke' for call in calls))
        self.assertIn('--expected-revision=1', calls[2])
        self.assertIn('--expected-revision=2', calls[3])
        self.assertIn('--yes', calls[3])
        self.assertTrue(calls[0][2].startswith(f'gateway-smoke-{RELEASE}-'))

    def test_write_check_removes_its_document_when_a_step_fails(self):
        world = self.world
        self.write_check_world()
        world.orbit('project:document:read', {'data': {'entry_id': 41, 'revision': 1, 'content_text': 'other'}})

        checks = self.assert_only_failed(world.smoke('--write-check', '--smoke-project', 'gateway-smoke'), ['documents'])

        self.assertEqual(checks['documents']['error'], 'document_mismatch')
        self.assertEqual(checks['documents']['detail']['cleanup'], 'removed')
        self.assertEqual(checks['documents']['detail']['steps'], ['create'])
        self.assertEqual([call[0] for call in world.orbit_calls() if call[0].startswith('project:document:')],
                         ['project:document:create', 'project:document:read', 'project:document:show', 'project:document:remove'])

    def test_write_check_names_a_document_it_could_not_remove(self):
        world = self.world
        self.write_check_world()
        world.orbit('project:document:read', {'data': {'entry_id': 41, 'revision': 1, 'content_text': 'other'}})
        world.orbit_failure('project:document:show', 'project_documents.not_found')

        checks = self.assert_only_failed(world.smoke('--write-check', '--smoke-project', 'gateway-smoke'), ['documents'])

        self.assertEqual(checks['documents']['detail']['cleanup'], 'failed')
        self.assertIn('Entry 41 in Project gateway-smoke was left behind', checks['documents']['message'])

    def test_wrong_flags_exit_2_with_a_next_step(self):
        cases = [
            (['--write-check'], '--write-check and --smoke-project go together.'),
            (['--smoke-project', 'gateway-smoke'], '--write-check and --smoke-project go together.'),
            (['--since', '2026-10-07T06:00:00'], 'is not an ISO 8601 time with a zone.'),
            (['--scheduler-unit', 'scheduler; reboot'], 'is not a systemd service name.'),
            ([f'--skip={name}' for name in READ_CHECKS], 'Every read check is skipped.'),
        ]
        for arguments, message in cases:
            with self.subTest(arguments=arguments):
                process = self.world.smoke(*arguments)
                self.assertEqual(process.returncode, 2, process.stdout)
                self.assertEqual(process.json['error'], 'usage')
                self.assertIn(message, process.json['message'])
                self.assertEqual(process.stderr.strip(), process.json['next'])
        self.assertEqual(self.world.orbit_calls(), [])

        unknown = self.world.smoke('--skip', 'everything')
        self.assertEqual(unknown.returncode, 2)
        self.assertIn('invalid choice', unknown.stderr)


if __name__ == '__main__':
    unittest.main(verbosity=1)
