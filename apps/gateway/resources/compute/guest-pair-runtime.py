"""Refresh the branch runtime inside an owned isolated pair. No host credentials are accepted."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import uuid

PROJECTS = ('packages/php-sdk', 'apps/gateway', 'apps/cli', 'apps/e2e', 'apps/docs')


def run(arguments, root, home, timeout=120):
    environment = {key: value for key, value in os.environ.items()
                   if not key.startswith(('GIT_', 'ORBIT_')) and key not in ('GH_TOKEN', 'GITHUB_TOKEN', 'COMPOSER_AUTH')}
    environment.update(HOME=str(home), ORBIT_HOME=str(home / '.orbit'),
                       ORBIT_GATEWAY_CHECKOUT=str(root / 'apps/gateway'), DB_CONNECTION='sqlite',
                       DB_DATABASE=str(home / '.orbit/gateway.sqlite'), GIT_CONFIG_GLOBAL='/dev/null',
                       GIT_CONFIG_NOSYSTEM='1', GIT_TERMINAL_PROMPT='0', COMPOSER_NO_INTERACTION='1')
    return subprocess.run(arguments, cwd=root, env=environment, capture_output=True, text=True,
                          check=True, timeout=timeout).stdout.strip()


def git(arguments, root, home):
    return run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', *arguments], root, home)


def private_gateway_version(root, home):
    config_path = home / '.orbit/config.json'
    if config_path.is_symlink() or not config_path.is_file():
        raise ValueError('The isolated Gateway profile is unavailable')
    configuration = json.loads(config_path.read_text())
    active = configuration.get('active_gateway') if isinstance(configuration, dict) else None
    gateways = configuration.get('gateways') if isinstance(configuration, dict) else None
    if (not isinstance(active, str) or not isinstance(gateways, dict) or set(gateways) != {active}
            or not isinstance(gateways[active], dict) or gateways[active].get('url') != 'https://10.44.0.1'):
        raise ValueError('The operator profile does not name the isolated Gateway')
    status = json.loads(run([str(root / 'apps/cli/orbit'), 'gateway:status', '--json'], root, home, timeout=60))
    if (not isinstance(status, dict) or status.get('status') != 'ok'
            or status.get('url') != 'https://10.44.0.1'
            or not isinstance(status.get('version'), str)
            or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', status['version'])):
        raise ValueError('The isolated Gateway is not serving the branch version')
    return status['version']


def prepare(request, root=Path('/home/orbit/orbit'), home=Path('/home/orbit')):
    if (not isinstance(request, dict) or set(request) - {'head', 'inventory', 'doctor'} != {'sandbox_id', 'branch', 'phase'}
            or request['phase'] not in ('inspect', 'gateway', 'operator', 'version')
            or not isinstance(request['sandbox_id'], str) or str(uuid.UUID(request['sandbox_id'])) != request['sandbox_id']
            or not isinstance(request['branch'], str) or not re.fullmatch(r'task-[1-9][0-9]*', request['branch'])):
        raise ValueError('Invalid pair runtime request')
    if 'doctor' in request and request['doctor'] is not True:
        raise ValueError('Invalid doctor request')
    inventory = request.get('inventory', ['gateway', 'operator'])
    if (not isinstance(inventory, list) or not 2 <= len(inventory) <= 5
            or any(not isinstance(role, str) for role in inventory)
            or len(set(inventory)) != len(inventory) or not {'gateway', 'operator'} <= set(inventory)
            or set(inventory) - {'gateway', 'operator', 'app-dev', 'app-prod', 'app-prod-2'}):
        raise ValueError('Invalid recorded inventory')
    if not root.is_dir() or root.resolve() != root or root.stat().st_uid != os.geteuid():
        raise ValueError('The pair source is unavailable')
    metadata = root / '.git'
    marker = metadata / 'orbit-sandbox-source.json'
    if metadata.is_symlink() or marker.is_symlink() or not marker.is_file() or marker.stat().st_size > 8192:
        raise ValueError('The pair source has no group ownership')
    owner = json.loads(marker.read_text())
    seed = owner.get('source_template') if isinstance(owner, dict) else None
    if (not isinstance(owner, dict) or owner.get('sandbox_id') != request['sandbox_id'] or owner.get('branch') != request['branch']
            or not isinstance(seed, dict) or not isinstance(seed.get('commit'), str)
            or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', seed['commit'])):
        raise ValueError('The pair source belongs to another group')
    head = git(['rev-parse', '--verify', 'HEAD^{commit}'], root, home)
    if git(['symbolic-ref', 'HEAD'], root, home) != 'refs/heads/' + request['branch']:
        raise ValueError('The pair source branch changed')
    if request['phase'] != 'inspect' and request.get('head') != head:
        raise ValueError('The pair source commit changed')
    if request['phase'] == 'gateway':
        database = home / '.orbit/gateway.sqlite'
        if database.is_symlink() or not database.is_file():
            raise ValueError('The isolated Gateway database is unavailable')
        for project in PROJECTS:
            path = root / project
            run(['composer', '--working-dir=' + str(path), 'validate', '--check-lock', '--no-check-publish', '--no-interaction', '--no-plugins', '--no-scripts'], root, home)
            changed = git(['diff', '--name-only', seed['commit'], head, '--', project + '/composer.lock'], root, home)
            operation = ['install', '--prefer-dist'] if changed or not (path / 'vendor/autoload.php').is_file() else ['dump-autoload']
            run(['composer', '--working-dir=' + str(path), *operation, '--no-interaction', '--no-scripts'], root, home)
        gateway = root / 'apps/gateway'
        environment = gateway / '.env'
        if environment.resolve() != environment or not environment.is_file() or environment.stat().st_uid != os.geteuid():
            raise ValueError('The Gateway environment is not local')
        lines = [line for line in environment.read_text().splitlines() if not re.match(r'^\s*(?:export\s+)?APP_VERSION\s*=', line)]
        temporary = None
        try:
            with tempfile.NamedTemporaryFile(mode='w', prefix='.orbit-version-', dir=gateway, delete=False) as output:
                temporary = Path(output.name)
                output.write('\n'.join([*lines, 'APP_VERSION=' + head]) + '\n')
            os.replace(temporary, environment)
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)
        cached = gateway / 'bootstrap/cache/config.php'
        if cached.is_symlink():
            raise ValueError('The Gateway config cache is not local')
        cached.unlink(missing_ok=True)
        for command in ('package:discover', 'optimize:clear', 'migrate'):
            run(['php', str(gateway / 'artisan'), command, '--no-interaction', *(['--force'] if command == 'migrate' else [])], root, home)
        access = (
            "require 'apps/gateway/vendor/autoload.php';"
            "$app = require 'apps/gateway/bootstrap/app.php';"
            "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
            "(new App\\Infrastructure\\Gateway\\GatewayCheckoutAccessConverger("
            "$app->make(App\\Infrastructure\\Processes\\ProcessRunner::class),"
            "getcwd().'/apps/gateway'))->converge();"
        )
        run(['php', '-r', access], root, home)
        run(['sudo', '-n', 'systemctl', 'restart', 'php8.5-fpm'], root, home)
    elif request['phase'] == 'version':
        version = private_gateway_version(root, home)
    elif request['phase'] == 'operator':
        version = private_gateway_version(root, home)
        data = json.loads(run([str(root / 'apps/cli/orbit'), 'node:list', '--json'], root, home, timeout=60))
        nodes = data.get('nodes') if isinstance(data, dict) else None
        if (not isinstance(nodes, list) or len(nodes) != len(inventory) or any(not isinstance(node, dict) for node in nodes)
                or {node.get('name') for node in nodes} != set(inventory)
                or any(node.get('status') != 'active' for node in nodes)):
            raise ValueError('The isolated recorded inventory is not ready')
        for node in nodes:
            roles = node.get('roles')
            required = 'app-prod' if node['name'] == 'app-prod-2' else node['name']
            if node['name'] == 'operator' and roles != []:
                raise ValueError('The operator is not roleless')
            if node['name'] not in ('gateway', 'operator') and (not isinstance(roles, list) or required not in roles):
                raise ValueError('A workload role is not active')
        if version != head:
            raise ValueError('The isolated Gateway is not serving the branch version')
        if request.get('doctor'):
            for node in nodes:
                node_id = node.get('id')
                if type(node_id) is not int or node_id < 1:
                    raise ValueError('Invalid doctor Node identity')
                report = json.loads(run([str(root / 'apps/cli/orbit'), 'doctor', '--node=' + str(node_id), '--family=node', '--family=role', '--family=firewall', '--json'], root, home, timeout=90))
                observations = report.get('nodes') if isinstance(report, dict) else None
                if (not isinstance(report, dict) or report.get('healthy') is not True
                        or not isinstance(observations, list) or len(observations) != 1
                        or not isinstance(observations[0], dict) or observations[0].get('node_id') != node_id
                        or observations[0].get('node_name') != node['name'] or observations[0].get('healthy') is not True):
                    raise ValueError('The recorded Node failed fresh doctor readiness')
                families = observations[0].get('families')
                if (not isinstance(families, list) or len(families) != 3
                        or any(not isinstance(family, dict) or family.get('status') != 'healthy' for family in families)
                        or {family.get('family') for family in families} != {'node', 'role', 'firewall'}):
                    raise ValueError('The doctor readiness families are incomplete')
    if git(['rev-parse', '--verify', 'HEAD^{commit}'], root, home) != head:
        raise ValueError('The pair source changed during preparation')
    return {'sandbox_id': request['sandbox_id'], 'head': head, 'ready': True,
            **({'gateway_head': version, 'gateway_url': 'https://10.44.0.1'} if request['phase'] == 'version' else {}),
            **({'gateway_url': 'https://10.44.0.1', 'doctor_nodes': inventory} if request.get('doctor') else {})}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(4097)
        if len(raw) > 4096:
            raise ValueError('Pair runtime request is too large')
        print(json.dumps(prepare(json.loads(raw))))
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print('Sandbox pair runtime preparation failed.', file=sys.stderr)
        sys.exit(1)
