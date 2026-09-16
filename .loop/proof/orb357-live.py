#!/usr/bin/env python3
"""Record ORB-357 database/env CLI UX on a disposable proof topology."""
from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import shutil
import signal
import subprocess
import sys
import termios
import time
from pathlib import Path

CANDIDATE = '4677890b8b35afd8e23927472d7628c8e6b18515'
REQUEST_ID = re.compile(
    r'\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z',
    re.I,
)
SENTINEL = 'ux357-db-secret-not-for-output'
ROOT_SECRET = 'ux357-root-secret'
USER_SECRET = 'ux357-user-secret'
SIGINT_EXIT = 128 + signal.SIGINT
SIGTERM_EXIT = 128 + signal.SIGTERM
FIXTURE_DIR = Path(__file__).resolve().parent
LIST_ANIMATION = {
    'name': 'list',
    'pattern': r'(?P<glyph>[○◉]) Loading Database connections',
    'terminal_pattern': r'● Loaded Database connections',
    'minimum_changes': 2,
    'min_interval': 0.15,
    'max_interval': 0.65,
}

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    stderr_file = open(os.environ['ORB357_CAPTURE_STDERR'], 'wb') if os.environ.get('ORB357_CAPTURE_STDERR') else None
    child = subprocess.Popen(sys.argv[3:], stderr=stderr_file)

    def forward(signum, _frame):
        try:
            child.send_signal(signum)
        except ProcessLookupError:
            pass

    signal.signal(signal.SIGINT, forward)
    signal.signal(signal.SIGTERM, forward)
    delay = float(os.environ.get('ORB357_SIGNAL_AFTER') or 0)
    sig_name = os.environ.get('ORB357_SIGNAL')
    marker = os.environ.get('ORB357_PENDING_MARKER')
    after_arrival = float(os.environ.get('ORB357_SIGNAL_AFTER_ARRIVAL') or 0)
    if sig_name and (delay > 0 or marker):
        import threading

        def killer():
            if marker:
                deadline = time.monotonic() + 30
                while time.monotonic() < deadline:
                    if Path(marker).exists():
                        time.sleep(after_arrival)
                        break
                    time.sleep(0.02)
                else:
                    return
            elif delay > 0:
                time.sleep(delay)
            try:
                child.send_signal(getattr(signal, sig_name))
            except ProcessLookupError:
                pass
            if marker:
                Path(marker + '.signaled').write_text(json.dumps({'signaled': time.time()}) + '\n')

        threading.Thread(target=killer, daemon=True).start()
    code = child.wait()
    if stderr_file is not None:
        stderr_file.close()
    after = termios.tcgetattr(0)
    Path(sys.argv[2]).write_text(json.dumps({'before': repr(before), 'after': repr(after), 'equal': before == after, 'exit': code}))
    sys.exit(code)

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--stage', required=True, choices=[
    'prepare', 'registry', 'query-sqlite', 'inspect', 'mysql', 'attach', 'consent', 'env',
    'liveness', 'native-visual', 'coverage-report',
])
parser.add_argument('--visible', action='store_true')
args = parser.parse_args()
assert args.candidate == CANDIDATE, args.candidate
os.umask(0o077)
source = Path('/home/orbit/orbit')
assert subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() == args.candidate
root = args.state / args.stage
root.mkdir(parents=True)
private = root / 'gateway-home'
private.mkdir()
shutil.copyfile(Path.home() / '.orbit/config.json', private / 'config.json')
env = dict(os.environ, ORBIT_HOME=str(private), TERM='xterm-256color', LC_ALL='C.UTF-8', PAO_DISABLE='1')
for key in ['NO_COLOR', 'FORCE_COLOR', 'CLICOLOR', 'COLUMNS', 'LINES']:
    env.pop(key, None)
launcher = source / 'apps/cli/orbit'
recorder = source / '.agents/skills/verifying-cli-output/scripts'
python = sys.executable
records = []


def save(path: Path, value) -> None:
    path.write_text(json.dumps(value, indent=2) + '\n')


def read(*argv, expected=0, error=None):
    command = ['php', str(launcher), *map(str, argv), '--json']
    result = subprocess.run(command, cwd=source, env=env, capture_output=True, input=b'', timeout=240)
    assert result.returncode == expected, (argv, result.returncode, result.stdout, result.stderr)
    assert result.stderr == b'' and b'\x1b' not in result.stdout, (argv, result.stderr)
    payload = json.loads(result.stdout)
    if error:
        assert payload['error']['code'] == error, (argv, payload)
    return payload


