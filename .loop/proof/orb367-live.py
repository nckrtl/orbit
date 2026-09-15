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

if len(sys.argv) > 1 and sys.argv[1] == '--child':
    before = termios.tcgetattr(0)
    child = subprocess.Popen(sys.argv[3:])
    signal.signal(signal.SIGINT, signal.SIG_IGN)
    code = child.wait()
    after = termios.tcgetattr(0)
    Path(sys.argv[2]).write_text(json.dumps({'before': repr(before), 'after': repr(after), 'equal': before == after, 'exit': code}))
    sys.exit(code)

parser = argparse.ArgumentParser()
parser.add_argument('--candidate', required=True)
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--stage', choices=['lifecycle'], required=True)
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

def record(label, argv, contains, *, expected=0, key=None, prompt=None, columns=100, plain=False, table=None, table_headers=None, fallback=None):
    out = root / label
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--no-ansi' if plain else '--ansi']
    capture = [sys.executable, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
               '--candidate', args.candidate, '--label', label, '--columns', str(columns), '--rows', '60',
               '--timeout', '600', '--idle-timeout', '600']
    if not args.visible:
        capture.append('--no-live')
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
    result = subprocess.run(capture, cwd=source, env=env, capture_output=not args.visible, timeout=615)
    assert result.returncode == expected, (label, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal'], label
    if '--json' in argv:
        raw = (out / 'capture/raw.bin').read_bytes()
        assert b'\x1b' not in raw, label
        json.loads(raw)
    captured_frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
    assert captured_frames[-1]['cursor']['hidden'] is False, label
    expectation = {'candidate': args.candidate, 'label': label, 'exit_code': expected, 'contains': contains if table is None else ['Request ID:'], 'final_contains': contains if table is None else ['Request ID:']}
    if table is not None:
        frames = [json.loads(line) for line in (out / 'capture/frames.jsonl').read_text().splitlines()]
        assert_wrapped_table(frames[-1]['lines'], table, table_headers or ['ID', 'ROLE', 'STATUS', 'FAILED STEP', 'ERROR CODE'])
        save(out / 'expected-table.json', table)
    if fallback is not None:
        compact = ''.join(''.join(captured_frames[-1]['lines']).split())
        expected_records = ''.join(''.join(str(k).split()) + ':' + ''.join(str(v).split()) for row in fallback for k, v in row)
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


def stable(value):
    if isinstance(value, dict):
        return {k: stable(v) for k, v in value.items() if k != 'request_id'}
    if isinstance(value, list):
        return [stable(v) for v in value]
    return value

nodes = read('node:list')['nodes']
extra = next(n for n in nodes if n['name'] == 'app-prod-2')
assert extra['status'] == 'active' and extra['roles'] == ['app-prod'], extra
baseline_instances = stable(read('instance:list'))
private_domain = 'gateway.orbit'
private_address = next(n for n in nodes if n['name'] == 'gateway')['wireguard_ip']
assert not any(i['node_id'] == extra['id'] for i in baseline_instances['app_instances'])
slug = uuid.uuid4().hex
sentinel = '/var/tmp/orb367-preserve-' + slug
remote(extra, 'printf %s ' + shlex.quote(slug) + ' > ' + shlex.quote(sentinel))
files = 'sha256sum /etc/hostname /etc/os-release /etc/ssh/sshd_config ' + shlex.quote(sentinel)
boot = remote(extra, 'cat /proc/sys/kernel/random/boot_id')
retained = remote(extra, files)
resolver_before = remote(extra, 'sudo cat /etc/wireguard/orbit.dns-link; resolvectl dns orbit; resolvectl domain orbit')
assert 'orbit\n10.44.0.1\n.\n' in resolver_before and '~.' in resolver_before, resolver_before
policy_program = r'''
import subprocess,json,re,hashlib
result={}
for prop in ['default-route','llmnr','mdns','dnssec','dnsovertls','nta']:
    value=subprocess.check_output(['resolvectl',prop,'orbit'],text=True)
    result[prop]=re.sub(r'^Link [0-9]+ \(orbit\): *', '', value).strip()
result['marker']=subprocess.check_output(['sudo','cat','/etc/wireguard/orbit.dns-link'],text=True)
for path in ['/etc/resolv.conf','/etc/nsswitch.conf']:
    result[path]=hashlib.sha256(open(path,'rb').read()).hexdigest()
print(json.dumps(result))
'''
policy_before = json.loads(remote(extra, 'python3 -c ' + shlex.quote(policy_program)))
save(root / 'resolver-policy-before.json', policy_before)
save(root / 'baseline.json', {'nodes': nodes, 'instances': baseline_instances, 'boot': boot, 'files': retained, 'resolver': resolver_before, 'sentinel': sentinel})
record('remove-role', ['node:role:remove', extra['name'], 'app-prod', '--force'], ['removed'])
assert read('node:role:list', extra['id'])['assignments'] == []
record('remove-node', ['node:remove', extra['id'], '--force'], ['removed'])
assert all(n['name'] != extra['name'] for n in read('node:list')['nodes'])
assert remote(extra, 'cat /proc/sys/kernel/random/boot_id', public=True) == boot
assert remote(extra, files, public=True) == retained

def resolver_probe(node, public):
    program = r'''
import json, subprocess, time, urllib.parse
sources = subprocess.run(['sudo','timeout','15','apt-get','--print-uris','update'], capture_output=True, text=True)
assert sources.returncode == 0, (sources.returncode,sources.stderr)
hosts=[]
for line in sources.stdout.splitlines():
    if line.startswith("'"):
        uri=line.split("'",2)[1]
        if uri.startswith(('http://','https://')):
            host=urllib.parse.urlparse(uri).hostname
            if host not in hosts: hosts.append(host)
assert hosts
flush=subprocess.run(['sudo','resolvectl','flush-caches'], capture_output=True, text=True)
assert flush.returncode==0
commands=[['resolvectl','dns','orbit'],['resolvectl','domain','orbit'],['timeout','8','getent','ahosts',hosts[0]]]
results=[]
for command in commands:
    started=time.monotonic()
    result=subprocess.run(command,capture_output=True,text=True,timeout=12)
    results.append({'argv':command,'exit':result.returncode,'stdout':result.stdout,'stderr':result.stderr,'seconds':time.monotonic()-started})
print(json.dumps({'source_host':hosts[0],'results':results}))
'''
    return json.loads(remote(node, 'python3 -c ' + shlex.quote(program), public=public))

failed = resolver_probe(extra, True)
save(root / 'removed-resolver-probe.json', failed)
assert failed['results'][0]['exit'] == failed['results'][1]['exit'] == 0
assert '10.44.0.1' in failed['results'][0]['stdout'] and '~.' in failed['results'][1]['stdout']
assert failed['results'][2]['exit'] in [2,124] and not failed['results'][2]['stdout'], failed
record('readd-node', ['node:add', extra['name'], extra['public_ssh_host'], '--user=' + extra['user'], '--ssh-port=' + str(extra['public_ssh_port']), '--wireguard-ip=' + extra['wireguard_ip'], '--host-key-fingerprint=' + extra['ssh_host_fingerprint'], *(['--tld=' + extra['tld']] if extra['tld'] else []), '--role=app-prod'], ['active'])
restored = next(n for n in read('node:list')['nodes'] if n['name'] == extra['name'])
assert restored['id'] != extra['id'] and restored['status'] == 'active' and restored['roles'] == ['app-prod'], restored
assert restored['tld'] == extra['tld']
assert remote(restored, 'cat /proc/sys/kernel/random/boot_id') == boot
assert remote(restored, files) == retained
working = resolver_probe(restored, False)
save(root / 'restored-resolver-probe.json', working)
assert all(r['exit'] == 0 for r in working['results']), working
assert working['source_host'] == failed['source_host']
policy_after = json.loads(remote(restored, 'python3 -c ' + shlex.quote(policy_program)))
save(root / 'resolver-policy-after.json', policy_after)
assert policy_after == policy_before, (policy_before,policy_after)
assert '10.44.0.1' in working['results'][0]['stdout'] and '~.' in working['results'][1]['stdout']
private_lookup = remote(restored, 'timeout 8 getent ahostsv4 ' + shlex.quote(private_domain))
assert {line.split()[0] for line in private_lookup.splitlines()} == {private_address}, private_lookup
save(root / 'private-dns.json', {'domain':private_domain, 'output':private_lookup})
assert stable(read('instance:list')) == baseline_instances
remote(restored, 'rm -- ' + shlex.quote(sentinel))
save(root / 'result.json', {'candidate':args.candidate,'passed':True,'old_node':extra,'new_node':restored,'manual_dns_configuration_edits':False,'records':records})
print(json.dumps({'stage':'lifecycle','passed':True,'records':len(records),'root':str(root)}), flush=True)
