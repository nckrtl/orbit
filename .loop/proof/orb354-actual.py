#!/usr/bin/env python3
"""Run all 23 family commands on an issue-owned real Gateway and inspect resulting state."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import shlex
import subprocess
import sys
import uuid

parser = argparse.ArgumentParser()
parser.add_argument('--source', type=Path, default=Path('/home/orbit/orbit'))
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--candidate', required=True)
parser.add_argument('--discovery-manifest', type=Path)
parser.add_argument('--visible', action='store_true')
parser.add_argument('--stage', choices=['all', 'routes'], default='all')
args = parser.parse_args()
os.umask(0o077)
root = args.state / 'actual'
root.mkdir(parents=True)
source = args.source
if args.discovery_manifest:
    manifest = json.loads(args.discovery_manifest.read_text())
    candidate = manifest['candidate']
    assert candidate == args.candidate
    for entry in manifest['files']:
        assert hashlib.sha256((source / entry['path']).read_bytes()).hexdigest() == entry['sha256'], entry['path']
    (root / 'discovery-source.json').write_text(args.discovery_manifest.read_text())
else:
    candidate = subprocess.check_output(['git', '-C', str(source), 'rev-parse', 'HEAD'], text=True).strip()
    assert candidate == args.candidate
    assert not subprocess.check_output(['git', '-C', str(source), 'status', '--porcelain', '--untracked-files=no'], text=True).strip()
private = root / 'gateway-home'
private.mkdir()
shutil.copyfile(Path.home() / '.orbit/config.json', private / 'config.json')
env = dict(os.environ, ORBIT_HOME=str(private), TERM='xterm-256color', LC_ALL='C.UTF-8', PAO_DISABLE='1')
for key in ['NO_COLOR', 'FORCE_COLOR', 'COLUMNS', 'LINES']:
    env.pop(key, None)
launcher = source / 'apps/cli/orbit'
recorder = source / '.agents/skills/verifying-cli-output/scripts'
wrapper = Path(__file__).with_name('orb354-commands.py')
records, observations = [], []


def save(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')


def read(*argv, expected=0):
    command = ['php', str(launcher), *map(str, argv), '--json']
    result = subprocess.run(command, cwd=source, env=env, capture_output=True, timeout=120)
    assert result.returncode == expected, (argv, result.returncode, result.stdout, result.stderr)
    assert result.stderr == b'' and b'\x1b' not in result.stdout, (argv, result.stderr)
    payload = json.loads(result.stdout)
    observations.append({'argv': command, 'status': result.returncode, 'payload': payload})
    save(root / 'observations.json', observations)
    return payload


def record(argv, contains, *, expected=0, inputs=None):
    name = f'{len(records):02d}-' + argv[0].replace(':', '-')
    out = root / name
    out.mkdir()
    command = ['php', str(launcher), *map(str, argv), '--ansi']
    capture = [sys.executable, str(recorder / 'capture.py'), '--output-dir', str(out / 'capture'),
               '--candidate', candidate, '--label', name, '--columns', '100', '--rows', '60',
               '--timeout', '120', '--idle-timeout', '30']
    if not args.visible:
        capture += ['--no-live']
    else:
        print('$ ' + shlex.join(command), flush=True)
    if inputs:
        save(out / 'inputs.json', inputs)
        capture += ['--input-plan', str(out / 'inputs.json')]
    capture += ['--', sys.executable, str(wrapper), '--child', str(out / 'terminal.json'), *command]
    save(out / 'case.json', {'candidate': candidate, 'argv': command, 'launcher': str(launcher),
                            'actual_gateway': True, 'PAO_DISABLE': '1', 'expected_exit': expected})
    result = subprocess.run(capture, cwd=source, env=env, capture_output=not args.visible, timeout=135)
    assert result.returncode == expected, (name, result.returncode, result.stdout, result.stderr)
    assert json.loads((out / 'terminal.json').read_text())['equal']
    expectation = {'candidate': candidate, 'label': name, 'exit_code': expected, 'contains': contains,
                   'max_first_output_seconds': 2}
    save(out / 'expectation.json', expectation)
    verified = subprocess.run([sys.executable, str(recorder / 'verify.py'), '--capture', str(out / 'capture'),
                               '--expect', str(out / 'expectation.json')], capture_output=True, text=True)
    (out / 'verify.json').write_text(verified.stdout)
    assert verified.returncode == 0, (name, verified.stdout, verified.stderr)
    records.append({'name': name, 'command': argv[0], 'passed': True})
    save(root / 'records.json', records)
    print(json.dumps(records[-1]), flush=True)


baseline_nodes = read('node:list')['nodes']
prod = next(n for n in baseline_nodes if n['name'] == 'app-prod')
assert prod['cluster_id'] is None
baseline_clusters = read('cluster:list')['clusters']
existing = next(c for c in baseline_clusters if c['router'] and c['state'] == 'active')
existing_id, router_id = existing['id'], existing['router']['id']
instance = next(i for i in read('instance:list')['app_instances'] if i['node_id'] == prod['id'] and i['status'] == 'active')
suffix = uuid.uuid4().hex[:8]
slug, cluster_name = 'ux-' + suffix, 'ux-' + suffix
if args.stage == 'all':
    record(['app:create', slug, 'https://github.com/laravel/framework.git', '--default-branch=13.x'], [slug, 'created'])
    created = next(a for a in read('app:list')['apps'] if a['slug'] == slug)
    app_id = created['id']
    record(['app:list'], [slug])
    record(['app:show', app_id], ['App: ' + slug, 'Web root'])
    record(['app:update', app_id, '--default-branch=12.x', '--root=src'], [slug, 'updated'])
    updated = read('app:show', app_id)
    assert updated['default_branch'] == '12.x' and updated['root'] == 'src'
    # Refused automation does not remove the resolved App.
    refusal = read('app:destroy', app_id, expected=1)
    assert refusal['error']['code'] == 'input.confirmation_required'
    assert read('app:show', app_id)['id'] == app_id
    record(['app:destroy', app_id, '--yes'], [slug, 'removed'])
    assert all(a['id'] != app_id for a in read('app:list')['apps'])
    record(['cluster:create', cluster_name, '--tld=uxproof'], [cluster_name, 'created'])
    new_cluster = next(c for c in read('cluster:list')['clusters'] if c['name'] == cluster_name)
    cluster_id = new_cluster['id']
    record(['cluster:list'], [cluster_name])
    record(['cluster:show', cluster_id], ['Cluster: ' + cluster_name, '.uxproof'])
    record(['cluster:update', cluster_id, '--tld=uxnext'], [cluster_name, 'updated'])
    assert read('cluster:show', cluster_id)['tld'] == 'uxnext'
    record(['cluster:node:add', cluster_id, prod['id']], ['attached', cluster_name])
    assert prod['id'] in [n['id'] for n in read('cluster:show', cluster_id)['nodes']]
    record(['cluster:node:remove', cluster_id, prod['id'], '--force'], ['detached', cluster_name])
    assert read('cluster:show', cluster_id)['nodes'] == []
    record(['cluster:destroy', cluster_id, '--force'], [cluster_name, 'removed'])
    assert all(c['id'] != cluster_id for c in read('cluster:list')['clusters'])
    # Exercise Router transitions on the cloned fixture Cluster, then restore it.
    record(['cluster:update', existing_id, '--state=inactive'], ['updated'])
    record(['cluster:router:unset', existing_id, '--force'], ['Router cleared'])
    assert read('cluster:show', existing_id)['router'] is None
    record(['cluster:router:set', existing_id, router_id], ['set as Router'])
    assert read('cluster:show', existing_id)['router']['id'] == router_id
    record(['cluster:update', existing_id, '--state=active'], ['updated'])
    assert read('cluster:show', existing_id)['state'] == existing['state']
domain = suffix + '.uxproof.test'
record(['route:create', instance['app_id'], domain, '--node=' + str(prod['id'])], ['Route: ' + domain])
route = next(r for r in read('route:list')['routes'] if r['domain'] == domain)
route_id = route['id']
record(['route:list'], [domain])
record(['route:show', route_id], ['Route: ' + domain, 'Targets'])
next_domain = suffix + '-next.uxproof.test'
record(['route:update', route_id, '--domain=' + next_domain], ['Route: ' + next_domain])
replacement = next(r for r in read('route:list')['routes'] if r['domain'] == next_domain)
assert replacement['id'] != route_id and replacement['replaces_route_id'] is None
assert read('route:show', route_id, expected=1)['error']['code'] == 'http.404'
route_id, domain = replacement['id'], next_domain
original_route_id = instance['route']['id']
original_targets = read('route:show', original_route_id)['targets']
record(['route:target:set', route_id, instance['id']], ['already associated'], expected=1)
assert read('route:show', route_id)['targets'] == []
assert read('route:show', original_route_id)['targets'] == original_targets
# Documented same-target success keeps the existing authoritative association.
record(['route:target:set', original_route_id, instance['id']], ['Route:', 'Targets'])
assert read('route:show', original_route_id)['targets'] == original_targets
record(['route:target:unset', original_route_id, '--yes'], ['must remain associated'], expected=1)
assert read('route:show', original_route_id)['targets'] == original_targets
refusal = read('route:target:unset', route_id, expected=1)
assert refusal['error']['code'] == 'input.confirmation_required'
assert read('route:show', route_id)['targets'] == []
# Documented empty-clear success must not affect another Route's association.
record(['route:target:unset', route_id, '--yes'], ['Route: ' + domain])
assert read('route:show', route_id)['targets'] == []
assert read('route:show', original_route_id)['targets'] == original_targets
refusal = read('route:destroy', route_id, expected=1)
assert refusal['error']['code'] == 'input.confirmation_required'
assert read('route:show', route_id)['id'] == route_id
record(['route:destroy', route_id, '--yes'], ['Route: ' + domain])
assert all(r['id'] != route_id for r in read('route:list')['routes'])
record(['activity:list'], ['COMMAND'])
activities = read('activity:list')['activities']
assert activities
record(['activity:show', activities[0]['id']], ['Activity:', 'Command'])
expected_commands = {c['argv'][0] for c in json.loads(Path(__file__).with_name('orb354-cases.json').read_text())}
if args.stage == 'routes':
    expected_commands = {c for c in expected_commands if c.startswith(('route:', 'activity:'))}
assert {r['command'] for r in records} == expected_commands
save(root / 'result.json', {'candidate': candidate, 'passed': True, 'records': records, 'commands': sorted(expected_commands),
                           'restored_cluster': existing_id, 'removed_app': app_id if args.stage == 'all' else None, 'removed_cluster': cluster_id if args.stage == 'all' else None,
                           'removed_route': route_id, 'baseline_nodes': [n['id'] for n in baseline_nodes]})