def record_pty(label, argv, *, expected=0, contains=None, absent=None, error_code=None, actual_gateway=True, input_actions=None, columns=100, compact_contains=None, plain=False, rows=40, animation=None):
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--no-ansi' if plain else '--ansi']
    capture = [python, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
               '--candidate', args.candidate, '--label', label, '--columns', str(columns), '--rows', str(rows),
               '--timeout', '240', '--idle-timeout', '60']
    if not args.visible:
        capture.append('--no-live')
    if input_actions is not None:
        save(out / 'inputs.json', input_actions)
        capture += ['--input-plan', str(out / 'inputs.json')]
    capture += ['--', python, str(Path(__file__).resolve()), '--child', str(out / 'terminal.json'), *command]
    print('$ ' + shlex.join(command), flush=True)
    capture_env = dict(env)
    if '--json' in argv:
        capture_env['ORB357_CAPTURE_STDERR'] = str(out / 'stderr.bin')
    result = subprocess.run(capture, cwd=source, env=capture_env, capture_output=not args.visible, timeout=255)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal'], label
    raw = (out / 'capture/raw.bin').read_bytes()
    text = raw.decode('utf-8', 'replace')
    for secret in [SENTINEL, ROOT_SECRET, USER_SECRET]:
        assert secret not in text, (label, 'secret leaked', secret)
    if '--json' in argv:
        assert (out / 'stderr.bin').read_bytes() == b'', label
        assert b'\x1b' not in raw, label
        machine = json.loads(raw)
        if error_code:
            assert machine['error']['code'] == error_code, (label, machine)
        else:
            assert 'error' not in machine, (label, machine)
            assert REQUEST_ID.fullmatch(machine.get('request_id') or ''), (label, machine)
        save(out / 'payload.json', machine)
    if contains:
        for token in contains:
            assert token in text, (label, token, text[-800:])
    if compact_contains:
        compact = ''.join(re.sub(r'\x1b\[[0-9;]*m', '', text).split())
        for token in compact_contains:
            assert ''.join(token.split()) in compact, (label, token, compact[-400:])
    if absent:
        for token in absent:
            assert token not in text, (label, token)
    frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
    assert frames[-1]['cursor']['hidden'] is False, label
    expectation = {'candidate': args.candidate, 'label': label, 'exit_code': expected, 'max_first_output_seconds': 3}
    if contains:
        expectation['contains'] = contains
    if '--json' in argv:
        expectation['absent'] = ['Request ID:']
    if animation and not plain:
        expectation['animation_rows'] = [animation]
    save(out / 'expectation.json', expectation)
    verified = subprocess.run(
        [python, str(recorder / 'verify.py'), '--capture', str(out / 'capture'), '--expect', str(out / 'expectation.json')],
        capture_output=True, text=True,
    )
    (out / 'verify.json').write_text(verified.stdout)
    assert verified.returncode == 0, (label, verified.stdout, verified.stderr)
    record = {'label': label, 'argv': command, 'candidate': args.candidate, 'exit': expected, 'passed': True}
    records.append(record)
    save(root / 'records.json', records)
    print(json.dumps({'label': label, 'passed': True}), flush=True)
    return text


def app_dev():
    nodes = read('node:list')['nodes']
    return next(n for n in nodes if 'app-dev' in n.get('roles', []))


def pipe(label, argv, *, expected=0, contains=None, error=None):
    command = ['php', str(launcher), *map(str, argv)]
    print('$ ' + shlex.join(command) + ' [pipe]', flush=True)
    result = subprocess.run(command, cwd=source, env=env, input=b'', capture_output=True, timeout=240)
    text = result.stdout.decode()
    assert result.returncode == expected, (label, result.returncode, text, result.stderr)
    assert result.stderr == b'', (label, result.stderr)
    for secret in [SENTINEL, ROOT_SECRET, USER_SECRET]:
        assert secret not in text and secret.encode() not in result.stderr, (label, secret)
    if '--json' in argv:
        payload = json.loads(result.stdout)
        if error:
            assert payload['error']['code'] == error, (label, payload)
        save(root / (label + '.json'), payload)
    elif contains:
        for token in contains:
            assert token in text, (label, token, text)
    (root / (label + '.stdout')).write_bytes(result.stdout)
    (root / (label + '.stderr')).write_bytes(result.stderr)
    records.append({'label': label, 'argv': command, 'exit': expected, 'mode': 'pipe', 'passed': True})
    save(root / 'records.json', records)
    return result


def e2e_dev():
    return next(i for i in read('instance:list')['app_instances'] if i['name'] == 'e2e-dev')


def wait_mysql(process_id: int, node_id: int, timeout=180) -> dict:
    deadline = time.time() + timeout
    last = None
    listed = None
    while time.time() < deadline:
        listed = read('process:list', f'--node={node_id}')
        rows = listed.get('processes') or []
        last = next((p for p in rows if p.get('id') == process_id), None)
        if last and last.get('runtime_status') == 'running':
            time.sleep(12)
            return last
        time.sleep(3)
    raise AssertionError(('mysql process not running', last, listed))


