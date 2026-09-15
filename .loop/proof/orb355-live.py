#!/usr/bin/env python3
"""Record real Node/firewall operations and verify their resulting guest state."""
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

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    stderr_file = open(os.environ['ORB355_CAPTURE_STDERR'], 'wb') if os.environ.get('ORB355_CAPTURE_STDERR') else None
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
parser.add_argument('--stage', choices=['firewall', 'settings-access', 'lifecycle', 'modes', 'read-modes', 'mutation-modes', 'offline-reachable', 'offline-role', 'offline-node', 'recover-role', 'recover-node', 'interruption', 'narrow-consent', 'remote-failures'], required=True)
parser.add_argument('--visible', action='store_true')
parser.add_argument('--discovery-manifest', type=Path)
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
root = args.state / args.stage
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
               '--candidate', args.candidate, '--label', label, '--columns', str(columns), '--rows', '60',
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
        capture_env['ORB355_CAPTURE_STDERR'] = str(out / 'stderr.bin')
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
        compact = ''.join(''.join(captured_frames[-1]['lines']).split())
        for expected_text in wrapped_contains:
            assert ''.join(expected_text.split()) in compact, (label, expected_text, compact)
        save(out / 'expected-wrapped.json', wrapped_contains)

    expectation = {'candidate': args.candidate, 'label': label, 'exit_code': expected, 'contains': contains if table is None else ['Request ID:'], 'final_contains': contains if table is None else ['Request ID:'], 'max_first_output_seconds': 240 if '--json' in argv else 3}
    if absent:
        expectation['absent'] = absent
        assert all(value not in (out / 'capture/transcript.txt').read_text() for value in absent), label
    if label in ['reconverge-role', 'add-node']:
        operation = 'Node role' if label == 'reconverge-role' else 'Node'
        animation = {'name': operation, 'pattern': '(?P<glyph>[○◉]) Adding ' + operation, 'terminal_pattern': '● Added ' + operation, 'minimum_changes': 5, 'min_interval': 0.15, 'max_interval': 0.6}
    if animation and not plain:
        expectation['animation_rows'] = [animation]
    if table is not None:
        frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
        assert_wrapped_table(frames[-1]['lines'], table, table_headers or ['ID', 'ROLE', 'STATUS', 'FAILED STEP', 'ERROR CODE'])
        save(out / 'expected-table.json', table)
    if detail is not None:
        spec = importlib.util.spec_from_file_location('node_detail_assertion', Path(__file__).with_name('orb355-node-detail.py'))
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        expected_fields = module.assert_node_detail(captured_frames[-1]['lines'], detail)
        save(out / 'expected-detail.json', expected_fields)
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
    record = {'label': label, 'argv': command, 'candidate': args.candidate, 'actual_gateway': True, 'exit': expected, 'columns': columns, 'passed': True}
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


def tagged_rules(node, comment):
    import re
    rows = []
    for line in remote(node, 'sudo ufw status numbered').splitlines():
        body, separator, tag = line.partition('#')
        if not separator or tag.strip() != comment:
            continue
        body = re.sub(r'^\[\s*\d+\]\s*', '', body.strip())
        cells = re.split(r'\s{2,}', body.strip())
        assert len(cells) == 3, (line, cells)
        rows.append(tuple(cells))
    return rows


def without_request(value):
    if isinstance(value, dict):
        return {k: without_request(v) for k, v in value.items() if k != 'request_id'}
    if isinstance(value, list):
        return [without_request(v) for v in value]
    return value

nodes = read('node:list')['nodes']
extra = next((n for n in nodes if n['name'] == 'app-prod-2'), None)
if args.stage == 'recover-node':
    assert extra is None
    extra = next(n for n in json.loads((args.state / 'offline-node/baseline.json').read_text())['nodes'] if n['name'] == 'app-prod-2')
assert extra is not None
dev = next(n for n in nodes if n['name'] == 'app-dev')
prod = next(n for n in nodes if n['name'] == 'app-prod')
node_id = extra['id']
slug = 'ux-' + uuid.uuid4().hex[:8]
baseline_instances = without_request(read('instance:list'))
save(root / 'baseline.json', {'nodes': nodes, 'instances': baseline_instances})

