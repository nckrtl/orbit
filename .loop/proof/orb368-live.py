#!/usr/bin/env python3
"""Record real App instance operations and verify their resulting guest state."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import sys
import termios
import signal
import uuid
import re
import importlib.util
import time
import sqlite3

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    stderr_file = open(os.environ['ORB368_CAPTURE_STDERR'], 'wb') if os.environ.get('ORB368_CAPTURE_STDERR') else None
    child = subprocess.Popen(sys.argv[3:], stderr=stderr_file)
    signal.signal(signal.SIGINT, signal.SIG_IGN)
    code = child.wait()
    if stderr_file is not None:
        stderr_file.close()
    after = termios.tcgetattr(0)
    Path(sys.argv[2]).write_text(json.dumps({'before': repr(before), 'after': repr(after), 'equal': before == after, 'exit': code}))
    sys.exit(code)

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--stage', choices=['prepare', 'roundtrip', 'resume-reverse', 'failures', 'retain-fixture'], required=True)
parser.add_argument('--visible', action='store_true')
parser.add_argument('--discovery-manifest', type=Path)
parser.add_argument('--attempt', default='')
args = parser.parse_args()
os.umask(0o077)
source = Path('/home/orbit/orbit')
if args.discovery_manifest:
    manifest = json.loads(args.discovery_manifest.read_text())
    assert manifest['candidate'] == args.candidate
    for entry in manifest['files']:
        data = os.readlink(source / entry['path']).encode() if entry['symlink'] else (source / entry['path']).read_bytes()
        assert hashlib.sha256(data).hexdigest() == entry['sha256'], entry['path']
else:
    assert subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip() == args.candidate
    assert not subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()
assert not args.attempt or (args.discovery_manifest and re.fullmatch(r'[a-z0-9-]+', args.attempt))
root = args.state / (args.stage + ('-'+args.attempt if args.attempt else ''))
root.mkdir(parents=True)
private = root / 'gateway-home'
private.mkdir()
shutil.copyfile(Path.home() / '.orbit/config.json', private / 'config.json')
known_hosts = root / 'known_hosts'
shutil.copyfile(Path.home() / '.orbit/ssh/known_hosts', known_hosts)
env = dict(os.environ, ORBIT_HOME=str(private), TERM='xterm-256color', LC_ALL='C.UTF-8', PAO_DISABLE='1')
for key in ['NO_COLOR', 'FORCE_COLOR', 'CLICOLOR', 'COLUMNS', 'LINES']:
    env.pop(key, None)
launcher = source / 'apps/cli/orbit'
recorder = source / '.agents/skills/verifying-cli-output/scripts'
observations, records = [], []

def save(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')

def read(*argv, expected=0, error=None):
    command = ['php', str(launcher), *map(str, argv), '--json']
    result = subprocess.run(command, cwd=source, env=env, capture_output=True, input=b'', timeout=240)
    assert result.returncode == expected, (argv, result.returncode, result.stdout, result.stderr)
    assert result.stderr == b'' and b'\x1b' not in result.stdout, (argv, result.stderr)
    payload = json.loads(result.stdout)
    if error:
        assert payload['error']['code'] == error, (argv, payload)
    observations.append({'argv': command, 'exit': result.returncode, 'payload': payload})
    save(root / 'observations.json', observations)
    return payload

def record(label, argv, contains, *, expected=0, key=None, prompt=None, columns=100, plain=False, table=None, table_headers=None, fallback=None, detail=None, json_payload=None, absent=None, animation=None, input_actions=None, max_after_input=None, wrapped_contains=None):
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--no-ansi' if plain else '--ansi']
    capture = [sys.executable, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
               '--candidate', args.candidate, '--label', label, '--columns', str(columns), '--rows', '100',
               '--timeout', '240', '--idle-timeout', '240' if '--json' in argv else '60']
    if not args.visible:
        capture.append('--no-live')
    if input_actions is not None:
        save(out / 'inputs.json', input_actions)
        capture += ['--input-plan', str(out / 'inputs.json')]
    if key is not None:
        plan = [{'wait_for': prompt, 'send': 'y' if key == 'n' else key}]
        if key == 'n':
            plan += [{'wait_for': 'Yes', 'send': 'n'}, {'wait_for': 'No', 'send': '\r'}]
        elif key == 'y':
            plan.append({'wait_for': 'Yes', 'send': '\r'})
        save(out / 'inputs.json', plan)
        capture += ['--input-plan', str(out / 'inputs.json')]
    capture += ['--', sys.executable, str(Path(__file__).resolve()), '--child', str(out / 'terminal.json'), *command]
    print('$ ' + shlex.join(command), flush=True)
    capture_env = dict(env)
    if '--json' in argv:
        capture_env['ORB368_CAPTURE_STDERR'] = str(out / 'stderr.bin')
    result = subprocess.run(capture, cwd=source, env=capture_env, capture_output=not args.visible, timeout=255)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal'], label
    if max_after_input is not None:
        events = [json.loads(line) for line in (out / 'capture/input-events.jsonl').read_text().splitlines()]
        sent = next(event for event in reversed(events) if event['bytes'] > 0)
        summary = json.loads((out / 'capture/summary.json').read_text())
        elapsed = summary['duration_seconds'] - sent['elapsed']
        save(out / 'signal-response.json', {'seconds': elapsed, 'maximum': max_after_input})
        assert elapsed <= max_after_input, (label, elapsed, max_after_input)

    if '--json' in argv:
        assert (out / 'stderr.bin').read_bytes() == b'', label
        raw = (out / 'capture/raw.bin').read_bytes()
        assert b'\x1b' not in raw, label
        machine = json.loads(raw)
        if json_payload is not None:
            assert without_request(machine) == without_request(json_payload), (label, machine, json_payload)
    captured_frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
    assert captured_frames[-1]['cursor']['hidden'] is False, label
    if wrapped_contains:
        compact = ''.join(''.join(captured_frames[-1]['lines']).replace('│', '').split())
        for expected_text in wrapped_contains:
            assert ''.join(expected_text.split()) in compact, (label, expected_text, compact)
        save(out / 'expected-wrapped.json', wrapped_contains)

    expectation = {'candidate': args.candidate, 'label': label, 'exit_code': expected, 'contains': contains if table is None else ['Request ID:'], 'final_contains': contains if table is None else ['Request ID:'], 'max_first_output_seconds': 240}
    if '--json' in argv:
        expectation['contains'] = ['request_id']
        expectation['final_contains'] = []
    if absent:
        expectation['absent'] = absent
        assert all(value not in (out / 'capture/transcript.txt').read_text() for value in absent), label
    if animation and not plain:
        expectation['animation_rows'] = [animation]
    if table is not None:
        frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
        assert_wrapped_table(frames[-1]['lines'], table, table_headers or ['ID', 'ROLE', 'STATUS', 'FAILED STEP', 'ERROR CODE'])
        save(out / 'expected-table.json', table)
    if fallback is not None:
        compact = ''.join(''.join(captured_frames[-1]['lines']).split())
        expected_records = ''.join('Record' + str(i + 1) + ''.join(''.join(str(k).split()) + ':' + ''.join(str(v).split()) for k, v in row) for i, row in enumerate(fallback))
        assert expected_records in compact, (label, expected_records, compact)
        save(out / 'expected-records.json', fallback)
    save(out / 'expectation.json', expectation)
    verified = subprocess.run([sys.executable, str(recorder / 'verify.py'), '--capture', str(out / 'capture'), '--expect', str(out / 'expectation.json')], capture_output=True, text=True)
    (out / 'verify.json').write_text(verified.stdout)
    assert verified.returncode == 0, (label, verified.stdout, verified.stderr)
    if plain:
        assert b'\x1b' not in (out / 'capture/raw.bin').read_bytes()
    record = {'label': label, 'argv': command, 'candidate': args.candidate, 'environment': 'disposable-incus', 'request_observation': 'see observations; local refusals may send no request', 'exit': expected, 'columns': columns, 'passed': True}
    save(out / 'case.json', record)
    records.append(record)
    save(root / 'records.json', records)
    print(json.dumps({'label': label, 'passed': True}), flush=True)
    return out

def remote(node, command, public=False):
    host = node['public_ssh_host'] if public else node['wireguard_ip']
    port = str(node['public_ssh_port']) if public else '22'
    argv = ['ssh', '-i', str(Path.home() / '.orbit/ssh/id_ed25519'), '-p', port,
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
            '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(known_hosts),
            '-o', 'ConnectTimeout=10', node['user'] + '@' + host, command]
    result = subprocess.run(argv, capture_output=True, text=True, timeout=60)
    assert result.returncode == 0, (node['name'], command, result.returncode, result.stdout, result.stderr)
    observations.append({'node': node['name'], 'remote': command, 'public': public, 'exit': result.returncode, 'stdout': result.stdout})
    save(root / 'observations.json', observations)
    return result.stdout

def assert_wrapped_table(lines, expected, expected_headers):
    # Role tables have an unwrapped numeric ID in their first column.
    # Join continuation cells within each ID row, never across columns or rows.
    headers, rows, current = [''] * len(expected_headers), [], None
    in_table = in_body = False
    for line in lines:
        if '┌' in line and '┬' in line:
            in_table = True
            continue
        if not in_table:
            continue
        if '┼' in line:
            in_body = True
            continue
        if '└' in line:
            break
        cells = line.split('│')[1:-1]
        if len(cells) != len(expected_headers):
            continue
        cells = [''.join(cell.split()) for cell in cells]
        if not in_body:
            headers = [a + b for a, b in zip(headers, cells)]
        elif cells[0]:
            if current is not None:
                rows.append(current)
            current = cells
        else:
            assert current is not None
            current = [a + b for a, b in zip(current, cells)]
    if current is not None:
        rows.append(current)
    assert headers == [''.join(h.split()) for h in expected_headers], headers
    assert rows == [[''.join(str(v).split()) for v in row] for row in expected], (rows, expected)


def without_request(value):
    if isinstance(value, dict):
        return {k: without_request(v) for k, v in value.items() if k != 'request_id'}
    if isinstance(value, list):
        return [without_request(v) for v in value]
    return value


def stored(instance_id):
    with sqlite3.connect('file:/home/orbit/.orbit/gateway.sqlite?mode=ro', uri=True) as db:
        db.row_factory = sqlite3.Row
        operation = db.execute('SELECT id, app_instance_id, source_node_id, source_router_node_id, destination_node_id, source_route_id, destination_route_id, status, current_step, failed_step, error_code, cutover_at, completed_at FROM app_instance_transfers WHERE app_instance_id=? ORDER BY rowid DESC LIMIT 1', (instance_id,)).fetchone()
        routes = db.execute('SELECT r.id, r.app_id, r.domain, r.status, r.replacement_step, r.replaces_route_id, r.replaced_by_route_id, t.app_instance_id FROM routes r JOIN route_targets t ON t.route_id=r.id WHERE t.app_instance_id=? ORDER BY r.id', (instance_id,)).fetchall()
        ports = db.execute('SELECT node_id, port FROM vite_port_assignments WHERE app_instance_id=? ORDER BY node_id', (instance_id,)).fetchall()
        targets = {str(r['id']): [dict(t) for t in db.execute('SELECT id, app_instance_id, position FROM route_targets WHERE route_id=? ORDER BY id', (r['id'],)).fetchall()] for r in routes}
    return {'operation': dict(operation) if operation else None, 'routes': [dict(r) for r in routes], 'ports': [dict(p) for p in ports], 'targets': targets}

def content(node, path):
    program = '''import hashlib,json,pathlib,subprocess
p=pathlib.Path(%r)
files=subprocess.check_output(['git','-C',str(p),'ls-files','-z']).decode().split('\\0')
files=[f for f in files if f]+['ux368-proof.txt']
print(json.dumps({'head':subprocess.check_output(['git','-C',str(p),'rev-parse','HEAD'],text=True).strip(),'files':{f:hashlib.sha256((p/f).read_bytes()).hexdigest() for f in files}},sort_keys=True))
''' % path
    return json.loads(remote(node, 'python3 -c '+shlex.quote(program)))

def completed(instance_id, node, previous_node, previous_path, expected_content, previous_domain):
    state = stored(instance_id)
    assert state['operation']['status'] == 'completed' and state['operation']['completed_at'], state
    assert state['operation']['destination_node_id'] == node['id'], state
    assert len(state['routes']) == 1, state
    assert all(len(ts) == 1 and ts[0]['app_instance_id'] == instance_id for ts in state['targets'].values()), state
    assert state['routes'][0]['replacement_step'] is None, state
    assert state['routes'][0]['replaces_route_id'] is None and state['routes'][0]['replaced_by_route_id'] is None, state
    assert [p['node_id'] for p in state['ports']] == [node['id']], state
    current = read('instance:show', instance_id)
    assert current['node_id'] == node['id'] and current['status'] == 'active', current
    assert content(node, current['checkout_path']) == expected_content
    remote(previous_node, 'test ! -e '+shlex.quote(previous_path))
    read('env:sync', '--instance='+str(instance_id))
    fixture = json.loads((args.state/'prepare/fixture.json').read_text())
    unit = fixture['worker_unit']
    remote(node, 'sudo systemctl is-active --quiet '+shlex.quote(unit))
    remote(previous_node, '! sudo systemctl is-active --quiet '+shlex.quote(unit))
    for n in [node, previous_node]:
        remote(n, 'sudo caddy validate --config /etc/caddy/Caddyfile && sudo systemctl is-active --quiet caddy')
        config = remote(n, 'sudo cat "$(dirname "$(sudo readlink -f /etc/caddy/Caddyfile)")/fragments/app-dev.caddy"')
        assert previous_domain not in config, (n['name'], previous_domain, config)
        if n['id'] == node['id']:
            assert current['domain'] in config, config
        for certificate in set(re.findall(r'/etc/caddy/orbit-certificates/[^\s";]+/(?:cert|key)\.pem', config)):
            remote(n, 'sudo test -s '+shlex.quote(certificate))
    router = next(n for n in nodes if n['id'] == state['operation']['source_router_node_id'])
    obsolete = '/etc/caddy/orbit-certificates/route-'+str(state['operation']['source_route_id'])+'-router'
    remote(router, 'sudo test ! -e '+shlex.quote(obsolete))
    remote(previous_node, 'sudo test ! -e /etc/caddy/orbit-certificates/app-instance-'+str(instance_id))
    remote(node, 'sudo test -s /etc/caddy/orbit-certificates/app-instance-'+str(instance_id)+'/current/cert.pem')
    dns = subprocess.check_output(['sudo', 'cat', '/etc/dnsmasq.d/orbit-records.conf'], text=True)
    assert previous_domain not in dns and 'host-record='+current['domain']+',' in dns, dns
    save(root/('projection-'+state['operation']['id']+'.json'), {'previous_domain': previous_domain, 'current_domain': current['domain'], 'dns': dns, 'obsolete_certificate': obsolete, 'checked': True})
    return current, state

def retained(state, before, instance_id):
    operation = state['operation']
    assert operation['app_instance_id'] == instance_id, state
    assert operation['source_node_id'] == dev['id'] and operation['destination_node_id'] == extra['id'], state
    assert operation['source_route_id'] == before['route']['id'], state
    assert operation['cutover_at'] and operation['completed_at'] is None, state
    routes = {r['id']: r for r in state['routes']}
    assert set(routes) == {operation['source_route_id'], operation['destination_route_id']}, state
    old, new = routes[operation['source_route_id']], routes[operation['destination_route_id']]
    assert old['replaced_by_route_id'] == new['id'] and new['replaces_route_id'] == old['id'], state
    assert old['replaces_route_id'] is None and new['replaced_by_route_id'] is None, state
    assert old['status'] == 'retiring' and new['status'] == 'active', state
    assert all(r['app_instance_id'] == instance_id and r['app_id'] == before['app_id'] and r['replacement_step'] == 'database-cutover' for r in routes.values()), state
    assert all(len(ts) == 1 and ts[0]['app_instance_id'] == instance_id for ts in state['targets'].values()), state
    assert {p['node_id'] for p in state['ports']} == {dev['id'], extra['id']}, state
    assert next(p['port'] for p in state['ports'] if p['node_id'] == dev['id']) == before['vite_port'], state

nodes = read('node:list')['nodes']
dev = next(n for n in nodes if n['name'] == 'app-dev')
extra = next(n for n in nodes if n['name'] == 'app-prod-2')
with sqlite3.connect('file:/home/orbit/.orbit/gateway.sqlite?mode=ro', uri=True) as db:
    routers = db.execute("SELECT node_id FROM node_roles WHERE cluster_id=? AND role='router' AND status='active'", (dev['cluster_id'],)).fetchall()
assert routers == [(dev['id'],)], routers

if args.stage == 'prepare':
    instances = read('instance:list')['app_instances']
    assert extra['roles'] == ['app-prod'] and not any(i['node_id'] == extra['id'] for i in instances)
    assert dev['cluster_id'] is not None
    save(root/'baseline.json', {'instances': instances, 'routes': read('route:list')['routes'], 'extra': extra, 'source': dev})
    read('node:role:remove', extra['id'], 'app-prod', '--force')
    if extra['cluster_id'] is None:
        read('cluster:node:add', dev['cluster_id'], extra['id'])
    read('node:role:add', extra['id'], 'app-dev')
    read('node:add', extra['name'], '--tld=ux368.test')
    after = read('node:show', extra['id'])
    assert after['status'] == 'active' and after['roles'] == ['app-dev'] and after['cluster_id'] == dev['cluster_id'], after
    fixture_app = read('app:create', 'ux368-fixture', 'https://github.com/mdn/beginner-html-site-styled.git', '--name=Transfer repair fixture', '--root=styles')
    created = read('instance:create', fixture_app['id'], dev['id'], 'ux368-transfer', '--branch=main')
    assert created['status'] == 'active' and created['node_id'] == dev['id'], created
    assert created['route']['provenance'] == 'generated' and dev['tld'] != 'ux368.test', created
    read('env:update', '--instance='+str(created['id']), '--key=UX368_PROOF', '--value=nonsecret-fixture')
    read('env:sync', '--instance='+str(created['id']))
    path = created['checkout_path']
    remote(dev, 'printf ux368-preserved > '+shlex.quote(path+'/ux368-proof.txt'))
    remote(dev, 'printf "\\n/* ux368 tracked edit */\\n" >> '+shlex.quote(path+'/styles/style.css'))
    read('process:create', 'ux368-worker', '--instance='+str(created['id']), '--command=/usr/bin/sleep', '--command=3600', '--start', '--keep-alive')
    with sqlite3.connect('file:/home/orbit/.orbit/gateway.sqlite?mode=ro', uri=True) as db:
        worker = db.execute('SELECT id FROM processes WHERE owner_id=? AND name=?', (created['id'], 'ux368-worker')).fetchone()
    assert worker is not None
    unit = 'orbit-process-'+str(worker[0])+'-ux368-worker.service'
    remote(dev, 'sudo systemctl is-active --quiet '+shlex.quote(unit))
    save(root/'fixture.json', {'app': fixture_app, 'instance': created, 'content': content(dev,path), 'source': dev, 'destination': after, 'worker_unit': unit, 'worker_id': worker[0]})
    current_instances = without_request(read('instance:list')['app_instances'])
    assert all(without_request(i) in current_instances for i in instances)

elif args.stage in ['roundtrip', 'resume-reverse']:
    fixture = json.loads((args.state/'prepare/fixture.json').read_text())
    original = fixture['instance']
    instance_id = original['id']
    assert original['route']['provenance'] == 'generated' and dev['tld'] != extra['tld']
    if args.stage == 'roundtrip':
        assert read('instance:show', instance_id)['node_id'] == dev['id']
        record('forward', ['instance:transfer', instance_id, extra['id'], '--force'], ['transferred.', 'Cleanup: completed', 'Request ID:'])
    else:
        assert args.discovery_manifest, 'Resume is discovery-only; fresh proof must run the complete roundtrip.'
        assert read('instance:show', instance_id)['node_id'] == extra['id']
    moved, state = completed(instance_id, extra, dev, original['checkout_path'], fixture['content'], original['domain'])
    assert moved['domain'] != original['domain'], moved
    assert state['operation']['source_router_node_id'] == dev['id'], state
    assert state['operation']['source_route_id'] != state['operation']['destination_route_id'], state
    save(root/'forward.json', {'instance': moved, 'state': state})
    record('reverse', ['instance:transfer', instance_id, dev['id'], '--force', '--json'], [])
    returned, state = completed(instance_id, dev, extra, moved['checkout_path'], fixture['content'], moved['domain'])
    assert returned['id'] == original['id'] and returned['domain'] == original['domain'], returned
    save(root/'reverse.json', {'instance': returned, 'state': state})

elif args.stage == 'failures':
    fixture = json.loads((args.state/'prepare/fixture.json').read_text())
    instance_id = fixture['instance']['id']
    before = read('instance:show', instance_id)
    assert before['node_id'] == dev['id'] and content(dev,before['checkout_path']) == fixture['content']
    expected_content = fixture['content']
    caddy_directory = '/etc/caddy/orbit-versions'
    attributes = remote(extra, 'sudo lsattr -d '+shlex.quote(caddy_directory)).split()[0]
    assert 'i' not in attributes, attributes
    remote(extra, 'sudo chattr +i '+shlex.quote(caddy_directory))
    try:
        record('projection-failure', ['instance:transfer', instance_id, extra['id'], '--force'], ['Request ID:'], expected=1)
        failed = stored(instance_id)
        save(root/'projection-failure.json', failed)
        assert failed['operation']['cutover_at'] and failed['operation']['completed_at'] is None, failed
        assert failed['operation']['current_step'] == 'cutover', failed
        assert failed['operation']['error_code'] == 'app-dev.caddy_config_failed', failed
        assert read('instance:show', instance_id)['node_id'] == extra['id']
        assert len(failed['routes']) == 2 and len(failed['ports']) == 2, failed
        retained(failed, before, instance_id)
        moved = read('instance:show', instance_id)
        assert content(extra, moved['checkout_path']) == expected_content
        remote(extra, 'printf "\\n/* destination-only projection retry */\\n" >> '+shlex.quote(moved['checkout_path']+'/styles/style.css'))
        expected_content = content(extra, moved['checkout_path'])
        assert expected_content != content(dev, before['checkout_path'])
        save(root/'projection-destination-edit.json', expected_content)
        remote(dev, '! sudo systemctl is-active --quiet '+shlex.quote(fixture['worker_unit']))
    finally:
        remote(extra, 'sudo chattr -i '+shlex.quote(caddy_directory))
    record('projection-retry', ['instance:transfer', instance_id, extra['id'], '--force'], ['Cleanup: completed'])
    moved, state = completed(instance_id, extra, dev, before['checkout_path'], expected_content, before['domain'])
    assert state['operation']['id'] == failed['operation']['id'], state
    save(root/'projection-retried.json', state)
    record('return-before-cleanup-fault', ['instance:transfer', instance_id, dev['id'], '--force'], ['Cleanup: completed'])
    before, state = completed(instance_id, dev, extra, moved['checkout_path'], expected_content, moved['domain'])
    marker = before['checkout_path']+'/ux368-proof.txt'
    attributes = remote(dev, 'sudo lsattr -d '+shlex.quote(marker)).split()[0]
    assert 'i' not in attributes, attributes
    remote(dev, 'sudo chattr +i '+shlex.quote(marker))
    try:
        record('cleanup-failure', ['instance:transfer', instance_id, extra['id'], '--force'], ['Request ID:'], expected=1)
        failed = stored(instance_id)
        save(root/'cleanup-failure.json', failed)
        assert failed['operation']['current_step'] == 'destination-activated', failed
        assert failed['operation']['error_code'] == 'instance.transfer_cleanup_incomplete', failed
        assert failed['operation']['completed_at'] is None and len(failed['routes']) == 2 and len(failed['ports']) == 2, failed
        assert any(r['status'] == 'retiring' for r in failed['routes']), failed
        assert all(r['replacement_step'] == 'database-cutover' for r in failed['routes']), failed
        retained(failed, before, instance_id)
        remote(dev, 'test -d '+shlex.quote(before['checkout_path'])+' && test "$(cat '+shlex.quote(marker)+')" = ux368-preserved')
        moved = read('instance:show', instance_id)
        assert moved['node_id'] == extra['id'] and content(extra,moved['checkout_path']) == expected_content
        remote(extra, 'printf "\\n/* destination-only cleanup retry */\\n" >> '+shlex.quote(moved['checkout_path']+'/styles/style.css'))
        expected_content = content(extra, moved['checkout_path'])
        save(root/'cleanup-destination-edit.json', expected_content)
        remote(extra, 'sudo systemctl is-active --quiet '+shlex.quote(fixture['worker_unit']))
        remote(dev, '! sudo systemctl is-active --quiet '+shlex.quote(fixture['worker_unit']))
    finally:
        remote(dev, 'sudo chattr -i '+shlex.quote(marker))
    record('cleanup-retry', ['instance:transfer', instance_id, extra['id'], '--force'], ['Cleanup: completed'])
    moved, state = completed(instance_id, extra, dev, before['checkout_path'], expected_content, before['domain'])
    assert state['operation']['id'] == failed['operation']['id'], state
    save(root/'cleanup-retried.json', state)
    record('final-return', ['instance:transfer', instance_id, dev['id'], '--force'], ['Cleanup: completed'])
    returned, state = completed(instance_id, dev, extra, moved['checkout_path'], expected_content, moved['domain'])
    save(root/'final-return.json', {'instance': returned, 'state': state})

elif args.stage == 'retain-fixture':
    fixture = json.loads((args.state/'prepare/fixture.json').read_text())
    baseline = json.loads((args.state/'prepare/baseline.json').read_text())
    instance_id = fixture['instance']['id']
    retained_instance = read('instance:show', instance_id)
    assert retained_instance['node_id'] == dev['id'] and retained_instance['status'] == 'active'
    assert retained_instance['transfer']['cleanup_completed'] is True
    read('node:role:remove', extra['id'], 'app-dev', '--force')
    if baseline['extra']['cluster_id'] is None:
        read('cluster:node:remove', dev['cluster_id'], extra['id'], '--force')
    read('node:role:add', extra['id'], 'app-prod')
    after = read('node:show', extra['id'])
    assert after['roles'] == ['app-prod'] and after['status'] == 'active', after
    assert after['cluster_id'] == baseline['extra']['cluster_id'], after
    current = read('instance:list')['app_instances']
    assert without_request([i for i in current if i['id'] != instance_id]) == without_request(baseline['instances']), current
    current_routes = read('route:list')['routes']
    assert without_request([r for r in current_routes if r['app_id'] != fixture['app']['id']]) == without_request(baseline['routes']), current_routes
    save(root/'retained.json', {'node': after, 'instances': current, 'routes': current_routes, 'disposal': 'Healthy transferred fixture remains on source Node for independent review. Topology release owns final disposal, including fixture TLD. Unrelated instances and Routes are unchanged; extra Node roles and membership restored.'})

save(root/'stage-result.json', {'candidate': args.candidate, 'stage': args.stage, 'attempt': args.attempt, 'passed': True, 'fixture_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(), 'recordings': len(records)})
print(json.dumps({'stage': args.stage, 'passed': True, 'recordings': len(records)}), flush=True)