def sqlite_path() -> Path:
    instances = read('instance:list')['app_instances']
    dev = next(i for i in instances if i['name'] == 'e2e-dev')
    return Path(dev['checkout_path']) / 'database' / 'database.sqlite'


def start_hold_proxy(marker: Path, state: Path, delay: float):
    tls = root / 'proxy-tls'
    tls.mkdir(exist_ok=True)
    if not (tls / 'cert.pem').exists():
        subprocess.run(
            ['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-keyout', str(tls / 'key.pem'), '-out', str(tls / 'cert.pem'),
             '-days', '1', '-nodes', '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1'],
            check=True, capture_output=True,
        )
    config = json.loads((private / 'config.json').read_text())
    active = config['active_gateway']
    upstream = config['gateways'][active]['url']
    upstream_ca = config['gateways'][active]['ca_path']
    proxy = subprocess.Popen(
        [python, str(FIXTURE_DIR / 'orb357-pending-http-proxy.py'),
         '--upstream', upstream, '--upstream-ca', upstream_ca,
         '--cert', str(tls / 'cert.pem'), '--key', str(tls / 'key.pem'),
         '--marker', str(marker), '--delay', str(delay), '--state', str(state),
         '--hold-path', '/api/v1/database-connections'],
        cwd=source,
    )
    for _ in range(50):
        if state.exists():
            break
        time.sleep(0.1)
    listen = json.loads(state.read_text())
    return proxy, config, active, upstream, upstream_ca, listen


if args.stage == 'prepare':
    node = app_dev()
    path = sqlite_path()
    empty = path.parent / 'ux357-empty.sqlite'
    created = read(
        'database:create', 'ux357-sqlite',
        '--driver=sqlite',
        f'--path={path}',
        f'--node={node["id"]}',
    )
    assert created['slug'] == 'ux357-sqlite' and created['driver'] == 'sqlite'
    assert SENTINEL not in json.dumps(created)
    save(root / 'created-sqlite.json', created)
    listed = read('database:list')
    assert any(c['slug'] == 'ux357-sqlite' for c in listed['connections'])
    shown = read('database:show', 'ux357-sqlite')
    assert shown['path'] == str(path)
    record_pty(
        'create-human',
        ['database:create', 'ux357-sqlite-b', '--driver=sqlite', f'--path={path}', f'--node={node["id"]}'],
        contains=['ux357-sqlite-b', 'sqlite'],
        absent=[SENTINEL],
    )
    record_pty(
        'create-duplicate',
        ['database:create', 'ux357-sqlite', '--driver=sqlite', f'--path={path}', f'--node={node["id"]}', '--json'],
        expected=1,
        error_code='database.slug_conflict',
    )
    empty_created = read(
        'database:create', 'ux357-empty',
        '--driver=sqlite',
        f'--path={empty}',
        f'--node={node["id"]}',
    )
    assert empty_created['slug'] == 'ux357-empty'
    save(root / 'created-empty.json', empty_created)
    orphan = read(
        'database:create', 'ux357-orphan-sqlite',
        '--driver=sqlite',
        f'--path={path}',
    )
    assert orphan['slug'] == 'ux357-orphan-sqlite'
    assert orphan.get('node_id') in (None, 0)
    save(root / 'created-orphan.json', orphan)
    pipe('create-pipe', ['database:create', 'ux357-sqlite-c', '--driver=sqlite', f'--path={path}', f'--node={node["id"]}', '--no-ansi'], contains=['ux357-sqlite-c'])

elif args.stage == 'registry':
    record_pty('list-human', ['database:list'], contains=['ux357-sqlite', 'sqlite', 'Request ID:'], absent=[SENTINEL])
    record_pty('list-plain', ['database:list'], contains=['ux357-sqlite', 'Request ID:'], absent=[SENTINEL], plain=True)
    pipe('list-pipe', ['database:list', '--no-ansi'], contains=['ux357-sqlite'])
    record_pty('list-json', ['database:list', '--json'], absent=[SENTINEL])
    record_pty('list-narrow', ['database:list'], compact_contains=['ux357-sqlite'], columns=40, rows=40)
    record_pty('show-human', ['database:show', 'ux357-sqlite'], contains=['ux357-sqlite', 'sqlite', 'Request ID'], absent=[SENTINEL])
    pipe('show-pipe', ['database:show', 'ux357-sqlite', '--no-ansi'], contains=['ux357-sqlite'])
    record_pty('update-human', ['database:update', 'ux357-sqlite', '--username=orbit'], contains=['orbit'])
    updated = read('database:update', 'ux357-sqlite', '--username=orbit')
    assert updated['username'] == 'orbit'
    record_pty('show-after-update', ['database:show', 'ux357-sqlite'], contains=['orbit'])
    record_pty(
        'update-required',
        ['database:update', 'ux357-sqlite', '--json'],
        expected=1,
        error_code='database.update_required',
        actual_gateway=False,
    )
    pipe('update-pipe', ['database:update', 'ux357-sqlite', '--username=orbit', '--no-ansi'], contains=['orbit'])