if args.stage == 'firewall':
    assert read('firewall:list', '--node=' + str(node_id))['rules'] == []
    record('empty', ['firewall:list', '--node=' + str(node_id)], ['No firewall rules.'])
    record('allow', ['firewall:allow', slug, '--node=' + str(node_id), '--port=18443', '--from=192.0.2.10'], ['is active.'])
    comment = f'orbit:node:{node_id}:firewall:{slug}'
    assert tagged_rules(extra, comment) == [('18443/tcp', 'ALLOW IN', '192.0.2.10')]
    record('deny', ['firewall:deny', slug + '-deny', '--node=' + str(node_id), '--port=18444', '--from=192.0.2.11'], ['is active.'])
    assert tagged_rules(extra, comment + '-deny') == [('18444/tcp', 'DENY IN', '192.0.2.11')]
    rules = read('firewall:list', '--node=' + str(node_id))['rules']
    firewall_headers = ['NAME', 'ACTION', 'SOURCE', 'PORT', 'PROTOCOL', 'STATUS']
    firewall_rows = [[r[k] for k in ['name', 'action', 'source', 'port', 'protocol', 'status']] for r in rules]
    for width in [24, 80, 160]:
        # Wide firewall rows fit each name in this deliberately short fixture.
        record('list-' + str(width), ['firewall:list', '--node=' + str(node_id)], [slug], columns=width, fallback=[list(zip(firewall_headers, row)) for row in firewall_rows] if width == 24 else None, table=firewall_rows if width >= 80 else None, table_headers=firewall_headers)
    assert {(r['name'], r['action'], str(r['port']), r['protocol'], r['status']) for r in rules} == {(slug, 'allow', '18443', 'tcp', 'active'), (slug + '-deny', 'deny', '18444', 'tcp', 'active')}
    before = without_request(read('firewall:list', '--node=' + str(node_id)))
    ufw_before = remote(extra, 'sudo ufw status numbered')
    for command, error in [
        (['firewall:deny', slug + '-ssh', '--node=' + str(node_id), '--port=22'], 'firewall.public_ssh_deny_forbidden'),
        (['firewall:allow', slug, '--node=' + str(node_id), '--port=18445'], 'firewall.name_taken'),
        (['firewall:deny', slug + '-conflict', '--node=' + str(node_id), '--port=18443', '--from=192.0.2.10'], 'firewall.action_conflict'),
        (['firewall:remove', slug, '--node=' + str(node_id)], 'input.confirmation_required'),
    ]:
        read(*command, expected=1, error=error)
        assert without_request(read('firewall:list', '--node=' + str(node_id))) == before
        assert remote(extra, 'sudo ufw status numbered') == ufw_before
    refusal = read('firewall:remove', slug, '--node=' + str(node_id), expected=1, error='input.confirmation_required')
    record('firewall-json-tty-consent', ['firewall:remove', slug, '--node=' + str(node_id), '--json'], ['input.confirmation_required'], expected=1, json_payload=refusal)
    for label, key in [('default-no', '\r'), ('no', 'n'), ('cancel', '\x03'), ('eof', '\x04'), ('plain-no', '\r'), ('plain-edit-no', 'n')]:
        record(label, ['firewall:remove', slug, '--node=' + str(node_id)], ['cancelled'], expected=1, key=key, prompt='Yes', plain='plain' in label)
        assert without_request(read('firewall:list', '--node=' + str(node_id))) == before
        assert tagged_rules(extra, comment) == [('18443/tcp', 'ALLOW IN', '192.0.2.10')]
    record('accept', ['firewall:remove', slug, '--node=' + str(node_id)], ['removed'], key='y', prompt='Yes', plain=True)
    assert not tagged_rules(extra, comment)
    record('remove-yes', ['firewall:remove', slug + '-deny', '--node=' + str(node_id), '--yes'], ['removed'])
    assert read('firewall:list', '--node=' + str(node_id))['rules'] == []
    assert comment not in remote(extra, 'sudo ufw status numbered')

