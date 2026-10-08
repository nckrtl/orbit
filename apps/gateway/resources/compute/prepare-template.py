"""Construct a new disposable cold candidate from pinned offline inputs."""
import base64
import hashlib
import secrets
import ipaddress
import json
from pathlib import Path
import re
import runpy
import subprocess
import sys
import time
import tarfile

HERE = Path(__file__).resolve().parent
publication = runpy.run_path(str(HERE / 'publish-template.py'))
Publisher, Refusal = publication['Publisher'], publication['Refusal']
verify_inputs = runpy.run_path(str(HERE / 'template-inputs.py'))['verify']
workload_roles = publication['workload_roles']
validate_guest = runpy.run_path(str(HERE / 'guest-template-install.py'))['validate']


class Builder(Publisher):
    def __init__(self, request):
        expected = {'project', 'pool', 'sandbox_id', 'budget', 'subnet', 'blocked_networks', 'base_image', 'inputs', 'source_manifest'}
        if not isinstance(request, dict) or set(request) - {'workload_roles'} != expected:
            raise Refusal('Invalid cold candidate request.')
        roles = workload_roles(request)
        if not isinstance(request['inputs'], dict):
            raise Refusal('Invalid offline inputs.')
        validate_guest({'role': 'operator', 'inputs': {**request['inputs'], 'root': '/root/orbit-template-inputs'}, 'source_manifest': request['source_manifest']})
        super().__init__({key: request[key] for key in ('project', 'pool', 'sandbox_id')} | {'source_template': request['source_manifest']['source_template'], **({'workload_roles': roles} if 'workload_roles' in request else {})})
        self.build = request
        if not re.fullmatch(r'orbit-sandbox-proof-[a-z0-9]+', self.project):
            raise Refusal('Invalid sandbox project.')
        if type(request['budget']) is not int or not 2 + len(roles) <= request['budget'] <= 64:
            raise Refusal('Invalid pair budget.')
        if not isinstance(request['base_image'], str) or not re.fullmatch('[a-f0-9]{64}', request['base_image']):
            raise Refusal('Pin the upstream VM fingerprint.')
        subnet = ipaddress.ip_network(request['subnet'], strict=True)
        if subnet.version != 4 or subnet.prefixlen != 24 or not subnet.subnet_of(ipaddress.ip_network('10.233.0.0/16')):
            raise Refusal('Invalid candidate subnet.')
        blocked = request['blocked_networks']
        if not isinstance(blocked, list) or not blocked or any(not isinstance(value, str) or ipaddress.ip_network(value).version != 4 for value in blocked):
            raise Refusal('Invalid network exclusions.')
        self.prepared_digest = hashlib.sha256(json.dumps({key: value for key, value in request.items() if key != 'inputs'} | {
            'inputs': {key: value for key, value in request['inputs'].items() if key != 'root'}}, sort_keys=True).encode()).hexdigest()

    def new_candidate(self):
        verified = verify_inputs(self.build['inputs'])
        project = json.loads(self.run('query', '/1.0/projects/' + self.project))
        config = project.get('config', {})
        if config.get('user.orbit.compute.owner') != 'orbit-task-sandbox' or config.get('features.networks') != 'false':
            raise Refusal('Candidate project ownership does not match.')
        inputs = self.build['inputs']
        files = [*inputs['packages'], inputs['tools'], inputs['source'], inputs['composer']]
        if any(item['file'] in {'template-inputs.py', 'guest-template-source.py', 'guest-template-install.py'} for item in files):
            raise Refusal('Input file conflicts with a preparation helper.')
        if any(self.outputs()):
            raise Refusal('Template output already exists.')
        instances = self.query('/1.0/instances?recursion=1')
        if any(value['name'].startswith(self.name + '-') or value.get('config', {}).get('user.orbit.compute.id') == self.request['sandbox_id'] for value in instances):
            raise Refusal('Candidate resources already exist; inspect before retrying.')
        volumes = self.query('/1.0/storage-pools/' + self.pool + '/volumes/custom?recursion=1')
        if any(value['name'] == self.name + '-worktree' or value.get('config', {}).get('user.orbit.compute.id') == self.request['sandbox_id'] for value in volumes):
            raise Refusal('Candidate source already exists.')
        for kind in ('networks', 'network-acls'):
            values = json.loads(self.run('query', '/1.0/' + kind + '?recursion=1'))
            if any(value['name'] == self.name for value in values):
                raise Refusal('Candidate network already exists.')
        image = self.query('/1.0/images/' + self.build['base_image'])
        if image.get('type') != 'virtual-machine' or image.get('architecture') != 'x86_64':
            raise Refusal('Cold construction requires an x86_64 VM image.')
        return {'candidate': self.name, 'inputs': verified, 'source_template': self.template}

    def own_unmarked(self, value):
        config = value.get('config', {})
        if config.get('user.orbit.compute.owner') != 'orbit-task-sandbox' or config.get('user.orbit.compute.id') != self.request['sandbox_id']:
            raise Refusal('New candidate ownership changed.')
        if 'user.orbit.template.candidate' in config or 'user.orbit.compute.template' in config:
            raise Refusal('New candidate already has template state.')

    def push(self, role, source, name):
        self.run('file', 'push', '--create-dirs', str(source), self.name + '-' + role + '/root/orbit-template-inputs/' + name)

    def prepare(self):
        report = self.new_candidate()
        envelope = {'project': self.project, 'sandbox_id': self.request['sandbox_id'], 'budget': self.build['budget'], 'operation': 'provision',
                    'spec': {'pool': self.pool, 'subnet': self.build['subnet'], 'blocked_networks': self.build['blocked_networks'],
                             'images': dict.fromkeys(self.roles, self.build['base_image'])}}
        helper = HERE.parents[2] / 'agent/resources/incus-sandbox.py'
        result = subprocess.run([sys.executable, str(helper)], input=json.dumps(envelope), capture_output=True, text=True, timeout=1200)
        if result.returncode or json.loads(result.stdout).get('power') != 'running':
            raise Refusal('Candidate provisioning failed; inspect owned resources.')
        for role in self.roles:
            name = self.name + '-' + role
            value = self.query('/1.0/instances/' + name)
            self.own_unmarked(value)
            if value['config'].get('volatile.base_image') != self.build['base_image']:
                raise Refusal('Candidate base image changed.')
        volume = self.query(self.volume_path(self.name + '-worktree'))
        self.own_unmarked(volume)
        for role in self.roles:
            self.run('config', 'set', self.name + '-' + role, 'user.orbit.template.candidate=' + self.template['id'])
        self.run('storage', 'volume', 'set', self.pool, self.name + '-worktree', 'user.orbit.template.candidate=' + self.template['id'])
        self.preflight()
        inputs = self.build['inputs']
        files = [*inputs['packages'], inputs['tools'], inputs['source'], inputs['composer']]
        reserved = {'template-inputs.py', 'guest-template-source.py', 'guest-template-install.py'}
        if any(item['file'] in reserved for item in files):
            raise Refusal('Input file conflicts with a preparation helper.')
        for role in self.roles:
            name = self.name + '-' + role
            for _ in range(120):
                try:
                    self.run('exec', name, '--', 'true', timeout=10)
                    break
                except Refusal:
                    time.sleep(1)
            else:
                raise Refusal('Candidate guest agent did not start.')
            self.run('exec', name, '--', 'bash', '-seu', data='test ! -e /root/orbit-template-inputs\ntest ! -L /root/orbit-template-inputs\ninstall -d -m 0700 /root/orbit-template-inputs\n')
            for item in files:
                self.push(role, Path(inputs['root']) / item['file'], item['file'])
            for filename in sorted(reserved):
                self.push(role, HERE / filename, filename)
            guest_request = {'role': role, 'inputs': {**inputs, 'root': '/root/orbit-template-inputs'}, 'source_manifest': self.build['source_manifest']}
            ready = json.loads(self.run('exec', name, '--', 'python3', '-I', '/root/orbit-template-inputs/guest-template-install.py', data=json.dumps(guest_request), timeout=3600))
            if ready.get('prepared') is not True or ready.get('role') != role or ready.get('source_template') != self.template:
                raise Refusal('Candidate guest preparation failed.')
            template = self.run('config', 'template', 'show', name, 'hosts.tpl')
            entry = '127.0.0.1 telemetry.sury.org # Orbit image: avoid network-dependent FPM startup'
            if 'telemetry.sury.org' in template:
                raise Refusal('Cold image has an unexpected hosts template override.')
            self.run('config', 'template', 'edit', name, 'hosts.tpl', data=template + '\n' + entry + '\n')
        self.preflight()
        for role in self.roles:
            self.run('config', 'set', self.name + '-' + role, 'user.orbit.template.prepared=' + self.prepared_digest)
        self.run('storage', 'volume', 'set', self.pool, self.name + '-worktree', 'user.orbit.template.prepared=' + self.prepared_digest)
        report.update(prepared=True, native_bootstrap=False, prepared_digest=self.prepared_digest)
        return report

    def prepared(self):
        self.preflight()
        resources = [self.query('/1.0/instances/' + self.name + '-' + role) for role in self.roles]
        resources.append(self.query(self.volume_path(self.name + '-worktree')))
        if any(value.get('config', {}).get('user.orbit.template.prepared') != self.prepared_digest for value in resources):
            raise Refusal('Candidate preparation receipt does not match.')

    def source_ready(self):
        result = self.guest('operator', (HERE / 'guest-template-source.py').read_text(),
                            json.dumps({'checkout': '/home/orbit/orbit', 'source_template': self.template}), 'orbit')
        if result.get('head') != self.template['commit'] or result.get('source_template') != self.template:
            raise Refusal('Prepared source changed.')

    def shell(self, role, script):
        return self.run('exec', self.name + '-' + role, '--', 'bash', '-seu', data=script, timeout=1800)

    def converge(self):
        self.prepared()
        self.source_ready()
        for role in self.roles:
            sources = self.guest(role, (HERE / 'guest-template-package-sources.py').read_text())
            if sources.get('sources_https') is not True:
                raise Refusal('Candidate package sources are unsafe.')
        for role in ('operator', 'gateway'):
            self.shell(role, 'systemctl enable --now ssh php8.5-fpm\nsystemctl disable --now dnsmasq\ninstall -d -o orbit -g orbit -m 0700 /home/orbit/.orbit\n')
        for project in ('.', 'apps/cli', 'apps/gateway', 'apps/e2e', 'apps/docs', 'packages/php-sdk'):
            self.run('exec', self.name + '-operator', '--', 'sudo', '-n', '-u', 'orbit', '-H', 'env',
                     'COMPOSER_DISABLE_NETWORK=1', 'composer', '--working-dir=/home/orbit/orbit/' + project,
                     'dump-autoload', '--no-interaction', timeout=600)
        environment_script = """import json,os,pathlib,sys
root=pathlib.Path('/home/orbit/orbit/apps/gateway')
values=json.load(sys.stdin)
path=root/'.env'
if path.is_symlink(): raise ValueError('Environment must be local')
if path.exists():
    lines=path.read_text().splitlines()
    values.pop('APP_KEY')
else:
    lines=(root/'.env.example').read_text().splitlines()
lines=[line for line in lines if line.split('=',1)[0] not in values]
path.write_text(chr(10).join(lines+[key+'='+value for key,value in values.items()])+chr(10))
os.chown(path,1002,1002)
path.chmod(0o600)
cached=root/'bootstrap/cache/config.php'
if cached.is_symlink(): raise ValueError('Config cache must be local')
cached.unlink(missing_ok=True)
print('{}')
"""
        self.guest('gateway', environment_script, json.dumps({
            'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
            'APP_URL': 'https://gateway.orbit', 'APP_VERSION': self.template['commit'],
            'ORBIT_HOME': '/home/orbit/.orbit', 'ORBIT_GATEWAY_CHECKOUT': '/home/orbit/orbit/apps/gateway',
            'DB_CONNECTION': 'sqlite', 'DB_DATABASE': '/home/orbit/.orbit/gateway.sqlite'}))
        subnet = ipaddress.ip_network(self.build['subnet'])
        helpers = '/home/orbit/orbit/apps/e2e/resources/guest/'
        self.run('exec', self.name + '-gateway', '--', 'bash', helpers + 'converge-gateway.sh', 'bootstrap', str(subnet.network_address + 11), timeout=1800)
        agent = (
            "chdir('/home/orbit/orbit');"
            "require 'apps/gateway/vendor/autoload.php';"
            "$app = require 'apps/gateway/bootstrap/app.php';"
            "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
            "$nodes = App\\Models\\Node::query()->whereHas('roles', static fn ($query) => $query"
            "->where('role', 'gateway')->where('status', 'active'))->get();"
            "if ($nodes->count() !== 1 || $nodes[0]->name !== 'gateway' || $nodes[0]->wireguard_ip !== '10.44.0.1'"
            " || $nodes[0]->platform !== 'linux' || $nodes[0]->user !== 'orbit') { throw new RuntimeException('Invalid isolated Gateway'); }"
            "$app->make(App\\Domain\\Nodes\\NodeAgentRuntime::class)->converge($nodes[0]);"
        )
        self.run('exec', self.name + '-gateway', '--', 'sudo', '-n', '-u', 'orbit', '-H', 'env',
                 'ORBIT_HOME=/home/orbit/.orbit', 'ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway',
                 'DB_CONNECTION=sqlite', 'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
                 'php', '-r', agent, timeout=240)
        self.run('exec', self.name + '-gateway', '--', 'systemctl', 'enable', '--now', 'dnsmasq')
        public = self.run('exec', self.name + '-gateway', '--', 'cat', '/home/orbit/.orbit/ssh/id_ed25519.pub').strip().split()
        if len(public) < 2 or public[0] != 'ssh-ed25519' or not re.fullmatch(r'[A-Za-z0-9+/]+={0,2}', public[1]):
            raise Refusal('Invalid isolated Gateway SSH key.')
        self.run('exec', self.name + '-operator', '--', 'bash', helpers + 'prepare-node.sh', 'gateway-authorize', ' '.join(public[:2]))
        self.run('exec', self.name + '-gateway', '--', 'bash', helpers + 'converge-operator.sh', str(subnet.network_address + 10), 'x86_64', '10.44.0.3', timeout=1800)
        self.run('exec', self.name + '-gateway', '--', 'bash', helpers + 'converge-sample-app.sh', 'grant-operator', 'operator', 'gateway')
        self.run('exec', self.name + '-operator', '--', 'bash', helpers + 'converge-sample-app.sh', 'configure-cli', '10.44.0.1')
        self.source_ready()
        self.prepared()
        health = self.guest('operator', (HERE / 'guest-template-health.py').read_text(), json.dumps({'commit': self.template['commit']}), 'orbit')
        for role in workload_roles(self.build):
            self.shell(role, 'systemctl enable --now ssh docker\nsystemctl disable --now dnsmasq\n')
            audit = self.guest(role, (HERE / 'guest-template-audit.py').read_text(), json.dumps({'role': role}))
            if audit.get('ready') is not True or audit.get('role') != role:
                raise Refusal('Blank workload prerequisites are unavailable.')
        if health.get('ready') is not True or health.get('head') != self.template['commit'] or health.get('gateway_version') != self.template['commit']:
            raise Refusal('Native candidate readiness failed.')
        for role in self.roles:
            self.run('config', 'set', self.name + '-' + role, 'user.orbit.template.ready=' + self.prepared_digest)
        self.run('storage', 'volume', 'set', self.pool, self.name + '-worktree', 'user.orbit.template.ready=' + self.prepared_digest)
        return {'converged': True, 'candidate': self.name, 'source_template': self.template, 'native_health': health}


def main():
    if sys.argv[1:] not in (['--plan'], ['--prepare'], ['--converge']):
        raise Refusal('Use --plan, --prepare, or --converge.')
    raw = sys.stdin.buffer.read(1024 * 1024 + 1)
    if len(raw) > 1024 * 1024:
        raise Refusal('Candidate request is too large.')
    builder = Builder(json.loads(raw))
    locked = runpy.run_path(str(HERE / 'template-lock.py'))['locked']
    with locked({builder.name, builder.target}):
        operation = {'--plan': builder.new_candidate, '--prepare': builder.prepare, '--converge': builder.converge}[sys.argv[1]]
        print(json.dumps(operation()))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, TypeError, OSError, EOFError, RecursionError, tarfile.TarError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_template_construction_failed'}))
        sys.exit(1)