elif args.stage == 'query-sqlite':
    before_payload = read(
        'database:query', 'ux357-sqlite',
        "SELECT COUNT(*) AS c FROM ux357_items WHERE name = 'post-state'",
    )
    before = int(next(iter(before_payload.get('rows') or [{'c': 0}])).get('c') or 0)
    record_pty(
        'query-empty-read',
        ['database:query', 'ux357-sqlite', "SELECT name FROM ux357_items WHERE name = 'missing-row'"],
        contains=['Write permission', 'No matching records found.'],
        absent=['Wrote', 'Rows affected'],
    )
    record_pty(
        'query-insert',
        ['database:query', 'ux357-sqlite', "INSERT INTO ux357_items (name) VALUES ('post-state')", '--write'],
        contains=['Write permission', 'Statement completed.'],
        absent=['No matching records found.', 'Wrote', 'Rows affected'],
    )
    record_pty(
        'query-select-write',
        ['database:query', 'ux357-sqlite', "SELECT name FROM ux357_items WHERE name = 'post-state'", '--write'],
        contains=['Write permission', 'yes', 'post-state'],
        absent=['Statement completed.'],
    )
    after_payload = read(
        'database:query', 'ux357-sqlite',
        "SELECT COUNT(*) AS c FROM ux357_items WHERE name = 'post-state'",
    )
    after = int(next(iter(after_payload.get('rows') or [{'c': 0}])).get('c') or 0)
    assert after == before + 1, (before, after, after_payload)
    save(root / 'sqlite-post-state.json', {'before': before, 'after': after, 'count_payload': after_payload})
    record_pty(
        'query-typed',
        ['database:query', 'ux357-sqlite', "SELECT NULL AS n, 1 AS i, 1.5 AS f, printf('%.*c', 180, 'x') AS long_cell"],
        contains=['Write permission', '—', '1.5'],
        compact_contains=['x' * 180],
        absent=['Wrote', 'Rows affected'],
    )
    record_pty(
        'query-insert-json',
        ['database:query', 'ux357-sqlite', "INSERT INTO ux357_items (name) VALUES ('json-row')", '--write', '--json'],
        absent=[SENTINEL],
    )
    record_pty(
        'query-write-required',
        ['database:query', 'ux357-sqlite', "INSERT INTO ux357_items (name) VALUES ('blocked')", '--json'],
        expected=1,
        error_code='database.write_required',
    )
    still = int(next(iter(read('database:query', 'ux357-sqlite', "SELECT COUNT(*) AS c FROM ux357_items WHERE name = 'blocked'").get('rows') or [{'c': 0}])).get('c') or 0)
    assert still == 0
    record_pty(
        'query-stacked',
        ['database:query', 'ux357-sqlite', 'SELECT 1; SELECT 2', '--json'],
        expected=1,
        error_code='database.sql_multiple_statements',
    )
    record_pty(
        'query-sqlite-node-required',
        ['database:query', 'ux357-orphan-sqlite', 'SELECT 1', '--json'],
        expected=1,
        error_code='database.sqlite_node_required',
    )
    values = ', '.join(f"('row-{i}')" for i in range(501))
    read('database:query', 'ux357-sqlite', f'INSERT INTO ux357_items (name) VALUES {values}', '--write')
    truncated = read('database:query', 'ux357-sqlite', 'SELECT name FROM ux357_items')
    assert truncated['truncated'] is True and len(truncated['rows']) == 500, truncated
    record_pty(
        'query-truncated',
        ['database:query', 'ux357-sqlite', 'SELECT name FROM ux357_items'],
        contains=['Result truncated at the Gateway row limit.', 'The omitted total is not known.'],
        absent=['Wrote', 'Rows affected'],
    )
    pipe('query-pipe', ['database:query', 'ux357-sqlite', "SELECT name FROM ux357_items WHERE name = 'post-state'", '--no-ansi'], contains=['post-state'])
    save(root / 'truncated.json', {'row_count': truncated['row_count'], 'returned': len(truncated['rows']), 'truncated': truncated['truncated']})