elif args.stage == 'settings-access':
    for width in [24, 80, 160]:
        node_rows = [[n['id'], n['name'], n['status'], ', '.join(n['roles']) or '—', n['platform'] or '—', '.' + n['tld'].lstrip('.') if n['tld'] else '—', n['user'], n['cluster_id'] or '—', n['wireguard_ip'] or '—', n['lan_ip'] or '—'] for n in read('node:list')['nodes']]
        record('nodes-' + str(width), ['node:list'], ['app-prod-2'], columns=width, table=node_rows if width >= 80 else None, table_headers=['ID', 'NAME', 'STATUS', 'ROLES', 'PLATFORM', 'TLD', 'USER', 'CLUSTER', 'WIREGUARD', 'LAN'], fallback=[list(zip(['ID', 'NAME', 'STATUS', 'ROLES', 'PLATFORM', 'TLD', 'USER', 'CLUSTER', 'WIREGUARD', 'LAN'], row)) for row in node_rows] if width == 24 else None)
        record('detail-' + str(width), ['node:show', node_id], ['Node:', 'app-prod-2'], columns=width, detail=read('node:show', node_id))
        expected_roles = [[r['id'], r['role'], r['status'], r['failed_step'] or '—', r['error_code'] or '—'] for r in read('node:role:list', 'app-prod-2')['assignments']]
        record('roles-' + str(width), ['node:role:list', 'app-prod-2'], [], columns=width, table=expected_roles)
    before = read('node:show', dev['id'])
    instances = [i for i in baseline_instances['app_instances'] if i['node_id'] == dev['id']]
    checkout = instances[0]['checkout_path']
    identity = remote(dev, 'stat -c %i ' + shlex.quote(checkout))
    record('settings', ['node:settings', 'app-dev', '--setting=apps.path:/srv/ux-' + slug], ['updated'])
    assert read('node:show', dev['id'])['settings']['apps']['path'] == '/srv/ux-' + slug
    assert remote(dev, 'stat -c %i ' + shlex.quote(checkout)) == identity
    assert without_request(read('instance:list')) == baseline_instances
    original = ((before.get('settings') or {}).get('apps') or {}).get('path') or ''
    read('node:settings', 'app-dev', '--setting=apps.path:' + original)
    original_access = read('node:show', node_id)['access']
    originally_present = prod['id'] in [n['id'] for n in original_access['can_access']]
    if originally_present:
        read('node:access:remove', node_id, prod['id'], '--force')
    assert prod['id'] not in [n['id'] for n in read('node:show', node_id)['access']['can_access']]
    record('access-add', ['node:access:add', node_id, prod['id']], ['Access', 'added'])
    access = read('node:show', node_id)['access']
    assert prod['id'] in [n['id'] for n in access['can_access']]
    refusal = read('node:access:remove', node_id, prod['id'], expected=1, error='node_access.confirmation_required')
    record('access-json-tty-consent', ['node:access:remove', node_id, prod['id'], '--json'], ['node_access.confirmation_required'], expected=1, json_payload=refusal)
    for label, key in [('access-no', '\r'), ('access-explicit-no', 'n'), ('access-cancel', '\x03'), ('access-eof', '\x04'), ('access-plain-no', '\r'), ('access-plain-edit-no', 'n')]:
        record(label, ['node:access:remove', node_id, prod['id']], ['cancelled'], expected=1, key=key, prompt='Yes', plain='plain' in label)
        assert read('node:show', node_id)['access'] == access
    record('access-accept', ['node:access:remove', node_id, prod['id']], ['removed'], key='y', prompt='Yes', plain=True)
    assert prod['id'] not in [n['id'] for n in read('node:show', node_id)['access']['can_access']]
    record('access-already-absent', ['node:access:remove', node_id, prod['id'], '--force'], ['already absent'])
    if originally_present:
        read('node:access:add', node_id, prod['id'])
    assert read('node:show', node_id)['access'] == original_access

elif args.stage == 'lifecycle':
    assert not any(i['node_id'] == node_id for i in baseline_instances['app_instances'])
    boot_id = remote(extra, 'cat /proc/sys/kernel/random/boot_id')
    record('reconverge-role', ['node:role:add', 'app-prod-2', 'app-prod', '--converge'], ['added'])
    roles = read('node:role:list', node_id)['assignments']
    refusal = read('node:role:remove', node_id, 'app-prod', '--offline', '--purge-data', expected=1, error='validation.failed')
    record('role-json-tty-consent', ['node:role:remove', node_id, 'app-prod', '--offline', '--purge-data', '--json'], ['validation.failed'], expected=1, json_payload=refusal)
    assert read('node:role:list', node_id)['assignments'] == roles
    for label, key in [('role-no', '\r'), ('role-explicit-no', 'n'), ('role-cancel', '\x03'), ('role-eof', '\x04'), ('role-plain-no', '\r'), ('role-plain-edit-no', 'n')]:
        record(label, ['node:role:remove', 'app-prod-2', 'app-prod', '--offline', '--purge-data'], ['cancelled'], expected=1, key=key, prompt='Yes', plain='plain' in label, absent=['Dependent resources:'])
        assert read('node:role:list', node_id)['assignments'] == roles
    record('remove-role', ['node:role:remove', 'app-prod-2', 'app-prod'], ['removed'], key='y', prompt='Yes', plain=True)
    assert read('node:role:list', node_id)['assignments'] == []
    record('empty-roles', ['node:role:list', node_id], ['No roles.'])
    assert 'orbit:public-ssh-recovery' in remote(extra, 'sudo ufw status numbered', public=True)
    refusal = read('node:remove', node_id, '--offline', expected=1, error='node.confirmation_required')
    record('node-json-tty-consent', ['node:remove', node_id, '--offline', '--json'], ['node.confirmation_required'], expected=1, json_payload=refusal)
    for label, key in [('node-no', '\r'), ('node-explicit-no', 'n'), ('node-cancel', '\x03'), ('node-eof', '\x04'), ('node-plain-no', '\r'), ('node-plain-edit-no', 'n')]:
        before = without_request(read('node:show', node_id))
        record(label, ['node:remove', node_id, '--offline'], ['cancelled'], expected=1, key=key, prompt='Yes', plain='plain' in label)
        assert without_request(read('node:show', node_id)) == before
    sentinel = '/var/tmp/orb355-preserve-' + slug
    remote(extra, 'printf %s ' + shlex.quote(slug) + ' > ' + shlex.quote(sentinel))
    retained_command = 'sha256sum /etc/hostname /etc/os-release /etc/ssh/sshd_config ' + shlex.quote(sentinel)
    retained_before = remote(extra, retained_command)
    record('remove-node', ['node:remove', node_id], ['removed'], key='y', prompt='Yes', plain=True)
    read('node:show', node_id, expected=1, error='http.404')
    assert remote(extra, 'cat /proc/sys/kernel/random/boot_id', public=True) == boot_id
    assert remote(extra, retained_command, public=True) == retained_before
    record('add-node', ['node:add', extra['name'], extra['public_ssh_host'], '--user=' + extra['user'], '--ssh-port=' + str(extra['public_ssh_port']), '--wireguard-ip=' + extra['wireguard_ip'], '--host-key-fingerprint=' + extra['ssh_host_fingerprint'], *(['--tld=' + extra['tld']] if extra['tld'] else []), '--role=app-prod'], ['active'])
    restored = next(n for n in read('node:list')['nodes'] if n['name'] == extra['name'])
    assert restored['id'] != node_id and restored['status'] == 'active' and restored['roles'] == ['app-prod']
    assert remote(restored, 'cat /proc/sys/kernel/random/boot_id') == boot_id
    remote(restored, 'rm -- ' + shlex.quote(sentinel))

