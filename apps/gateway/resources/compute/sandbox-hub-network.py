"""Gateway-owned WireGuard sandbox policy. No packages or shared rules are changed."""
import fcntl
import ipaddress
import json
import os
import pathlib
import shutil
import stat
import subprocess
import sys
import uuid

ROOT = pathlib.Path('/etc/orbit/task-networks')
UNITS = pathlib.Path('/etc/systemd/system')
LOCK = pathlib.Path('/run/lock/orbit-sandbox-network.lock')


def require(condition):
    if not condition:
        raise ValueError('sandbox network ownership or prerequisites unavailable')


def validate(request):
    require(isinstance(request, dict) and set(request) == {
        'operation', 'sandbox_id', 'address', 'hub', 'gateway', 'router', 'model', 'model_port'})
    require(request['operation'] in ('ensure', 'remove'))
    identity = request['sandbox_id']
    require(isinstance(identity, str) and str(uuid.UUID(identity)) == identity)
    for key in ('address', 'hub', 'gateway', 'router', 'model'):
        value = request[key]
        require(isinstance(value, str) and str(ipaddress.IPv4Address(value)) == value
                and ipaddress.IPv4Address(value) in ipaddress.IPv4Network('10.44.0.0/16'))
    require(request['address'] not in {request[k] for k in ('hub', 'gateway', 'router', 'model')})
    require(type(request['model_port']) is int and 1 <= request['model_port'] <= 65535)
    return {k: v for k, v in request.items() if k != 'operation'}


def table_name(spec):
    return 'orbit_sb_' + spec['sandbox_id'].replace('-', '')


def render(spec):
    address, gateway, router, hub, model = (spec[k] for k in ('address', 'gateway', 'router', 'hub', 'model'))
    peers = ', '.join(sorted({gateway, router, hub, model}))
    clients = ', '.join(sorted({gateway, router}))
    outbound = [
        f'ip saddr {address} ip daddr {{ {clients} }} ct state established ct direction reply accept',
        f'ip saddr {address} ip daddr {gateway} tcp dport 443 accept',
        f'ip saddr {address} ip daddr {router} tcp dport {{ 80, 443 }} accept',
        f'ip saddr {address} ip daddr {model} tcp dport {spec["model_port"]} accept',
        f'ip saddr {address} ip daddr {hub} udp dport 53 accept',
        f'ip saddr {address} ip daddr {hub} tcp dport 53 accept',
        f'ip saddr {address} drop',
    ]
    inbound = [
        f'ip daddr {address} ip saddr {{ {peers} }} ct state established ct direction reply accept',
        f'ip daddr {address} ip saddr {gateway} tcp dport {{ 22, 3774 }} accept',
        f'ip daddr {address} ip saddr {router} tcp dport {{ 80, 443, 5173 }} accept',
        f'ip daddr {address} drop',
    ]
    rows = [f'table inet {table_name(spec)} {{', f'comment "orbit-sandbox:{spec["sandbox_id"]}"']
    for hook, rules in [('forward', outbound + inbound), ('input', outbound), ('output', inbound)]:
        rows += [f'chain {hook} {{', f'type filter hook {hook} priority -150; policy accept;']
        rows += [rule + ';' for rule in rules]
        rows += ['}']
    return '\n'.join(rows + ['}', ''])


def run(arguments, data=None):
    result = subprocess.run(arguments, input=data, capture_output=True, text=True, timeout=30)
    require(result.returncode == 0 and len(result.stdout) <= 262144)
    return result.stdout


def canonical(data):
    if isinstance(data, list):
        return [canonical(item) for item in data if not (isinstance(item, dict) and 'metainfo' in item)]
    if isinstance(data, dict):
        return {key: canonical(value) for key, value in data.items() if key not in ('handle', 'packets', 'bytes')}
    return data


def observed(name):
    tables = json.loads(run(['nft', '-j', 'list', 'tables']))['nftables']
    present = any(row.get('table', {}).get('family') == 'inet' and row.get('table', {}).get('name') == name for row in tables)
    return canonical(json.loads(run(['nft', '-j', 'list', 'table', 'inet', name]))) if present else None


def expected(name, text):
    # Compile through the same installed nft binary in a private network namespace.
    # This yields its real canonical expressions without touching the live hub.
    program = """import subprocess, sys
r = subprocess.run(['nft', '-f', '-'], input=sys.stdin.read(), text=True, capture_output=True, timeout=15)
if r.returncode: sys.exit(1)
r = subprocess.run(['nft', '-j', 'list', 'table', 'inet', sys.argv[1]], text=True, capture_output=True, timeout=15)
if r.returncode: sys.exit(1)
print(r.stdout)
"""
    return canonical(json.loads(run(['unshare', '--net', 'python3', '-I', '-c', program, name], text)))


