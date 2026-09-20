"""Fixed node operation for Orbit's FPM exporter and service scrape firewall rules."""
import base64
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT = Path('/etc/orbit/service-metrics')
UNIT = Path('/etc/systemd/system/orbit-fpm-exporter.service')
CONFIG = ROOT / 'fpm.yml'
BINARY = Path('/usr/local/bin/orbit-fpm-exporter')
MARKER = '# Managed by Orbit: service-metrics'
SERVICE = 'orbit-fpm-exporter'
PORTS = {'caddy': '9103', 'fpm': '9114'}
CHECKSUMS = {
    'x86_64': '570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98',
    'aarch64': 'd9773dfef3f6efbbbea0fe8be40ea79652d71ea24b20bfe89690ab026e205442',
}


def run(args, check=True):
    return subprocess.run(args, text=True, capture_output=True, check=check, timeout=45)


def owned(path):
    if path.is_symlink():
        raise ValueError('symlink at owned path')
    if not path.exists():
        return None
    st = path.stat()
    if not path.is_file() or st.st_uid != 0 or st.st_mode & 0o022:
        raise ValueError('file ownership mismatch')
    content = path.read_text()
    if not content.startswith(MARKER + '\n'):
        raise ValueError('missing ownership marker')
    return content


def write(path, content, mode=0o644):
    fd, candidate = tempfile.mkstemp(prefix='.orbit-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(candidate, mode)
        os.replace(candidate, path)
    finally:
        if os.path.exists(candidate):
            os.unlink(candidate)


def rules():
    if not run(['ufw', 'status']).stdout.startswith('Status: active'):
        raise ValueError('firewall is inactive')
    result = []
    seen = set()
    for line in run(['ufw', 'show', 'added']).stdout.splitlines():
        if 'orbit:metrics-service-' not in line:
            continue
        args = shlex.split(line)
        if args[:1] != ['ufw']:
            raise ValueError('unknown firewall record')
        args = args[1:]
        if len(args) >= 2 and args[0] == 'insert':
            args = args[2:]
        # UFW stores these rules in the same syntax returned by show added.
        if 'comment' not in args:
            raise ValueError('missing firewall marker')
        comment = args[-1]
        matches = [(kind, action) for kind in PORTS for action in ['allow', 'deny']
                   if comment == 'orbit:metrics-service-' + kind + '-' + action]
        if len(matches) != 1 or comment in seen:
            raise ValueError('ambiguous firewall ownership')
        kind, action = matches[0]
        if args[0] != action or args[1:4] != ['in', 'on', 'orbit']:
            raise ValueError('unexpected firewall scope')
        # Validate semantic tokens rather than adopting a rule by comment alone.
        parsed = {}
        tail = args[4:]
        if len(tail) % 2:
            raise ValueError('invalid firewall rule')
        for i in range(0, len(tail), 2):
            key, value = tail[i:i + 2]
            if key in parsed:
                raise ValueError('duplicate firewall field')
            parsed[key] = value
        if action == 'deny' and 'from' not in parsed:
            parsed['from'] = 'any'
        if set(parsed) != {'proto', 'from', 'to', 'port', 'comment'}:
            raise ValueError('unexpected firewall fields')
        if parsed['proto'] != 'tcp' or parsed['port'] != PORTS[kind]:
            raise ValueError('unexpected firewall port')
        ipaddress.IPv4Address(parsed['to'])
        if action == 'allow':
            ipaddress.IPv4Address(parsed['from'])
        elif parsed['from'] != 'any':
            raise ValueError('unexpected isolation source')
        seen.add(comment)
        result.append([action, 'in', 'on', 'orbit', 'proto', 'tcp',
                       'from', parsed['from'], 'to', parsed['to'], 'port', parsed['port'],
                       'comment', comment])
    return result


def inspect():
    return {
        'config': owned(CONFIG), 'unit': owned(UNIT), 'rules': rules(),
        'active': run(['systemctl', 'is-active', '--quiet', SERVICE], False).returncode == 0,
        'enabled': run(['systemctl', 'is-enabled', '--quiet', SERVICE], False).returncode == 0,
        'binary': binary_present(),
    }


def binary_present():
    if BINARY.is_symlink():
        raise ValueError('exporter binary is a symlink')
    if not BINARY.exists():
        return False
    stat = BINARY.stat()
    if not BINARY.is_file() or stat.st_uid != 0 or stat.st_mode & 0o022:
        raise ValueError('exporter binary ownership mismatch')
    if hashlib.sha256(BINARY.read_bytes()).hexdigest() != CHECKSUMS[os.uname().machine]:
        raise ValueError('exporter binary checksum mismatch')
    return True


def set_rules(desired):
    current = rules()
    lines = [shlex.split(line) for line in run(['ufw', 'show', 'added']).stdout.splitlines() if line.startswith('ufw ')]
    leading = [line[-1] for line in lines[:len(desired)]]
    if current == desired and leading == [rule[-1] for rule in desired]:
        return
    for rule in reversed(current):
        run(['ufw', '--force', 'delete'] + rule)
    # Every service pair precedes broad member allow rules. Insert in reverse order.
    for rule in reversed(desired):
        run(['ufw', 'insert', '1'] + rule)
    if rules() != desired:
        raise ValueError('firewall verification failed')


def install_binary():
    arch = os.uname().machine
    expected = CHECKSUMS[arch]
    if BINARY.is_symlink():
        raise ValueError('exporter binary is a symlink')
    if BINARY.exists():
        if BINARY.stat().st_uid != 0 or BINARY.stat().st_mode & 0o022:
            raise ValueError('exporter binary ownership mismatch')
        if hashlib.sha256(BINARY.read_bytes()).hexdigest() == expected:
            return
        raise ValueError('existing exporter binary checksum mismatch')
    machine = {'x86_64': 'amd64', 'aarch64': 'arm64'}[arch]
    url = 'https://github.com/cboxdk/fpm-exporter/releases/download/v3.1.1/fpm-exporter-linux-' + machine
    with urllib.request.urlopen(url, timeout=30) as response:
        content = response.read(64 * 1024 * 1024)
    if hashlib.sha256(content).hexdigest() != expected:
        raise ValueError('download checksum mismatch')
    fd, candidate = tempfile.mkstemp(prefix='.orbit-fpm-', dir=BINARY.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
        os.chmod(candidate, 0o755)
        os.replace(candidate, BINARY)
    finally:
        if os.path.exists(candidate):
            os.unlink(candidate)


def ready(config):
    address = re.search(r"^  listen_addr: ['\"]?([0-9.]+:9114)['\"]?$", config, re.M)
    if address is None:
        raise ValueError('invalid exporter listener')
    client = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    for attempt in range(5):
        try:
            with client.open('http://' + address[1] + '/metrics', timeout=12) as response:
                if response.status != 200 or not response.read(64).startswith(b'# HELP'):
                    raise ValueError('invalid exporter response')
            run(['systemctl', 'is-active', '--quiet', SERVICE])
            return
        except Exception:
            if attempt == 4:
                raise
            time.sleep(0.2)


def apply(state):
    before = inspect()
    config, unit = state['config'], state['unit']
    if (config is None) != (unit is None):
        raise ValueError('incomplete exporter configuration')
    if config is not None and not state['binary']:
        raise ValueError('missing exporter binary')
    if state['binary']:
        install_binary()
    if config is not None:
        if not config.startswith(MARKER + '\n') or not unit.startswith(MARKER + '\n'):
            raise ValueError('invalid candidate marker')
        write(CONFIG, config, 0o600)
        write(UNIT, unit)
        run(['systemctl', 'daemon-reload'])
        set_rules(state['rules'])
        if state['enabled']:
            run(['systemctl', 'enable', SERVICE])
        else:
            run(['systemctl', 'disable', SERVICE])
        if state['active']:
            if before['config'] != config or before['unit'] != unit or not before['active']:
                run(['systemctl', 'restart', SERVICE])
            run(['systemctl', 'is-active', '--quiet', SERVICE])
            ready(config)
        else:
            run(['systemctl', 'stop', SERVICE])
    else:
        if before['unit'] is not None:
            run(['systemctl', 'disable', '--now', SERVICE])
            UNIT.unlink()
            run(['systemctl', 'daemon-reload'])
        if before['config'] is not None:
            CONFIG.unlink()
        set_rules(state['rules'])
    if not state['binary'] and binary_present():
        BINARY.unlink()
    if inspect() != state:
        raise ValueError('exporter state verification failed')


def main():
    request = json.loads(base64.b64decode(sys.argv[1]))
    lock_dir = Path('/run/lock/orbit')
    if lock_dir.is_symlink():
        raise ValueError('lock directory is a symlink')
    lock_dir.mkdir(mode=0o700, exist_ok=True)
    if lock_dir.stat().st_uid != 0 or lock_dir.stat().st_mode & 0o077:
        raise ValueError('lock directory ownership mismatch')
    fd = os.open(lock_dir / 'service-metrics.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        for parent in [ROOT.parent, *ROOT.parent.parents, UNIT.parent, BINARY.parent]:
            if parent.exists() and (parent.is_symlink() or parent.stat().st_uid != 0 or parent.stat().st_mode & 0o022):
                raise ValueError('unsafe parent directory')
        if ROOT.is_symlink():
            raise ValueError('configuration directory is a symlink')
        if ROOT.exists():
            if ROOT.stat().st_uid != 0 or ROOT.stat().st_mode & 0o077:
                raise ValueError('configuration directory ownership mismatch')
            if owned(ROOT / '.orbit-owner') != MARKER + '\n':
                raise ValueError('unowned configuration directory')
        elif request['operation'] != 'snapshot':
            ROOT.mkdir(mode=0o700, parents=True)
            write(ROOT / '.orbit-owner', MARKER + '\n', 0o600)
        journal = ROOT / 'transaction.json'
        if journal.exists():
            if journal.is_symlink() or journal.stat().st_uid != 0 or journal.stat().st_mode & 0o077:
                raise ValueError('unowned recovery journal')
            apply(json.loads(journal.read_text()))
            journal.unlink()
        before = inspect()
        if before['unit'] is None and (before['active'] or before['enabled']):
            raise ValueError('unowned exporter service')
        if request['operation'] == 'snapshot':
            print(json.dumps(before))
            return
        write(journal, json.dumps(before), 0o600)
        try:
            apply(request['state'])
        except Exception:
            apply(before)
            journal.unlink()
            raise
        journal.unlink()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Service metrics operation failed: ' + type(error).__name__, file=sys.stderr)
        sys.exit(1)