elif args.stage == 'inspect':
    record_pty('tables-human', ['database:tables', 'ux357-sqlite'], contains=['ux357_items', 'ux357_typed'])
    record_pty('schema-human', ['database:schema', 'ux357-sqlite'], contains=['ux357_items', 'ux357_typed'])
    record_pty('describe-human', ['database:describe', 'ux357-sqlite', 'ux357_items'], contains=['name'])
    record_pty('describe-typed', ['database:describe', 'ux357-sqlite', 'ux357_typed'], contains=['flag', 'amount'])
    record_pty('tables-empty', ['database:tables', 'ux357-empty'], contains=['No matching records found.'])
    record_pty('schema-empty', ['database:schema', 'ux357-empty'], contains=['No matching records found.'])
    pipe('tables-json', ['database:tables', 'ux357-sqlite', '--json'])
    pipe('schema-pipe', ['database:schema', 'ux357-sqlite', '--no-ansi'], contains=['ux357_typed'])
    pipe('describe-pipe', ['database:describe', 'ux357-sqlite', 'ux357_typed', '--no-ansi'], contains=['amount'])
    record_pty(
        'describe-missing',
        ['database:describe', 'ux357-sqlite', 'missing_table', '--json'],
        expected=1,
        error_code='database.table_missing',
    )
    record_pty(
        'show-unknown',
        ['database:show', 'missing-slug', '--json'],
        expected=1,
        error_code='http.404',
    )

elif args.stage == 'mysql':
    node = app_dev()
    created = read(
        'process:create', 'ux357-mysql',
        f'--node={node["id"]}',
        '--runtime=docker',
        '--image=mysql:8.4',
        '--command=mysqld',
        f'--environment=MYSQL_ROOT_PASSWORD={ROOT_SECRET}',
        f'--port={node["wireguard_ip"]}:3307:3306/tcp',
        '--restart=on-failure',
        '--start',
    )
    assert created['name'] == 'ux357-mysql' and ROOT_SECRET not in json.dumps(created)
    save(root / 'mysql-process.json', created)
    wait_mysql(created['id'], node['id'])
    user = read(
        'database:user:create', 'ux357-mysql',
        f'--process={created["id"]}',
        '--database=ux357',
        '--username=ux357',
        f'--password={USER_SECRET}',
    )
    assert user['slug'] == 'ux357-mysql' and user['driver'] == 'mysql' and user['has_password'] is True
    assert USER_SECRET not in json.dumps(user) and ROOT_SECRET not in json.dumps(user)
    save(root / 'mysql-user.json', user)
    record_pty(
        'mysql-user-create',
        ['database:user:create', 'ux357-mysql-b', f'--process={created["id"]}', '--database=ux357b', '--username=ux357b', f'--password={USER_SECRET}'],
        contains=['ux357-mysql-b', 'mysql', 'stored'],
        absent=[USER_SECRET, ROOT_SECRET],
    )
    record_pty('mysql-show', ['database:show', 'ux357-mysql'], contains=['ux357-mysql', 'mysql', 'stored'], absent=[USER_SECRET, ROOT_SECRET])
    refreshed = read(
        'database:user:create', 'ux357-mysql',
        f'--process={created["id"]}',
        '--database=ux357',
        '--username=ux357',
        f'--password={USER_SECRET}',
    )
    assert refreshed['id'] == user['id']
    queried = read('database:query', 'ux357-mysql', 'SELECT 1 AS ok')
    assert queried['rows'] == [{'ok': 1}] and queried['truncated'] is False
    record_pty('mysql-query', ['database:query', 'ux357-mysql', 'SELECT 1 AS ok'], contains=['Write permission', '1'])
    grants = read('database:query', 'ux357-mysql', 'SHOW GRANTS')
    save(root / 'mysql-grants.json', grants)
    assert any('ux357' in json.dumps(row).lower() or 'GRANT' in json.dumps(row) for row in grants.get('rows') or [])
    record_pty('mysql-grants', ['database:query', 'ux357-mysql', 'SHOW GRANTS'], contains=['GRANT'], absent=[USER_SECRET, ROOT_SECRET])
    record_pty(
        'mysql-user-missing-process',
        ['database:user:create', 'ux357-missing', '--process=999999', '--database=ux357', '--username=ux357', f'--password={USER_SECRET}', '--json'],
        expected=1,
        error_code='http.404',
    )
    record_pty(
        'mysql-user-missing-password',
        ['database:user:create', 'ux357-missing-password', f'--process={created["id"]}', '--database=ux357', '--username=ux357', '--json'],
        expected=1,
        error_code='database.password_required',
        actual_gateway=False,
    )

