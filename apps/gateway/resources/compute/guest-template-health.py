"""Check the isolated native pair before a candidate can become an image."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys


def check(request, root=Path('/home/orbit/orbit'), home=Path('/home/orbit')):
    if (not isinstance(request, dict) or set(request) != {'commit'} or not isinstance(request['commit'], str)
            or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', request['commit'])):
        raise ValueError('Invalid health request')
    if root.resolve() != root or root.stat().st_uid != os.geteuid() or (root / '.git').is_symlink():
        raise ValueError('Candidate source is not local')
    profile = home / '.orbit/config.json'
    if profile.resolve() != profile or not profile.is_file() or profile.stat().st_size > 65536:
        raise ValueError('Missing private Gateway profile')
    configuration = json.loads(profile.read_text())
    if not isinstance(configuration, dict):
        raise ValueError('Invalid private Gateway profile')
    active, gateways = configuration.get('active_gateway'), configuration.get('gateways')
    if (not isinstance(active, str) or not isinstance(gateways, dict) or set(gateways) != {active}
            or not isinstance(gateways[active], dict) or gateways[active].get('url') != 'https://10.44.0.1'):
        raise ValueError('Profile does not name the isolated Gateway')
    environment = {key: value for key, value in os.environ.items() if not key.startswith(('GIT_', 'ORBIT_'))
                   and key not in ('GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN', 'COMPOSER_AUTH')}
    environment.update(HOME=str(home), ORBIT_HOME=str(home / '.orbit'), GIT_CONFIG_GLOBAL='/dev/null',
                       GIT_CONFIG_NOSYSTEM='1', GIT_TERMINAL_PROMPT='0')
    def run(*args):
        return subprocess.run(args, cwd=root, env=environment, capture_output=True, text=True, check=True, timeout=90).stdout.strip()
    head = run('git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', 'rev-parse', '--verify', 'HEAD^{commit}')
    if head != request['commit']:
        raise ValueError('Candidate source commit changed')
    value = json.loads(run(str(root / 'apps/cli/orbit'), 'node:list', '--json'))
    nodes = value.get('nodes') if isinstance(value, dict) else None
    if not isinstance(nodes, list) or len(nodes) != 2 or any(not isinstance(node, dict) for node in nodes):
        raise ValueError('Pair inventory is incomplete')
    by_name = {node.get('name'): node for node in nodes}
    if set(by_name) != {'gateway', 'operator'} or any(node.get('status') != 'active' for node in nodes):
        raise ValueError('Pair is not active')
    if (by_name['gateway'].get('wireguard_ip') != '10.44.0.1'
            or by_name['operator'].get('wireguard_ip') != '10.44.0.3' or by_name['operator'].get('roles') != []):
        raise ValueError('Operator is not isolated and roleless')
    status = json.loads(run(str(root / 'apps/cli/orbit'), 'gateway:status', '--json'))
    if not isinstance(status, dict) or status.get('status') != 'ok' or status.get('url') != 'https://10.44.0.1' or status.get('version') != head:
        raise ValueError('Private Gateway did not answer')
    return {'ready': True, 'head': head, 'gateway_version': status.get('version'), 'nodes': ['gateway', 'operator']}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(4097)
        if len(raw) > 4096:
            raise ValueError('Request too large')
        print(json.dumps(check(json.loads(raw))))
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_template_native_health_failed'}))
        sys.exit(1)
