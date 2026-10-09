"""Publish a new Project image from one audited, immutable blank workload image."""
import hashlib
import json
from pathlib import Path
import re
import runpy
import sys
import time

HERE = Path(__file__).resolve().parent
publication = runpy.run_path(str(HERE / 'publish-template.py'))
Publisher, Refusal = publication['Publisher'], publication['Refusal']


class ProjectImage(Publisher):
    def __init__(self, request):
        keys = {'project', 'pool', 'sandbox_id', 'budget', 'project_slug', 'base_image', 'source_template'}
        if not isinstance(request, dict) or set(request) != keys:
            raise Refusal('Invalid Project image request.')
        super().__init__({key: request[key] for key in ('project', 'pool', 'sandbox_id', 'source_template')})
        if not re.fullmatch(r'orbit-sandbox-proof-[a-z0-9]+', self.project):
            raise Refusal('Use an owned proof project.')
        if (not isinstance(request['project_slug'], str) or not re.fullmatch(r'[a-z][a-z0-9-]{0,62}', request['project_slug'])
                or request['project_slug'] == 'orbit' or type(request['budget']) is not int or not 1 <= request['budget'] <= 64
                or not isinstance(request['base_image'], str) or not re.fullmatch('[a-f0-9]{64}', request['base_image'])):
            raise Refusal('Invalid Project image placement.')
        self.build = request
        self.name = 'ot-project-image-' + hashlib.sha256(request['sandbox_id'].encode()).hexdigest()[:10]
        self.target = self.name
        self.lock_identity = 'ot-' + hashlib.sha256(request['sandbox_id'].encode()).hexdigest()[:10]
        self.owner = {'user.orbit.project.builder': request['sandbox_id'], 'user.orbit.project.slug': request['project_slug'],
                      'user.orbit.project.base': request['base_image']}
        self.properties = {'user.orbit.project.owner': 'orbit-task-project-image', 'user.orbit.project.slug': request['project_slug'],
                           'user.orbit.project.account': 'orbit', 'user.orbit.project.bootstrap': 'unenrolled',
                           'user.orbit.project.builder': request['sandbox_id'], 'user.orbit.project.base': request['base_image']}

    def base(self):
        project = json.loads(self.run('query', '/1.0/projects/' + self.project))
        if (project.get('config', {}).get('user.orbit.compute.owner') != 'orbit-task-sandbox'
                or project.get('config', {}).get('features.networks') != 'false'):
            raise Refusal('Project image scope is not owned.')
        image = self.query('/1.0/images/' + self.build['base_image'])
        expected = {**self.metadata, 'user.orbit.template.role': 'app-dev'}
        if (image.get('type') != 'virtual-machine' or image.get('architecture') != 'x86_64'
                or image.get('public') is not False or image.get('aliases')
                or any(image.get('properties', {}).get(key) != value for key, value in expected.items())
                or {key for key in image.get('properties', {}) if key.startswith('user.orbit.template.')} != set(expected)):
            raise Refusal('Project base must be the pinned blank workload image.')
        return image

    def candidates(self):
        return [value for value in self.query('/1.0/instances?recursion=1')
                if value.get('name') == self.name or value.get('config', {}).get('user.orbit.project.builder') == self.build['sandbox_id']]

    def outputs(self):
        return [image for image in self.images() if image.get('properties', {}).get('user.orbit.project.builder') == self.build['sandbox_id']]

    def candidate(self, value):
        devices = value.get('expanded_devices', {})
        root = devices.get('root', {})
        if (value.get('name') != self.name or value.get('type') != 'virtual-machine' or value.get('profiles') != []
                or value.get('config', {}).get('volatile.base_image') != self.build['base_image']
                or any(value.get('config', {}).get(key) != item for key, item in self.owner.items())
                or set(devices) != {'root'} or root.get('type') != 'disk' or root.get('path') != '/'
                or root.get('pool') != self.pool or root.get('size') != '20GiB'):
            raise Refusal('Project image candidate ownership changed.')

    def plan(self):
        self.base()
        if self.candidates() or self.outputs():
            raise Refusal('Project image resources already exist; inspect before retrying.')
        count = sum(value.get('status') != 'Stopped' for value in self.query('/1.0/instances?recursion=1'))
        if count >= self.build['budget']:
            raise Refusal('Project image VM budget is full.')
        return {'candidate': self.name, 'project_slug': self.build['project_slug'], 'base_image': self.build['base_image'],
                'disk': '20GiB', 'network': False}

    def audit(self, prepare=False):
        values = self.candidates()
        if len(values) != 1:
            raise Refusal('Project image candidate is missing or ambiguous.')
        self.candidate(values[0])
        if values[0].get('status') != 'Running':
            raise Refusal('Project image audit needs its running candidate.')
        script = (HERE / 'guest-template-audit.py').read_text()
        script += """
if __name__ != '__main__':
    account = pwd.getpwnam('orbit')
    checkout = Path('/home/orbit/orbit')
    if account.pw_dir != '/home/orbit' or checkout.is_symlink() or (checkout.exists() and (not checkout.is_dir() or any(checkout.iterdir()))):
        raise ValueError('Project image checkout is not empty')
    inherited_pi = None
    if PREPARE_PROJECT_IMAGE:
        audit(role='app-dev')
        inherited_pi = remove_project_pi()
    report = audit(role='app-dev', project_image=True)
    if inherited_pi is not None:
        report['inherited_pi_removed'] = inherited_pi
    print(json.dumps(report))
"""
        command = "__name__ = 'orbit_project_image_audit'\nPREPARE_PROJECT_IMAGE = " + repr(prepare) + '\n' + script
        report = json.loads(self.run('exec', self.name, '--', 'python3', '-I', '-c', command, timeout=600))
        if report.get('ready') is not True or report.get('role') != 'app-dev':
            raise Refusal('Project image prerequisites are unavailable.')
        return report

    def prepare(self):
        budget_lock = runpy.run_path(str(HERE.parents[2] / 'agent/resources/incus-sandbox.py'))['sandbox_lock']
        with budget_lock('/run/lock/orbit-task-sandboxes.lock'):
            report = self.plan()
            self.query('/1.0/instances', 'POST', {'name': self.name, 'type': 'virtual-machine', 'profiles': [],
                       'config': {**self.owner, 'security.secureboot': 'false'},
                       'devices': {'root': {'type': 'disk', 'pool': self.pool, 'path': '/', 'size': '20GiB'}},
                       'source': {'type': 'image', 'fingerprint': self.build['base_image']}})
            self.candidate(self.query('/1.0/instances/' + self.name))
        self.run('start', self.name)
        for attempt in range(120):
            try:
                self.run('exec', self.name, '--', 'true', timeout=10)
                break
            except Refusal:
                time.sleep(1)
        else:
            raise Refusal('Project image guest agent is unavailable.')
        report.update(prepared=True, audit=self.audit(prepare=True))
        return report

    def output(self, image, inherited=False):
        properties = image.get('properties', {})
        if (image.get('type') != 'virtual-machine' or image.get('architecture') != 'x86_64' or image.get('public') is not False
                or image.get('aliases') or any(properties.get(key) != value for key, value in self.properties.items())
                or not isinstance(image.get('fingerprint'), str) or not re.fullmatch('[a-f0-9]{64}', image['fingerprint'])
                or image['fingerprint'] == self.build['base_image']
                or (not inherited and any(key.startswith('user.orbit.template.') for key in properties))):
            raise Refusal('Published Project image provenance changed.')
        return image['fingerprint']

    def remove_output_template_provenance(self, image):
        fingerprint = self.output(image, inherited=True)
        inherited = {key: item for key, item in image.get('properties', {}).items() if key.startswith('user.orbit.template.')}
        expected = {**self.metadata, 'user.orbit.template.role': 'app-dev'}
        if any(expected.get(key) != item for key, item in inherited.items()):
            raise Refusal('Published Project image inherited foreign template provenance.')
        for key in sorted(inherited):
            self.run('image', 'unset-property', fingerprint, key)
        return self.output(self.query('/1.0/images/' + fingerprint))

    def remove_template_provenance(self):
        value = self.query('/1.0/instances/' + self.name)
        self.candidate(value)
        inherited = {key: item for key, item in value.get('config', {}).items() if key.startswith('image.user.orbit.template.')}
        expected = {'image.' + key: item for key, item in {**self.metadata, 'user.orbit.template.role': 'app-dev'}.items()}
        if any(expected.get(key) != item for key, item in inherited.items()):
            raise Refusal('Project candidate inherited foreign template provenance.')
        for key in sorted(inherited):
            self.run('config', 'unset', self.name, key)
        value = self.query('/1.0/instances/' + self.name)
        self.candidate(value)
        if any(key.startswith('image.user.orbit.template.') for key in value.get('config', {})):
            raise Refusal('Project candidate template provenance could not be removed.')

    def publish(self):
        self.base()
        if self.outputs():
            raise Refusal('Project image output already exists; inspect before retrying.')
        audit = self.audit()
        self.remove_template_provenance()
        self.run('stop', self.name, '--timeout', '60')
        value = self.query('/1.0/instances/' + self.name)
        self.candidate(value)
        if value.get('status') != 'Stopped':
            raise Refusal('Project image candidate did not stop.')
        self.run('publish', self.name, '--compression', 'none', *[key + '=' + item for key, item in self.properties.items()])
        images = self.outputs()
        if len(images) != 1:
            raise Refusal('Project image publication is ambiguous; retain its resources.')
        return {'published': True, 'image': self.remove_output_template_provenance(images[0]),
                'project_slug': self.build['project_slug'], 'audit': audit}

    def destroy(self):
        values = self.candidates()
        if not values:
            return {'destroyed': True, 'candidate': self.name}
        if len(values) != 1:
            raise Refusal('Project image candidate is ambiguous.')
        self.candidate(values[0])
        self.run('delete', self.name, '--force')
        if self.candidates():
            raise Refusal('Project image candidate cleanup is unresolved.')
        return {'destroyed': True, 'candidate': self.name}


def main():
    if sys.argv[1:] not in (['--plan'], ['--prepare'], ['--publish'], ['--destroy']):
        raise Refusal('Use --plan, --prepare, --publish, or --destroy.')
    raw = sys.stdin.buffer.read(16385)
    if len(raw) > 16384:
        raise Refusal('Project image request is too large.')
    image = ProjectImage(json.loads(raw))
    locked = runpy.run_path(str(HERE / 'template-lock.py'))['locked']
    with locked({image.lock_identity}):
        operation = {'--plan': image.plan, '--prepare': image.prepare, '--publish': image.publish, '--destroy': image.destroy}[sys.argv[1]]
        print(json.dumps(operation()))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print(json.dumps({'error': 'project_image_operation_failed'}))
        sys.exit(1)
