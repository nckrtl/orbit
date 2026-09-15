#!/usr/bin/env python3
"""Recovery and state assertions for this issue's disposable topology."""
import argparse, ipaddress, json, os, pathlib, shlex, socket, subprocess

p = argparse.ArgumentParser()
p.add_argument('action', choices=['self-baseline', 'self-restore-human', 'self-restore-json', 'consumer-read', 'prepare-offline-recovery'])
p.add_argument('--state', type=pathlib.Path, required=True)
a = p.parse_args()
assert socket.gethostname().startswith('orbit-e2e-orb-355-')
os.umask(0o077)
a.state.mkdir(parents=True, exist_ok=True)

def read(*argv):
    r = subprocess.run(['php', '/home/orbit/orbit/apps/cli/orbit', *map(str, argv), '--json'], env=dict(os.environ, PAO_DISABLE='1'), input=b'', capture_output=True, timeout=120)
    assert r.returncode == 0 and not r.stderr and b'\x1b' not in r.stdout, (argv, r.returncode, r.stdout, r.stderr)
    return json.loads(r.stdout)

def norm(v):
    if isinstance(v, dict):
        return {k: norm(x) for k, x in v.items() if k != 'request_id'}
    if isinstance(v, list):
        return [norm(x) for x in v]
    return v

def save(name, value):
    (a.state / name).write_text(json.dumps(value, indent=2))

nodes = read('node:list')['nodes']
dev = next(n for n in nodes if n['name'] == 'app-dev')
gateway = next(n for n in nodes if n['name'] == 'gateway')
if a.action == 'self-baseline':
    assert socket.gethostname().endswith('-gateway')
    assert not (a.state / 'before.json').exists()
    node = read('node:show', dev['id'])
    assert any(n['id'] == gateway['id'] for n in node['access']['can_access'])
    save('before.json', {'node': node, 'instances': read('instance:list')})
elif a.action.startswith('self-restore-'):
    assert socket.gethostname().endswith('-gateway')
    before = json.loads((a.state / 'before.json').read_text())
    reply = read('node:access:add', dev['id'], gateway['id'])
    assert reply['already_exists'] is False
    after = {'node': read('node:show', dev['id']), 'instances': read('instance:list')}
    assert norm(before) == norm(after)
    save(a.action + '.json', {'restored': True, 'reply': reply, 'before': before, 'after': after})
elif a.action == 'consumer-read':
    assert socket.gethostname().endswith('-app-dev')
    result = read('node:show', gateway['id'])
    save('consumer-read.json', result)
else:
    assert socket.gethostname().endswith('-gateway')
    extra = next(n for n in nodes if n['name'] == 'app-prod-2')
    address = str(ipaddress.IPv4Address(gateway['public_ssh_host']))
    assert ipaddress.ip_address(address).is_private
    descriptor = {'gateway_public_ssh_host': address, 'extra_public_ssh_host': extra['public_ssh_host'], 'extra_wireguard_ip': extra['wireguard_ip']}
    target = '/home/orbit/.local/state/orbit-cli-ux/ORB-355/offline-host/recovery.json'
    command = 'sudo mkdir -p ' + shlex.quote(str(pathlib.Path(target).parent)) + ' && sudo tee ' + shlex.quote(target)
    r = subprocess.run(['ssh', '-i', str(pathlib.Path.home() / '.orbit/ssh/id_ed25519'), '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile=' + str(pathlib.Path.home() / '.orbit/ssh/known_hosts'), extra['user'] + '@' + extra['wireguard_ip'], command], input=json.dumps(descriptor), text=True, capture_output=True, timeout=30)
    assert r.returncode == 0, (r.stdout, r.stderr)
    assert json.loads(r.stdout) == descriptor
    save('offline-recovery-descriptor.json', descriptor)
print(json.dumps({'action': a.action, 'passed': True, 'state': str(a.state)}))
