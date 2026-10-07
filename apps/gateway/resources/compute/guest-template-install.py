"""Offline cold-candidate preparation. Invoked only after host ownership checks."""
import grp
import hashlib
import json
import os
from pathlib import Path
import pwd
import runpy
import shutil
import subprocess
import sys
import tarfile

ROOT = Path('/root/orbit-template-inputs')
HOME = Path('/home/orbit')
SOURCE = HOME / 'orbit'
PHP_PROJECTS = ('.', 'apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk')
JS_PROJECTS = ('packages/agent-annotation', 'apps/web', 'apps/pi-server')


def command(*args, cwd=None, timeout=1200):
    subprocess.run(args, cwd=cwd, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                   stderr=subprocess.DEVNULL, check=True, timeout=timeout)


def digest(path):
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def validate(request):
    if not isinstance(request, dict) or set(request) != {'role', 'inputs', 'source_manifest'}:
        raise ValueError('Invalid preparation request')
    if request['role'] not in ('operator', 'gateway') or not isinstance(request['inputs'], dict) or request['inputs'].get('root') != str(ROOT):
        raise ValueError('Invalid guest scope')
    manifest = request['source_manifest']
    if not isinstance(manifest, dict) or set(manifest) != {'source_template', 'ci_run', 'projects', 'sha256'}:
        raise ValueError('Invalid source provenance')
    if type(manifest['ci_run']) is not int or manifest['ci_run'] < 1 or manifest['sha256'] != request['inputs']['source']['sha256']:
        raise ValueError('Invalid pinned source')
    projects = manifest['projects']
    if not isinstance(projects, dict) or set(projects) != set(PHP_PROJECTS + JS_PROJECTS):
        raise ValueError('Incomplete source dependency provenance')
    import re
    for project, values in projects.items():
        expected = {'bun_lock_sha256'} if project in JS_PROJECTS else {'composer_lock_sha256'} | (set() if project == '.' else {'tia_sha256'})
        if not isinstance(values, dict) or set(values) != expected or any(not isinstance(v, str) or not re.fullmatch('[a-f0-9]{64}', v) for v in values.values()):
            raise ValueError('Invalid dependency provenance')
    return request


def identity_available():
    for lookup, value in ((pwd.getpwnam, 'orbit'), (pwd.getpwnam, 'orbit-worker'), (pwd.getpwuid, 1002),
                          (grp.getgrnam, 'orbit'), (grp.getgrgid, 1002)):
        try:
            lookup(value)
        except KeyError:
            continue
        raise ValueError('Cold image already has a managed identity')
    if HOME.resolve() != HOME or SOURCE.resolve() != SOURCE or not SOURCE.is_dir():
        raise ValueError('Expected local source mount')
    if any(path.name != 'orbit' for path in HOME.iterdir()):
        raise ValueError('Cold home contains existing state')


def packages(request, policy=Path('/usr/sbin/policy-rc.d')):
    body = '#!/bin/sh\nexit 101\n'
    with policy.open('x') as output:
        output.write(body)
    policy.chmod(0o755)
    try:
        (ROOT / 'empty-lists').mkdir(mode=0o700)
        environment = dict(os.environ, DEBIAN_FRONTEND='noninteractive')
        subprocess.run(['apt-get', '-o', 'Dir::State::lists=' + str(ROOT / 'empty-lists'),
                        '-o', 'Acquire::Retries=0', '-o', 'Acquire::http::Proxy=http://127.0.0.1:9',
                        '-o', 'Acquire::https::Proxy=http://127.0.0.1:9', '-o', 'Dpkg::Options::=--force-confold',
                        '--no-install-recommends', '--no-remove', '--yes', 'install',
                        *[str(ROOT / item['file']) for item in request['inputs']['packages']]],
                       env=environment, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, check=True, timeout=1200)
        result = subprocess.run(['dpkg', '--audit'], capture_output=True, check=True, timeout=60)
        if result.stdout:
            raise ValueError('Incomplete package installation')
    finally:
        if policy.is_symlink() or policy.read_text() != body:
            raise ValueError('Package service policy changed')
        policy.unlink()