elif args.stage == 'recover-role':
    assert extra['roles'] == []
    record('reconverge-role', ['node:role:add', node_id, 'app-prod'], ['added'])
    after = read('node:show', node_id)
    assert after['status'] == 'active' and after['roles'] == ['app-prod']
    assert all(r['status'] == 'active' for r in read('node:role:list', node_id)['assignments'])

elif args.stage == 'recover-node':
    record('add-node', ['node:add', extra['name'], extra['public_ssh_host'], '--user=' + extra['user'], '--ssh-port=' + str(extra['public_ssh_port']), '--wireguard-ip=' + extra['wireguard_ip'], '--host-key-fingerprint=' + extra['ssh_host_fingerprint'], *(['--tld=' + extra['tld']] if extra['tld'] else []), '--role=app-prod'], ['active'])
    restored = next(n for n in read('node:list')['nodes'] if n['name'] == extra['name'])
    assert restored['id'] != node_id
    for key in ['name', 'status', 'roles', 'tld', 'public_ssh_host', 'public_ssh_port', 'user', 'wireguard_ip', 'settings']:
        assert restored[key] == extra[key], (key, restored[key], extra[key])
    assert without_request(read('instance:list')) == json.loads((args.state / 'offline-node/baseline.json').read_text())['instances']

elif args.stage == 'remote-failures':
    before_node = without_request(read('node:show', node_id))
    name = slug + '-remote'
    read('firewall:allow', name, '--node=' + str(node_id), '--port=18449', '--from=192.0.2.19')
    before_rules = without_request(read('firewall:list', '--node=' + str(node_id)))
    before_ufw = remote(extra, 'sudo ufw status numbered')
    try:
        for label, command, error in [
            ('deny-public-ssh', ['firewall:deny', slug + '-ssh', '--node=' + str(node_id), '--port=22'], 'firewall.public_ssh_deny_forbidden'),
            ('duplicate-rule', ['firewall:allow', name, '--node=' + str(node_id), '--port=18450'], 'firewall.name_taken'),
            ('conflicting-rule', ['firewall:deny', slug + '-conflict', '--node=' + str(node_id), '--port=18449', '--from=192.0.2.19'], 'firewall.action_conflict'),
            ('missing-rule-before-consent', ['firewall:remove', slug + '-missing', '--node=' + str(node_id)], 'http.404'),
            ('missing-node-before-consent', ['node:remove', '2147483647'], 'http.404'),
        ]:
            payload = read(*command, expected=1, error=error)
            record(label, command, ['Request ID:'], expected=1, wrapped_contains=[payload['error']['message']], absent=['Remove rule', 'Yes /', 'Rollback'])
            assert without_request(read('firewall:list', '--node=' + str(node_id))) == before_rules
            assert remote(extra, 'sudo ufw status numbered') == before_ufw
            assert without_request(read('node:show', node_id)) == before_node
    finally:
        read('firewall:remove', name, '--node=' + str(node_id), '--yes')
    assert not any(r['name'] == name for r in read('firewall:list', '--node=' + str(node_id))['rules'])
    assert not tagged_rules(extra, f'orbit:node:{node_id}:firewall:{name}')
    unavailable = root / 'unavailable-home'
    unavailable.mkdir()
    save(unavailable / 'config.json', {'active_gateway': 'unavailable', 'gateways': {'unavailable': {'url': 'https://127.0.0.1:1', 'ca_path': None}}})
    original_env = env
    try:
        env = dict(env, ORBIT_HOME=str(unavailable))
        payload = read('node:list', expected=1, error='gateway.unreachable')
        record('unavailable-gateway', ['node:list'], [payload['error']['message']], expected=1)
    finally:
        env = original_env
    for label, command in [('missing-node', ['node:show']), ('missing-role', ['node:role:add', str(node_id)]), ('missing-rule-name', ['firewall:allow'])]:
        payload = read(*command, expected=1, error='input.invalid')
        record(label + '-json-tty', command + ['--json'], ['input.invalid'], expected=1, json_payload=payload)
        record(label + '-human', command, ['Not enough arguments'], expected=1)
    assert without_request(read('node:show', node_id)) == before_node

