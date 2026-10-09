"""Publish immutable Incus pair and blank workload images and source; never promote or enable claims."""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import subprocess
import sys
import urllib.parse
import uuid


class Refusal(ValueError):
    pass


WORKLOAD_ROLES = ('app-dev', 'app-prod', 'app-prod-2')


def workload_roles(request):
    roles = request.get('workload_roles', [])
    if (not isinstance(roles, list) or any(not isinstance(role, str) for role in roles)
            or roles != [role for role in WORKLOAD_ROLES if role in roles]):
        raise Refusal('Invalid workload image roles.')
    return roles


def validate(request):
    if not isinstance(request, dict) or set(request) - {'workload_roles'} != {'project', 'pool', 'sandbox_id', 'source_template'}:
        raise Refusal('Invalid publication request.')
    workload_roles(request)
    for key in ('project', 'pool'):
        if not isinstance(request[key], str) or not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}', request[key]):
            raise Refusal('Invalid Incus scope.')
    if request['project'] == 'default':
        raise Refusal('Use a dedicated candidate project.')
    if not isinstance(request['sandbox_id'], str) or str(uuid.UUID(request['sandbox_id'])) != request['sandbox_id']:
        raise Refusal('Invalid candidate identity.')
    template = request['source_template']
    if (not isinstance(template, dict) or set(template) != {'id', 'repository', 'base', 'commit'}
            or not all(isinstance(value, str) for value in template.values())
            or str(uuid.UUID(template['id'])) != template['id']
            or template['id'] == request['sandbox_id']
            or not re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git', template['repository'])
            or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', template['commit'])
            or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._/-]{0,199}', template['base'])
            or any(part in template['base'] for part in ('..', '//', '@{'))
            or any(part.endswith('.lock') or part.startswith('.') for part in template['base'].split('/'))
            or template['base'] == 'HEAD' or template['base'].endswith(('/', '.'))):
        raise Refusal('Invalid template identity.')
    return request