def install(request):
    validate(request)
    if os.geteuid() != 0:
        raise ValueError('Guest preparation requires root')
    identity_available()
    if request['role'] == 'operator' and any(SOURCE.iterdir()):
        raise ValueError('Source volume is not empty')
    verifier = runpy.run_path(str(ROOT / 'template-inputs.py'))['verify']
    verified = verifier(request['inputs'])
    packages(request)
    command('groupadd', '--gid', '1002', 'orbit')
    command('useradd', '--uid', '1002', '--gid', '1002', '--home-dir', str(HOME), '--shell', '/bin/bash', '--no-create-home', 'orbit')
    os.chown(HOME, 1002, 1002)
    HOME.chmod(0o755)
    sudoers = Path('/etc/sudoers.d/orbit')
    with sudoers.open('x') as output:
        output.write('orbit ALL=(ALL) NOPASSWD: ALL\n')
    sudoers.chmod(0o440)
    command('visudo', '-cf', str(sudoers))
    command('sudo', '-n', '-u', 'orbit', 'sudo', '-n', 'true')
    with tarfile.open(ROOT / request['inputs']['tools']['file'], 'r:gz') as archive:
        archive.extractall('/', filter='data')
    shutil.copyfile(ROOT / request['inputs']['composer']['file'], '/usr/local/bin/composer')
    Path('/usr/local/bin/composer').chmod(0o755)
    for name, target in {'node': '/opt/orbit-image/node/bin/node', 'vp': '/opt/orbit-image/vp/bin/vp', 'php': '/usr/bin/php8.5'}.items():
        Path('/usr/local/bin', name).symlink_to(target)
    for binary in ('orbit-agent', 'orbit-pi-server', 'bun'):
        path = Path('/usr/local/bin') / binary
        os.chown(path, 0, 0)
        path.chmod(0o755)
    with Path('/etc/hosts').open('a') as output:
        output.write('\n127.0.0.1 telemetry.sury.org # Orbit image: avoid network-dependent FPM startup\n')
    vp = HOME / '.vite-plus'
    (vp / 'bin').mkdir(parents=True)
    shutil.copytree('/opt/orbit-image/vp', vp / '0.3.0', symlinks=True)
    shutil.copytree('/opt/orbit-image/node', vp / 'js_runtime/node/24.21.0', symlinks=True)
    bun = vp / 'package_manager/bun/1.4.2/bun/bin/bun'
    bun.parent.mkdir(parents=True)
    shutil.copy2('/usr/local/bin/bun', bun)
    (vp / 'bin/vp').symlink_to('../0.3.0/bin/vp')
    command('chown', '-R', 'orbit:orbit', str(vp))
    for args in (('default', '24.21.0'), ('setup',), ('on',)):
        command('sudo', '-n', '-u', 'orbit', '-H', 'env', 'VP_NO_UPDATE_CHECK=1', str(vp / 'bin/vp'), 'env', *args, cwd=HOME)
    if request['role'] == 'operator':
        with tarfile.open(ROOT / request['inputs']['source']['file'], 'r:gz') as archive:
            archive.extractall(SOURCE, filter='data')
        command('chown', '-R', 'orbit:orbit', str(SOURCE))
    manifest = request['source_manifest']
    for project, values in manifest['projects'].items():
        for key, filename in (('composer_lock_sha256', 'composer.lock'), ('bun_lock_sha256', 'bun.lock'), ('tia_sha256', '.orbit-tia/graph.json')):
            if key in values and digest(SOURCE / project / filename) != values[key]:
                raise ValueError('Source dependency provenance mismatch')
    prepared = subprocess.run(['sudo', '-n', '-u', 'orbit', 'python3', '-I', '-c', (ROOT / 'guest-template-source.py').read_text()],
                              input=json.dumps({'checkout': str(SOURCE), 'source_template': manifest['source_template']}),
                              text=True, capture_output=True, check=True, timeout=180)
    if json.loads(prepared.stdout).get('head') != manifest['source_template']['commit']:
        raise ValueError('Prepared source commit mismatch')
    command('php', '-r', 'exit(extension_loaded("gd") && extension_loaded("pcov") && in_array("sqlite",PDO::getAvailableDrivers(),true) ? 0 : 1);')
    return {'prepared': True, 'role': request['role'], 'packages': verified['packages'], 'ci_run': manifest['ci_run'], 'source_template': manifest['source_template']}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(1024 * 1024 + 1)
        if len(raw) > 1024 * 1024:
            raise ValueError('Request too large')
        print(json.dumps(install(json.loads(raw))))
    except (ValueError, KeyError, TypeError, OSError, EOFError, tarfile.TarError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_template_guest_preparation_failed'}))
        sys.exit(1)