elif args.stage == 'narrow-consent':
    before = without_request(read('node:show', node_id))
    assert before['roles'] == ['app-prod']
    question = f"Remove Node [{extra['name']}] (#{node_id}) from the Gateway?"
    for label, key, expected in [('decline', '\r', 'cancelled'), ('accept-guard', 'y', 'has roles')]:
        out = record('narrow-' + label, ['node:remove', node_id], [expected], expected=1, key=key, prompt='Yes', columns=24)
        events = [json.loads(line) for line in (out / 'capture/input-events.jsonl').read_text().splitlines()]
        frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
        before_input = [frame for frame in frames if frame['elapsed'] <= events[0]['elapsed']]
        def has_question(frame):
            compact = ''.join(''.join(frame['lines']).replace('│', '').split())
            return ''.join(question.split()) in compact and '…' not in ''.join(frame['lines'])
        assert any(has_question(frame) for frame in before_input), (label, question)
        assert has_question(frames[-1]), label
        save(out / 'expected-question.json', {'full_question': question, 'before_first_input': True})
        assert without_request(read('node:show', node_id)) == before

elif args.stage == 'interruption':
    before = without_request(read('node:show', node_id))
    assert not any(i['node_id'] == node_id for i in baseline_instances['app_instances'])
    # Observe several actual running frames before interrupting the admitted request.
    actions = [{'wait_for': 'Adding Node role', 'send': ''} for _ in range(10)]
    actions.append({'wait_for': 'Adding Node role', 'send': '\x03'})
    record('role-post-admission-ctrl-c', ['node:role:add', node_id, 'app-prod', '--converge'],
           ['Operation interrupted.'], expected=130, input_actions=actions, max_after_input=2,
           absent=['Role [app-prod] added to node', 'Rolled back'])
    # A client interruption makes no rollback promise. Observe server completion.
    deadline = time.monotonic() + 120
    while True:
        roles = read('node:role:list', node_id)['assignments']
        if any(r['role'] == 'app-prod' and r['status'] in ['active', 'failed'] for r in roles):
            break
        assert time.monotonic() < deadline, roles
        time.sleep(2)
    after = without_request(read('node:show', node_id))
    save(root / 'server-after-interruption.json', {'before': before, 'after': after, 'assignments': roles, 'rollback_claimed': False})
    assert after['status'] == 'active' and after['roles'] == ['app-prod'] and all(r['status'] == 'active' for r in roles)

elif args.stage == 'offline-reachable':
    assert extra['roles'] == ['app-prod'] and not any(i['node_id'] == node_id for i in baseline_instances['app_instances'])
    before = without_request(read('node:show', node_id))
    payload = read('node:remove', node_id, '--offline', '--force', expected=1, error='node.has_roles')
    record('reachable-offline-refused', ['node:remove', node_id, '--offline', '--force'], [payload['error']['message']], expected=1)
    assert without_request(read('node:show', node_id)) == before

elif args.stage in ['offline-role', 'offline-node']:
    assert extra['roles'] == ['app-prod'] and not any(i['node_id'] == node_id for i in baseline_instances['app_instances'])
    before = without_request(read('node:show', node_id))
    # The separate, recorded extra-Node setup stopped ssh.socket and ssh.service.
    # Incus exec remains the independent management/recovery path.
    probe = subprocess.run(['ssh', '-i', str(Path.home() / '.orbit/ssh/id_ed25519'), '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none', '-o', 'UserKnownHostsFile=' + str(known_hosts), '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=5', extra['user'] + '@' + extra['wireguard_ip'], 'true'], capture_output=True, text=True, timeout=10)
    assert probe.returncode == 255 and ('Connection refused' in probe.stderr or 'Connection timed out' in probe.stderr), (probe.returncode, probe.stderr)
    save(root / 'ssh-unreachable.json', {'exit': probe.returncode, 'stdout': probe.stdout, 'stderr': probe.stderr})
    retained = ['Caddy site configuration and certificates for the app-prod role', 'Orbit firewall rules for the app-prod role', 'Managed PHP-FPM pools and instance checkouts']
    if args.stage == 'offline-role':
        command = ['node:role:remove', node_id, 'app-prod', '--offline', '--purge-data']
        outcome = f"Role [app-prod] removed from node [{extra['name']}] (#{node_id})."
        follow_up = 'Orbit still manages this node. Clean up only the leftovers listed above; provision the node again when it is reachable.'
    else:
        command = ['node:remove', node_id, '--offline']
        outcome = f"Node [{extra['name']}] removed."
        follow_up = 'Discard this node, or clear only the leftovers listed above by hand once it is reachable.'
        retained.append('Metrics node exporter package, its Orbit systemd drop-in and its firewall rule for port 9100')
    out = record(args.stage, command, ['Warning:', 'Left on the node:'], key='y', prompt='Yes', columns=80 if args.stage == 'offline-role' else 24)
    frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
    compact = ''.join(''.join(frames[-1]['lines']).split())
    for text in [outcome, f"Warning: Node [{extra['name']}] was unreachable. Orbit removed only the state it owns.", follow_up, *retained]:
        assert ''.join(text.split()) in compact, (text, compact)
    save(out / 'expected-warning.json', {'outcome': outcome, 'retained': sorted(retained), 'follow_up': follow_up})
    if args.stage == 'offline-role':
        assert read('node:role:list', node_id)['assignments'] == []
        after = without_request(read('node:show', node_id))
        assert after['id'] == before['id'] and after['status'] == 'active' and after['roles'] == []
    else:
        assert 'Rolesshed:app-prod' in compact
        read('node:show', node_id, expected=1, error='http.404')
        assert not any(n['id'] == node_id for n in read('node:list')['nodes'])
    # The following required extra-Node action proves unchanged machine hashes,
    # restores SSH over Incus, and removes exactly its sentinel.