elif args.stage == 'attach':
    dev = e2e_dev()
    record_pty(
        'attach-add',
        ['instance:database:add', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357'],
        contains=['UX357', 'Workload file'],
    )
    record_pty(
        'destroy-attached',
        ['database:destroy', 'ux357-sqlite', '--force', '--json'],
        expected=1,
        error_code='database.connection_attached',
    )
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    record_pty(
        'attach-remove-json-no-force',
        ['instance:database:remove', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357', '--json'],
        expected=1,
        error_code='database.confirmation_required',
        actual_gateway=False,
    )
    record_pty(
        'attach-remove-default-no',
        ['instance:database:remove', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357'],
        expected=1,
        contains=['ux357-sqlite', 'UX357', 'Workload .env stays unchanged'],
        input_actions=[{'wait_for': 'No', 'send': '\r'}],
    )
    record_pty(
        'attach-remove-cancel',
        ['instance:database:remove', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357'],
        expected=1,
        contains=['cancelled'],
        input_actions=[{'wait_for': 'No', 'send': '\x03'}],
    )
    record_pty(
        'attach-remove-eof',
        ['instance:database:remove', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357'],
        expected=1,
        contains=['cancelled'],
        input_actions=[{'wait_for': 'No', 'send': '\x04'}],
    )
    pipe('attach-remove-pipe', ['instance:database:remove', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357', '--no-ansi'], expected=1, contains=['--force'])
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    record_pty(
        'attach-remove-force',
        ['instance:database:remove', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357', '--force'],
        contains=['removed'],
    )
    mysql_attach = read('instance:database:add', 'ux357-mysql', f'--instance={dev["id"]}', '--prefix=UX357')
    assert mysql_attach['prefix'] == 'UX357' and mysql_attach.get('host')
    save(root / 'attach-mysql.json', mysql_attach)
    record_pty(
        'attach-mysql-prefix',
        ['instance:database:add', 'ux357-mysql-b', f'--instance={dev["id"]}'],
        contains=['DB_', 'Workload file'],
    )
    record_pty(
        'attach-sqlite-reuse',
        ['instance:database:add', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357'],
        contains=['UX357'],
    )
    sqlite_reuse = read('instance:database:add', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357')
    assert sqlite_reuse['prefix'] == 'UX357'
    assert sqlite_reuse.get('host') in (None, '', '—')
    assert sqlite_reuse.get('port') in (None, 0)
    save(root / 'attach-sqlite-reuse.json', sqlite_reuse)
    record_pty(
        'attach-sqlite-reuse-remove',
        ['instance:database:remove', 'ux357-sqlite', f'--instance={dev["id"]}', '--prefix=UX357', '--force'],
        contains=['removed'],
    )
    save(root / 'attach.json', {'instance': dev['id'], 'prefix': 'UX357'})

elif args.stage == 'consent':
    record_pty(
        'destroy-json-no-force',
        ['database:destroy', 'ux357-sqlite', '--json'],
        expected=1,
        error_code='database.confirmation_required',
        actual_gateway=False,
    )
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    record_pty(
        'destroy-default-no',
        ['database:destroy', 'ux357-sqlite'],
        expected=1,
        contains=['ux357-sqlite', 'physical database is not dropped'],
        input_actions=[{'wait_for': 'No', 'send': '\r'}],
    )
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    record_pty(
        'destroy-cancel',
        ['database:destroy', 'ux357-sqlite'],
        expected=1,
        contains=['cancelled'],
        input_actions=[{'wait_for': 'No', 'send': '\x03'}],
    )
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    record_pty(
        'destroy-eof',
        ['database:destroy', 'ux357-sqlite'],
        expected=1,
        contains=['cancelled'],
        input_actions=[{'wait_for': 'No', 'send': '\x04'}],
    )
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    pipe('destroy-pipe-no-force', ['database:destroy', 'ux357-sqlite', '--no-ansi'], expected=1, contains=['--force'])
    still = read('database:show', 'ux357-sqlite')
    assert still['slug'] == 'ux357-sqlite'
    record_pty(
        'destroy-force',
        ['database:destroy', 'ux357-sqlite', '--force'],
        contains=['destroyed'],
    )
    missing = read('database:show', 'ux357-sqlite', expected=1, error='http.404')
    save(root / 'destroyed.json', missing)

elif args.stage == 'env':
    instances = read('instance:list')['app_instances']
    dev = next(i for i in instances if i['name'] == 'e2e-dev')
    record_pty(
        'env-update',
        ['env:update', f'--instance={dev["id"]}', '--key=UX357_FLAG', f'--value={SENTINEL}'],
        contains=['AppInstance ID', 'Operation', 'Changed', 'Stored keys'],
        absent=[SENTINEL],
    )
    record_pty(
        'env-sync',
        ['env:sync', f'--instance={dev["id"]}'],
        contains=['Operation', 'sync'],
        absent=['cache', 'restart', SENTINEL],
    )
    changed = read('env:update', f'--instance={dev["id"]}', '--key=UX357_FLAG', '--value=true')
    assert changed.get('changed') is True
    record_pty(
        'env-import-conflict',
        ['env:import', f'--instance={dev["id"]}', '--json'],
        expected=1,
        error_code='env.import_conflict',
    )
    record_pty(
        'env-import-replace',
        ['env:import', f'--instance={dev["id"]}', '--replace'],
        contains=['Operation', 'import'],
        absent=[SENTINEL],
    )
    record_pty(
        'env-update-empty',
        ['env:update', f'--instance={dev["id"]}', '--key=UX357_EMPTY', '--value='],
        contains=['Changed'],
        absent=[SENTINEL],
    )
    record_pty(
        'env-update-false',
        ['env:update', f'--instance={dev["id"]}', '--key=UX357_FALSE', '--value=false'],
        contains=['Changed'],
        absent=[SENTINEL],
    )
    record_pty(
        'env-update-zero',
        ['env:update', f'--instance={dev["id"]}', '--key=UX357_ZERO', '--value=0'],
        contains=['Changed'],
        absent=[SENTINEL],
    )
    record_pty(
        'env-update-unchanged',
        ['env:update', f'--instance={dev["id"]}', '--key=UX357_ZERO', '--value=0'],
        contains=['Changed', 'false'],
        absent=[SENTINEL],
    )
    pipe('env-update-pipe', ['env:update', f'--instance={dev["id"]}', '--key=UX357_PIPE', '--value=true', '--no-ansi'], contains=['Operation'])
    record_pty(
        'env-update-json',
        ['env:update', f'--instance={dev["id"]}', '--key=UX357_FLAG', '--value=true', '--json'],
        absent=[SENTINEL],
    )
    payload = json.loads((root / 'env-update-json' / 'payload.json').read_text())
    assert payload['operation'] == 'update'
    assert payload.get('workload_file_changed') is False
    assert 'value' not in payload
    flushed = read('env:sync', f'--instance={dev["id"]}')
    save(root / 'env-sync-flush.json', flushed)
    record_pty(
        'env-sync-unchanged',
        ['env:sync', f'--instance={dev["id"]}'],
        contains=['Operation', 'sync', 'false'],
        absent=['cache', 'restart', SENTINEL],
    )
    pipe('env-import-pipe', ['env:import', f'--instance={dev["id"]}', '--replace', '--no-ansi'], contains=['Operation'])
    record_pty(
        'env-instance-required',
        ['env:update', '--key=UX357_FLAG', '--value=true', '--json'],
        expected=1,
        error_code='env.instance_required',
        actual_gateway=False,
    )

elif args.stage == 'liveness':
    delay = 8.0
    marker = root / 'arrived-slow'
    proxy_state = root / 'proxy-slow.json'
    proxy, config, active, upstream, upstream_ca, listen = start_hold_proxy(marker, proxy_state, delay)
    try:
        config['gateways'][active] = {'url': 'https://127.0.0.1:' + str(listen['port']), 'ca_path': str(root / 'proxy-tls' / 'cert.pem')}
        save(private / 'config.json', config)
        record_pty(
            'list-slow-live',
            ['database:list'],
            contains=['ux357-sqlite', 'Request ID:'],
            animation=LIST_ANIMATION,
        )
        summary = json.loads((root / 'list-slow-live' / 'capture/summary.json').read_text())
        assert summary['first_output_seconds'] < 3, summary
        assert summary['duration_seconds'] >= delay - 1, summary
        save(root / 'list-slow-live-timing.json', {
            'first_output_seconds': summary['first_output_seconds'],
            'duration_seconds': summary['duration_seconds'],
            'delay': delay,
            'arrival': json.loads(marker.read_text()) if marker.exists() else None,
        })
    finally:
        config['gateways'][active] = {'url': upstream, 'ca_path': upstream_ca}
        save(private / 'config.json', config)
        proxy.terminate()
        try:
            proxy.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proxy.kill()

    evidence = []
    for sig_name, expected in [('SIGINT', SIGINT_EXIT), ('SIGTERM', SIGTERM_EXIT)]:
        marker = root / ('arrived-' + sig_name)
        if marker.exists():
            marker.unlink()
        signaled = Path(str(marker) + '.signaled')
        if signaled.exists():
            signaled.unlink()
        proxy_state = root / ('proxy-' + sig_name + '.json')
        proxy, config, active, upstream, upstream_ca, listen = start_hold_proxy(marker, proxy_state, delay)
        try:
            config['gateways'][active] = {'url': 'https://127.0.0.1:' + str(listen['port']), 'ca_path': str(root / 'proxy-tls' / 'cert.pem')}
            save(private / 'config.json', config)
            env.update({'ORB357_SIGNAL': sig_name, 'ORB357_PENDING_MARKER': str(marker), 'ORB357_SIGNAL_AFTER_ARRIVAL': '0.4'})
            record_pty(
                'list-pending-' + sig_name.lower(),
                ['database:list'],
                contains=['Loading Database connections', 'Operation interrupted.'],
                expected=expected,
            )
        finally:
            config['gateways'][active] = {'url': upstream, 'ca_path': upstream_ca}
            save(private / 'config.json', config)
            env.pop('ORB357_SIGNAL', None)
            env.pop('ORB357_PENDING_MARKER', None)
            env.pop('ORB357_SIGNAL_AFTER_ARRIVAL', None)
            proxy.terminate()
            try:
                proxy.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proxy.kill()
        summary = json.loads((root / ('list-pending-' + sig_name.lower()) / 'capture/summary.json').read_text())
        arrival = json.loads(marker.read_text()) if marker.exists() else {}
        signal_meta = json.loads(signaled.read_text()) if signaled.exists() else {}
        assert marker.exists() and signaled.exists(), (sig_name, arrival, signal_meta)
        assert summary['duration_seconds'] < delay, (sig_name, summary, delay)
        assert summary['child_exit_code'] == expected, (sig_name, summary)
        remaining = read('database:list')
        assert any(c['slug'] == 'ux357-sqlite' for c in remaining['connections'])
        evidence.append({
            'signal': sig_name,
            'expected_exit': expected,
            'arrival': arrival,
            'signaled': signal_meta,
            'duration': summary['duration_seconds'],
            'first_output': summary['first_output_seconds'],
            'did_not_wait_for_delayed_response': summary['duration_seconds'] < delay,
            'arrival_proves': 'request waiting at fixture proxy, not upstream Gateway processing',
            'post_state_sqlite_present': True,
        })
    save(root / 'pending-http-evidence.json', evidence)

elif args.stage == 'native-visual':
    record_pty('native-list', ['database:list'], contains=['ux357-sqlite', 'Request ID:'], rows=65, columns=150)
    record_pty('native-show-mysql', ['database:show', 'ux357-mysql'], contains=['ux357-mysql', 'stored'])

elif args.stage == 'coverage-report':
    expected = {
        'database:create': ['create-human', 'create-duplicate', 'create-pipe'],
        'database:list': ['list-human', 'list-plain', 'list-pipe', 'list-json', 'list-narrow', 'list-slow-live', 'list-pending-sigint', 'list-pending-sigterm', 'native-list'],
        'database:show': ['show-human', 'show-pipe', 'show-unknown', 'mysql-show', 'native-show-mysql'],
        'database:update': ['update-human', 'update-required', 'update-pipe'],
        'database:query': ['query-empty-read', 'query-insert', 'query-select-write', 'query-typed', 'query-truncated', 'query-write-required', 'query-sqlite-node-required', 'query-pipe', 'mysql-query', 'mysql-grants'],
        'database:tables': ['tables-human', 'tables-empty', 'tables-json'],
        'database:schema': ['schema-human', 'schema-empty', 'schema-pipe'],
        'database:describe': ['describe-human', 'describe-typed', 'describe-missing', 'describe-pipe'],
        'database:user:create': ['mysql-user-create', 'mysql-user-missing-process', 'mysql-user-missing-password'],
        'database:destroy': ['destroy-attached', 'destroy-json-no-force', 'destroy-default-no', 'destroy-cancel', 'destroy-eof', 'destroy-pipe-no-force', 'destroy-force'],
        'instance:database:add': ['attach-add', 'attach-mysql-prefix', 'attach-sqlite-reuse'],
        'instance:database:remove': ['attach-remove-json-no-force', 'attach-remove-default-no', 'attach-remove-cancel', 'attach-remove-eof', 'attach-remove-pipe', 'attach-remove-force'],
        'env:update': ['env-update', 'env-update-empty', 'env-update-false', 'env-update-zero', 'env-update-unchanged', 'env-update-json', 'env-update-pipe'],
        'env:import': ['env-import-conflict', 'env-import-replace', 'env-import-pipe'],
        'env:sync': ['env-sync', 'env-sync-unchanged'],
    }
    found = {}
    missing = []
    for command, labels in expected.items():
        found[command] = []
        for name in labels:
            matches = list(args.state.rglob(name + '/verify.json')) + list(args.state.rglob(name + '.stdout'))
            if not matches:
                missing.append(name)
                continue
            verify_files = [p for p in matches if p.name == 'verify.json']
            if verify_files:
                verify = json.loads(verify_files[0].read_text())
                assert verify.get('passed') is True, (name, verify)
                assert verify.get('failures') == [], (name, verify)
            found[command].append(name)
    assert missing == [], missing
    save(root / 'coverage-aggregate.json', {
        'commands': sorted(expected),
        'found': found,
        'missing': missing,
        'complete': missing == [] and set(expected) == set(found) and all(found[c] == expected[c] for c in expected),
    })

save(root / 'records.json', records)
print(json.dumps({'stage': args.stage, 'passed': True, 'records': len(records)}), flush=True)