class Publisher:
    def __init__(self, request):
        self.request = validate(request)
        self.project, self.pool = request['project'], request['pool']
        self.template = request['source_template']
        self.roles = ('operator', 'gateway', *workload_roles(request))
        self.name = 'ot-' + hashlib.sha256(request['sandbox_id'].encode()).hexdigest()[:10]
        self.target = 'ot-template-' + hashlib.sha256(self.template['id'].encode()).hexdigest()[:10]
        self.metadata = {'user.orbit.template.owner': 'orbit-task-template',
                         **{'user.orbit.template.' + key: value for key, value in self.template.items()}}
        self.helpers = Path(__file__).resolve().parent

    def run(self, *args, data=None, timeout=1200):
        result = subprocess.run(['incus', '--force-local', *([] if args[0] == 'query' else ['--project', self.project]), *args],
                                input=data, capture_output=True, text=True, timeout=timeout)
        if result.returncode:
            raise Refusal('An Incus publication operation failed.')
        return result.stdout

    def query(self, path, method='GET', data=None):
        path += ('&' if '?' in path else '?') + urllib.parse.urlencode({'project': self.project})
        arguments = ['query', path]
        if method != 'GET':
            arguments += ['-X', method, '--wait']
        if data is not None:
            arguments += ['-d', json.dumps(data)]
        result = self.run(*arguments)
        return json.loads(result) if result.strip() else None

    def volume_path(self, name):
        return '/1.0/storage-pools/' + self.pool + '/volumes/custom/' + name

    def images(self):
        return self.query('/1.0/images?recursion=1')

    def outputs(self):
        volumes = self.query('/1.0/storage-pools/' + self.pool + '/volumes/custom?recursion=1')
        return ([image for image in self.images() if image.get('properties', {}).get('user.orbit.template.id') == self.template['id']],
                [volume for volume in volumes if volume['name'] == self.target])

    def candidate(self, value):
        config = value.get('config', {})
        if (config.get('user.orbit.compute.owner') != 'orbit-task-sandbox'
                or config.get('user.orbit.compute.id') != self.request['sandbox_id']
                or config.get('user.orbit.template.candidate') != self.template['id']
                or 'user.orbit.compute.template' in config):
            raise Refusal('Candidate ownership does not match.')

    def preflight(self):
        images, volumes = self.outputs()
        if images or volumes:
            raise Refusal('Template output already exists; inspect it before retrying.')
        pair = {self.name + '-' + role for role in self.roles}
        instances = self.query('/1.0/instances?recursion=1')
        selected = [item for item in instances if item['name'] in pair]
        owned = [item for item in instances if item.get('config', {}).get('user.orbit.compute.id') == self.request['sandbox_id']]
        if len(selected) != len(self.roles) or {item['name'] for item in owned} != pair:
            raise Refusal('The disposable candidate inventory is incomplete or foreign.')
        for instance in selected:
            self.candidate(instance)
            devices = instance.get('expanded_devices', instance.get('devices', {}))
            if (instance.get('type') != 'virtual-machine' or instance.get('status') != 'Running'
                    or instance.get('profiles') or set(devices) != {'root', 'worktree', 'eth0'}
                    or devices['root'].get('type') != 'disk' or devices['root'].get('pool') != self.pool
                    or devices['root'].get('path') != '/'
                    or devices['worktree'] != {'type': 'disk', 'pool': self.pool, 'source': self.name + '-worktree', 'path': '/home/orbit/orbit'}
                    or devices['eth0'].get('type') != 'nic' or devices['eth0'].get('network') != self.name):
                raise Refusal('Candidate devices or power do not match.')
        volume = self.query(self.volume_path(self.name + '-worktree'))
        self.candidate(volume)
        used = {urllib.parse.urlparse(url).path for url in volume.get('used_by', [])}
        if (volume.get('content_type') != 'filesystem' or used != {'/1.0/instances/' + name for name in pair}
                or any(urllib.parse.parse_qs(urllib.parse.urlparse(url).query).get('project', ['default']) != [self.project]
                       for url in volume.get('used_by', []))):
            raise Refusal('Candidate source has foreign attachments.')
        return {'source_template': self.template, 'candidate': self.name, 'target': self.target, 'project': self.project, 'pool': self.pool}

    def guest(self, role, script, data=None, user='root'):
        arguments = ['exec', self.name + '-' + role, '--']
        if user != 'root':
            arguments += ['sudo', '-n', '-u', user]
        return json.loads(self.run(*arguments, 'python3', '-I', '-c', script, data=data, timeout=3600))

    def owned_output(self, value, field='config'):
        if any(value.get(field, {}).get(key) != expected for key, expected in self.metadata.items()):
            raise Refusal('Published resource ownership changed; retained for inspection.')

    def cleanup(self):
        images, volumes = self.outputs()
        for image in images:
            self.owned_output(image, 'properties')
            if image.get('public') or image.get('aliases') or image.get('used_by'):
                raise Refusal('Published image is in use; retained for inspection.')
            self.run('image', 'delete', image['fingerprint'])
        for volume in volumes:
            self.owned_output(volume)
            if volume.get('used_by'):
                raise Refusal('Published volume is in use; retained for inspection.')
            for snapshot in self.query(self.volume_path(self.target) + '/snapshots?recursion=1'):
                self.owned_output(snapshot)
            self.run('storage', 'volume', 'delete', self.pool, self.target)
        if any(self.outputs()):
            raise Refusal('Publication cleanup audit failed.')

    def publish(self):
        self.preflight()
        report = {'source_template': self.template, 'images': {}, 'guest_audits': {}}
        program = (self.helpers / 'guest-template-audit.py').read_text()
        with ThreadPoolExecutor(max_workers=len(self.roles)) as workers:
            pending = {role: workers.submit(self.guest, role, program,
                       json.dumps({'role': role}) if role in WORKLOAD_ROLES else None) for role in self.roles}
            for role, result in pending.items():
                audit = result.result()
                if audit.get('ready') is not True or (role in WORKLOAD_ROLES and audit.get('role') != role):
                    raise Refusal('Candidate guest did not pass its audit.')
                report['guest_audits'][role] = audit
        marker_script = """import json,os,pathlib,sys
root=pathlib.Path('/home/orbit/orbit')
assert root.resolve()==root and root.stat().st_uid==os.geteuid()
metadata=root/'.git'
assert metadata.is_dir() and not metadata.is_symlink()
value=json.load(sys.stdin)
path=metadata/'orbit-template-candidate.json'
if path.exists():
    assert not path.is_symlink() and json.loads(path.read_text())==value
else:
    with path.open('x') as output: json.dump(value,output)
print('{}')
"""
        self.guest('operator', marker_script, json.dumps(self.template), 'orbit')
        result = self.guest('operator', (self.helpers / 'guest-template-source.py').read_text(),
                            json.dumps({'checkout': '/home/orbit/orbit', 'source_template': self.template}), 'orbit')
        if result.get('source_template') != self.template or result.get('head') != self.template['commit']:
            raise Refusal('Candidate source preparation failed.')
        health = self.guest('operator', (self.helpers / 'guest-template-health.py').read_text(),
                            json.dumps({'commit': self.template['commit']}), 'orbit')
        if health.get('ready') is not True or health.get('head') != self.template['commit'] or health.get('gateway_version') != self.template['commit']:
            raise Refusal('Candidate native pair is not ready.')
        report['native_health'] = health
        # Revalidate all host identities immediately before changing power.
        self.preflight()
        for role in self.roles:
            self.run('stop', self.name + '-' + role, '--timeout', '60')
        for role in self.roles:
            value = self.query('/1.0/instances/' + self.name + '-' + role)
            self.candidate(value)
            if value.get('status') != 'Stopped':
                raise Refusal('Candidate did not stop.')
        try:
            self.query('/1.0/storage-pools/' + self.pool + '/volumes/custom', 'POST', {
                'name': self.target, 'type': 'custom', 'config': {**self.metadata, 'size': '20GiB'},
                'source': {'type': 'copy', 'name': self.name + '-worktree', 'pool': self.pool, 'project': self.project}})
            self.owned_output(self.query(self.volume_path(self.target)))
            self.run('storage', 'volume', 'snapshot', 'create', self.pool, self.target, 'ready')
            self.owned_output(self.query(self.volume_path(self.target) + '/snapshots/ready'))
            for role in self.roles:
                self.run('publish', self.name + '-' + role, '--compression', 'none',
                         *[key + '=' + value for key, value in {**self.metadata, 'user.orbit.template.role': role}.items()])
                matches = [image for image in self.images() if image.get('properties', {}).get('user.orbit.template.id') == self.template['id']
                           and image.get('properties', {}).get('user.orbit.template.role') == role]
                if len(matches) != 1:
                    raise Refusal('Published image is ambiguous.')
                image = matches[0]
                self.owned_output(image, 'properties')
                if image.get('public') or image.get('type') != 'virtual-machine' or image.get('aliases'):
                    raise Refusal('Published image is not private and immutable.')
                report['images'][role] = image['fingerprint']
            volume = self.query(self.volume_path(self.target))
            self.owned_output(volume)
            if volume.get('used_by'):
                raise Refusal('Published source is attached.')
            report.update(published=True, volume=self.target, snapshot='ready', candidate_power='stopped')
            return report
        except BaseException:
            self.cleanup()
            raise


def main():
    if sys.argv[1:] not in (['--plan'], ['--apply']):
        raise Refusal('Usage: bin/sandbox-template-publish --plan|--apply < request.json')
    raw = sys.stdin.buffer.read(8193)
    if len(raw) > 8192:
        raise Refusal('Publication request is too large.')
    publisher = Publisher(json.loads(raw))
    locked = runpy.run_path(str(publisher.helpers / 'template-lock.py'))['locked']
    with locked({publisher.name, publisher.target}):
        print(json.dumps(publisher.preflight() if sys.argv[1] == '--plan' else publisher.publish()))

if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_template_publication_failed'}))
        sys.exit(1)