elif args.stage == 'mutation-modes':
    def mutation(label, command, message, mode):
        command = list(map(str, command))
        if mode == 'json-tty':
            out = record(label + '-' + mode, command + ['--json'], ['request_id'])
            value = json.loads((out / 'capture/raw.bin').read_bytes())
            assert isinstance(value.get('request_id'), str) and re.fullmatch(r'[0-9a-f-]{36}', value['request_id']), value
            return value
        out = root / (label + '-pipe')
        out.mkdir()
        argv = ['php', str(launcher), *command, '--ansi']
        print('$ ' + shlex.join(argv) + ' [separate pipes]', flush=True)
        result = subprocess.run(argv, cwd=source, env=env, input=b'', capture_output=True, timeout=240)
        (out / 'stdout.txt').write_bytes(result.stdout)
        (out / 'stderr.txt').write_bytes(result.stderr)
        text = result.stdout.decode()
        assert result.returncode == 0 and result.stderr == b'' and b'\x1b' not in result.stdout, (label, result.returncode, text, result.stderr)
        assert message in text and re.search(r'Request ID: [0-9a-f-]{36}', text), (label, text)
        assert '◉' not in text and 'Working...' not in text, (label, text)
        case = {'label': label + '-pipe', 'argv': argv, 'candidate': args.candidate, 'exit': result.returncode, 'actual_gateway': True, 'passed': True}
        save(out / 'case.json', case); records.append(case); save(root / 'records.json', records)
        print(json.dumps({'label': case['label'], 'passed': True}), flush=True)
        return None

    def exact(actual, expected):
        if actual is not None:
            assert without_request(actual) == without_request(expected), (actual, expected)
            observations.append({'assertion': 'full-mutation-payload', 'actual': actual, 'expected': expected})
            save(root / 'observations.json', observations)

    for mode in ['json-tty', 'pipe']:
        before = read('node:show', node_id)
        original_path = ((before.get('settings') or {}).get('apps') or {}).get('path') or ''
        settings_root = '/srv/ux-' + slug
        remote(extra, 'sudo install -d -o ' + shlex.quote(extra['user']) + ' -g ' + shlex.quote(extra['user']) + ' -m 0755 -- ' + shlex.quote(settings_root))
        value = mutation('settings', ['node:settings', node_id, '--setting=apps.path:/srv/ux-' + slug], f"Node [{extra['name']}] settings updated.", mode)
        after = read('node:show', node_id)
        assert after['settings']['apps']['path'] == '/srv/ux-' + slug
        exact(value, {k: v for k, v in after.items() if k != 'access'})
        read('node:settings', node_id, '--setting=apps.path:' + original_path)
        assert read('node:show', node_id)['settings'] == before['settings']
        remote(extra, 'sudo rmdir -- ' + shlex.quote(settings_root))

        original_access = before['access']
        present = prod['id'] in [n['id'] for n in original_access['can_access']]
        if present:
            read('node:access:remove', node_id, prod['id'], '--force')
        consumer = {'id': node_id, 'name': extra['name']}
        serving = {'id': prod['id'], 'name': prod['name']}
        value = mutation('access-add', ['node:access:add', node_id, prod['id']], f"Access from [{extra['name']}] (#{node_id}) to [{prod['name']}] (#{prod['id']}) added.", mode)
        exact(value, {'consumer_node': consumer, 'serving_node': serving, 'already_exists': False})
        assert prod['id'] in [n['id'] for n in read('node:show', node_id)['access']['can_access']]
        value = mutation('access-remove', ['node:access:remove', node_id, prod['id'], '--force'], f"Access from [{extra['name']}] (#{node_id}) to [{prod['name']}] (#{prod['id']}) removed.", mode)
        exact(value, {'consumer_node': consumer, 'serving_node': serving, 'already_absent': False, 'self_lockout': False})
        assert prod['id'] not in [n['id'] for n in read('node:show', node_id)['access']['can_access']]
        if present:
            read('node:access:add', node_id, prod['id'])
        assert read('node:show', node_id)['access'] == original_access

        for action, port in [('allow', 18445), ('deny', 18446)]:
            name = slug + '-' + action + '-' + mode
            comment = f'orbit:node:{node_id}:firewall:{name}'
            value = mutation('firewall-' + action, ['firewall:' + action, name, '--node=' + str(node_id), '--port=' + str(port), '--from=192.0.2.12'], f'Firewall rule [{name}] is active.', mode)
            rule = next(r for r in read('firewall:list', '--node=' + str(node_id))['rules'] if r['name'] == name)
            expected = dict(rule, backend_status='active')
            exact(value, expected)
            assert tagged_rules(extra, comment) == [(str(port) + '/tcp', action.upper() + ' IN', '192.0.2.12')]
            value = mutation('firewall-remove-' + action, ['firewall:remove', name, '--node=' + str(node_id), '--yes'], f'Firewall rule [{name}] removed.', mode)
            exact(value, dict(rule, backend_status='absent'))
            assert not tagged_rules(extra, comment)
            assert not any(r['name'] == name for r in read('firewall:list', '--node=' + str(node_id))['rules'])

        value = mutation('role-add', ['node:role:add', node_id, 'app-prod', '--converge'], f"Role [app-prod] added to node [{extra['name']}] (#{node_id}).", mode)
        role = next(r for r in read('node:role:list', node_id)['assignments'] if r['role'] == 'app-prod')
        assert role['status'] == 'active'
        expected = {'node_id': node_id, 'node_name': extra['name'], 'role': 'app-prod', 'assignment': role, 'removed': False, 'degradation': None, 'retained_on_node': [], 'follow_up': None}
        exact(value, expected)
        value = mutation('role-remove', ['node:role:remove', node_id, 'app-prod', '--force'], f"Role [app-prod] removed from node [{extra['name']}] (#{node_id}).", mode)
        exact(value, dict(expected, assignment=None, removed=True))
        assert read('node:role:list', node_id)['assignments'] == []
        value = mutation('node-remove', ['node:remove', node_id, '--force'], f"Node [{extra['name']}] removed.", mode)
        exact(value, {'id': node_id, 'name': extra['name'], 'removed': True, 'wireguard_peer_removed': True, 'dns_records_removed': True, 'degradation': None, 'roles_shed': [], 'retained_on_node': [], 'follow_up': None})
        read('node:show', node_id, expected=1, error='http.404')
        value = mutation('node-add', ['node:add', extra['name'], extra['public_ssh_host'], '--user=' + extra['user'], '--ssh-port=' + str(extra['public_ssh_port']), '--wireguard-ip=' + extra['wireguard_ip'], '--host-key-fingerprint=' + extra['ssh_host_fingerprint'], *(['--tld=' + extra['tld']] if extra['tld'] else []), '--role=app-prod'], f"Node [{extra['name']}] is active.", mode)
        restored = next(n for n in read('node:list')['nodes'] if n['name'] == extra['name'])
        node_id = restored['id']
        after = read('node:show', node_id)
        exact(value, {k: v for k, v in after.items() if k != 'access'})
        assert after['status'] == 'active' and after['roles'] == ['app-prod'] and after['tld'] == extra['tld']
        extra = restored
        assert without_request(read('instance:list')) == baseline_instances