def directory(path):
    if not path.exists():
        path.mkdir(mode=0o700)
    for parent in [path, *path.parents]:
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022)


def read(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o600
                and info.st_nlink == 1 and info.st_size <= 131072)
        with os.fdopen(fd, 'rb', closefd=False) as source:
            return source.read()
    finally:
        os.close(fd)


def put(path, data):
    if path.exists() or path.is_symlink():
        require(read(path) == data)
        return
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'wb', closefd=False) as target:
            target.write(data)
            target.flush()
            os.fsync(fd)
    finally:
        os.close(fd)


def source():
    if '-c' in sys.orig_argv:
        return sys.orig_argv[sys.orig_argv.index('-c') + 1].encode()
    return pathlib.Path(__file__).read_bytes()


def apply(spec, operation, boot=False):
    identity, name = spec['sandbox_id'], table_name(spec)
    manifest = ROOT / (identity + '.json')
    script = ROOT / (identity + '.py')
    rules = ROOT / (identity + '.nft')
    unit_name = 'orbit-sandbox-network-' + identity + '.service'
    unit = UNITS / unit_name
    dependency_dir = UNITS / 'wg-quick@orbit.service.d'
    dependency = dependency_dir / ('orbit-sandbox-' + identity + '.conf')
    directory(ROOT.parent)
    directory(ROOT)
    directory(UNITS)
    directory(dependency_dir)
    require(not (UNITS / (unit_name + '.d')).exists()
            and not (pathlib.Path('/run/systemd/system') / unit_name).exists())
    owned = manifest.exists() or manifest.is_symlink()
    text = render(spec)
    desired = expected(name, text)
    current = observed(name)
    require(current is None or (owned and current == desired))
    files = {
        manifest: json.dumps(spec, sort_keys=True).encode(), script: source(), rules: text.encode(),
        unit: (f'[Unit]\nDescription=Orbit sandbox network {identity}\nDefaultDependencies=no\n'
               'After=local-fs.target\nBefore=wg-quick@orbit.service\n'
               f'[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/bin/python3 -I {script} boot {identity}\n'
               '[Install]\nWantedBy=multi-user.target\n').encode(),
        dependency: f'[Unit]\nRequires={unit_name}\nAfter={unit_name}\n'.encode(),
    }
    if operation == 'remove':
        for path, data in files.items():
            if path.exists() or path.is_symlink():
                require(read(path) == data)
        if dependency.exists():
            dependency.unlink()
        run(['systemctl', 'daemon-reload'])
        if unit.exists():
            run(['systemctl', 'disable', '--now', unit_name])
        if current is not None:
            run(['nft', 'delete', 'table', 'inet', name])
        require(observed(name) is None)
        for path in files:
            if path.exists():
                path.unlink()
        run(['systemctl', 'daemon-reload'])
        return
    if boot:
        for path, data in files.items():
            require(read(path) == data)
    else:
        # Ownership intent and boot dependencies persist before publishing any peer.
        for path, data in files.items():
            put(path, data)
    if current is None:
        run(['nft', '-f', '-'], text)
    require(observed(name) == desired)
    if not boot:
        run(['systemctl', 'daemon-reload'])


def main():
    require(os.geteuid() == 0 and all(shutil.which(name) for name in ('nft', 'unshare', 'python3', 'systemctl')))
    boot = len(sys.argv) == 3 and sys.argv[1] == 'boot'
    if boot:
        identity = sys.argv[2]
        require(str(uuid.UUID(identity)) == identity)
        request = {'operation': 'ensure', **json.loads(read(ROOT / (identity + '.json')))}
    else:
        require(len(sys.argv) == 1)
        data = sys.stdin.buffer.read(8193)
        require(len(data) <= 8192)
        request = json.loads(data)
    spec = validate(request)
    fd = os.open(LOCK, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o600 and info.st_nlink == 1)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        apply(spec, request['operation'], boot)
    finally:
        os.close(fd)
    if request['operation'] == 'ensure' and not boot:
        # The boot service also takes the hub lock; do not wait for it while holding that lock.
        unit_name = 'orbit-sandbox-network-' + spec['sandbox_id'] + '.service'
        run(['systemctl', 'enable', '--now', unit_name])
        require(run(['systemctl', 'is-active', unit_name]).strip() == 'active')
        require(observed(table_name(spec)) == expected(table_name(spec), render(spec)))
    print(json.dumps({'sandbox_id': spec['sandbox_id'], 'operation': request['operation'], 'confirmed': True}))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # The hub is a trust boundary; never publish command output or file content.
        print(json.dumps({'error': 'sandbox network unavailable'}))
        sys.exit(1)