if args.stage in ['modes', 'read-modes']:
    before_nodes = without_request(read('node:list'))
    before_rules = without_request(read('firewall:list', '--node=' + str(node_id)))
    negatives = [
        (['node:add', 'invalid-proof', '--ssh-port=0'], 'node.ssh_port_invalid'),
        (['node:show', '0'], 'node.id_invalid'),
        (['node:remove', '0'], 'node.id_invalid'),
        (['node:role:add', '0', 'database'], 'node.id_invalid'),
        (['node:role:list', '0'], 'node.id_invalid'),
        (['node:role:remove', '0', 'database'], 'node.id_invalid'),
        (['node:access:add', '0', str(node_id)], 'node_access.consumer_id_invalid'),
        (['node:access:remove', '0', str(node_id)], 'node_access.consumer_id_invalid'),
        (['node:settings', str(node_id)], 'node.setting_required'),
        (['firewall:allow', 'invalid-proof', '--node=0', '--port=18445'], 'firewall.node_id_invalid'),
        (['firewall:deny', 'invalid-proof', '--node=0', '--port=18445'], 'firewall.node_id_invalid'),
        (['firewall:list', '--node=0'], 'firewall.node_id_invalid'),
        (['firewall:remove', 'invalid-proof', '--node=0'], 'firewall.node_id_invalid'),
    ]
    for command, error in negatives if args.stage == 'modes' else []:
        label = command[0].replace(':', '-')
        payload = read(*command, expected=1, error=error)
        message = payload['error']['message']
        record(label + '-invalid-human', command, [message], expected=1)
        record(label + '-invalid-json-tty', command + ['--json'], [error], expected=1, json_payload=payload)
        record(label + '-invalid-plain', command, [message], expected=1, plain=True)
        result = subprocess.run(['php', str(launcher), *command, '--ansi'], cwd=source, env=env, input=b'', capture_output=True, timeout=30)
        assert result.returncode == 1 and result.stderr == b'' and b'\x1b' not in result.stdout
        assert message in result.stdout.decode()
        save(root / (label + '-pipe.json'), {'argv': command + ['--ansi'], 'stdout': result.stdout.decode(), 'stderr': result.stderr.decode(), 'exit': result.returncode})
    for label, command, keys in [
        ('nodes', ['node:list'], {'nodes', 'request_id'}),
        ('node', ['node:show', str(node_id)], {'id', 'name', 'status', 'request_id'}),
        ('roles', ['node:role:list', str(node_id)], {'assignments', 'request_id'}),
        ('firewall', ['firewall:list', '--node=' + str(node_id)], {'rules', 'request_id'}),
        ('firewall-populated', ['firewall:list', '--node=' + str(node_id)], {'rules', 'request_id'}),
    ]:
        if label == 'firewall-populated':
            read('firewall:allow', slug, '--node=' + str(node_id), '--port=18447', '--from=192.0.2.17')
        payload = read(*command)
        assert keys <= payload.keys()
        record(label + '-json-tty', command + ['--json'], ['request_id'], json_payload=payload)
        checks = {}
        if label == 'nodes':
            checks = {'table': [[n['id'], n['name'], n['status'], ', '.join(n['roles']) or '—', n['platform'] or '—', '.' + n['tld'].lstrip('.') if n['tld'] else '—', n['user'], n['cluster_id'] or '—', n['wireguard_ip'] or '—', n['lan_ip'] or '—'] for n in payload['nodes']], 'table_headers': ['ID', 'NAME', 'STATUS', 'ROLES', 'PLATFORM', 'TLD', 'USER', 'CLUSTER', 'WIREGUARD', 'LAN']}
        elif label == 'node':
            checks = {'detail': payload}
        elif label == 'roles':
            checks = {'table': [[r['id'], r['role'], r['status'], r['failed_step'] or '—', r['error_code'] or '—'] for r in payload['assignments']], 'table_headers': ['ID', 'ROLE', 'STATUS', 'FAILED STEP', 'ERROR CODE']}
        elif payload['rules']:
            checks = {'table': [[r[k] for k in ['name', 'action', 'source', 'port', 'protocol', 'status']] for r in payload['rules']], 'table_headers': ['NAME', 'ACTION', 'SOURCE', 'PORT', 'PROTOCOL', 'STATUS']}
        content = ['No firewall rules.'] if label == 'firewall' and not payload['rules'] else ['Request ID:']
        record(label + '-plain', command, content, plain=True, **checks)
        result = subprocess.run(['php', str(launcher), *command, '--ansi'], cwd=source, env=env, input=b'', capture_output=True, timeout=30)
        assert result.returncode == 0 and result.stderr == b'' and b'\x1b' not in result.stdout
        lines = result.stdout.decode().splitlines()
        assert all(value in result.stdout.decode() for value in content)
        if 'table' in checks:
            assert_wrapped_table(lines, checks['table'], checks['table_headers'])
        if 'detail' in checks:
            spec = importlib.util.spec_from_file_location('node_detail_assertion', Path(__file__).with_name('orb355-node-detail.py'))
            module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
            module.assert_node_detail(lines, payload)
        save(root / (label + '-pipe.json'), {'argv': command + ['--ansi'], 'stdout': result.stdout.decode(), 'stderr': result.stderr.decode(), 'exit': result.returncode})
        if label == 'firewall-populated':
            read('firewall:remove', slug, '--node=' + str(node_id), '--yes')
            assert not tagged_rules(extra, f'orbit:node:{node_id}:firewall:{slug}')
    assert without_request(read('node:list')) == before_nodes
    assert without_request(read('firewall:list', '--node=' + str(node_id))) == before_rules

assert without_request(read('instance:list')) == baseline_instances
save(root / 'result.json', {'candidate': args.candidate, 'stage': args.stage, 'passed': True, 'records': records, 'observations': len(observations), 'actual_gateway': True})
print(json.dumps({'stage': args.stage, 'passed': True, 'records': len(records), 'root': str(root)}))
