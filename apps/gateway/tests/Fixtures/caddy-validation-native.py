"""Real publisher + Caddy, isolated from host services and managed paths."""
import json
import os
import pathlib
import pwd
import shutil
import signal
import subprocess
import sys
import time
import urllib.request


def command(arguments, **kwargs):
    return subprocess.run(arguments, capture_output=True, text=True, timeout=15, **kwargs)


def activate():
    if sys.argv[2] != 'reload-or-restart':
        return 0
    root = pathlib.Path(os.environ['ORBIT_CADDY_PROBE_ROOT'])
    adapted = command(['caddy', 'adapt', '--config', str(root / 'etc/caddy/Caddyfile'), '--adapter', 'caddyfile'])
    if adapted.returncode:
        sys.stderr.write(adapted.stderr)
        return adapted.returncode
    config = json.loads(adapted.stdout)
    config['admin'] = {'listen': 'unix/' + str(root / 'runtime/admin.sock'), 'config': {'persist': False}}
    for server in config.get('apps', {}).get('http', {}).get('servers', {}).values():
        server.setdefault('automatic_https', {})['disable_redirects'] = True
    path = root / 'runtime/reload.json'
    path.write_text(json.dumps(config))
    path.chmod(0o644)
    result = command(['runuser', '-u', 'caddy', '--', 'caddy', 'reload', '--config', str(path), '--address', config['admin']['listen']])
    sys.stderr.write(result.stderr)
    return result.returncode


if len(sys.argv) > 1 and sys.argv[1] == 'activate':
    sys.exit(activate())

manifest = json.load(sys.stdin)
root = pathlib.Path(manifest['root'])
assert root.parent == pathlib.Path('/var/tmp') and root.name.startswith('orbit-caddy-native-')
assert os.geteuid() == 0
account = pwd.getpwnam('caddy')
root.mkdir(mode=0o755)
root.chmod(0o755)
service = None
try:
    for relative in ['bin', 'etc/caddy/orbit-versions/current/fragments', 'runtime', 'logs']:
        (root / relative).mkdir(parents=True, exist_ok=True)
    os.chown(root / 'runtime', account.pw_uid, account.pw_gid)
    os.chown(root / 'logs', 0, account.pw_gid)
    (root / 'logs').chmod(0o2775)
    untouched = root / 'unrelated.log'
    untouched.write_text('unrelated\n')
    untouched.chmod(0o600)
    unrelated_stat = untouched.stat()
    current = root / 'etc/caddy/orbit-versions/current'
    (current / 'fragments/unrelated.caddy').write_text('# preserved unrelated fragment\n')
    key = root / 'runtime/site.key'
    certificate = root / 'runtime/site.pem'
    issued = command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key), '-out', str(certificate), '-days', '1', '-subj', '/CN=localhost'])
    assert issued.returncode == 0, issued.stderr
    os.chown(key, 0, account.pw_gid)
    key.chmod(0o640)
    certificate.chmod(0o644)
    certificate_bytes = certificate.read_bytes()
    key_bytes = key.read_bytes()
    (current / 'fragments/tls.caddy').write_text('https://localhost:' + str(manifest['port'] + 1) + ' {\n tls ' + str(certificate) + ' ' + str(key) + '\n respond "tls"\n}\n')
    (current / 'Caddyfile').write_text('import ' + str(current / 'fragments/*.caddy') + '\n')
    live = root / 'etc/caddy/Caddyfile'
    live.symlink_to(current / 'Caddyfile')
    script = root / 'bin/systemctl'
    script.write_text('#!/bin/sh\nexec python3 ' + str(pathlib.Path(__file__).resolve()) + ' activate "$@"\n')
    script.chmod(0o755)
    runtime_config = root / 'runtime/initial.json'
    runtime_config.write_text(json.dumps({'admin': {'listen': 'unix/' + str(root / 'runtime/admin.sock'), 'config': {'persist': False}}}))
    runtime_config.chmod(0o644)
    environment = dict(os.environ, ORBIT_CADDY_PROBE_ROOT=str(root), PATH=str(root / 'bin') + ':' + os.environ['PATH'])
    service_output = (root / 'runtime-output').open('w+')
    service = subprocess.Popen(['runuser', '-u', 'caddy', '--', 'caddy', 'run', '--config', str(runtime_config)], stdout=service_output, stderr=service_output, env=environment, start_new_session=True)
    for _ in range(100):
        if (root / 'runtime/admin.sock').exists():
            break
        assert service.poll() is None
        time.sleep(0.02)
    assert (root / 'runtime/admin.sock').exists()

    def publish(name, expected=0):
        item = manifest[name]
        result = command(item['arguments'], input=item['input'], env=environment)
        assert (result.returncode == 0) == (expected == 0), name + ': ' + result.stderr
        return result

    def response():
        with urllib.request.urlopen('http://127.0.0.1:' + str(manifest['port']), timeout=3) as reply:
            return reply.read().decode()

    publish('publish')
    assert response() == 'first'
    log = root / 'logs/access.log'
    stat = log.stat()
    assert (stat.st_uid, stat.st_gid, stat.st_mode & 0o777) == (account.pw_uid, account.pw_gid, 0o600), str(stat)
    with log.open('a') as handle:
        handle.write('preserved-sentinel\n')
    log_inode = log.stat().st_ino
    publish('reload')
    assert response() == 'second'
    assert log.stat().st_ino == log_inode
    assert 'preserved-sentinel\n' in log.read_text()
    previous = live.readlink()
    publish('invalid', expected=1)
    assert live.readlink() == previous
    assert response() == 'second'
    blocked = root / 'logs/blocked.log'
    blocked.write_text('do-not-adopt\n')
    blocked.chmod(0o600)
    blocked_stat = blocked.stat()
    refused = publish('blocked', expected=1)
    assert 'permission denied' in refused.stderr
    assert live.readlink() == previous
    assert response() == 'second'
    assert blocked.stat() == blocked_stat
    assert blocked.read_text() == 'do-not-adopt\n'
    publish('retry')
    assert response() == 'retry'
    assert log.stat().st_ino == log_inode
    assert 'preserved-sentinel\n' in log.read_text()
    assert (live.resolve().parent / 'fragments/unrelated.caddy').read_text() == '# preserved unrelated fragment\n'
    publish('remove')
    assert not (live.resolve().parent / 'fragments/app-dev.caddy').exists()
    assert (live.resolve().parent / 'fragments/unrelated.caddy').read_text() == '# preserved unrelated fragment\n'
    assert untouched.stat() == unrelated_stat
    assert untouched.read_text() == 'unrelated\n'
    assert certificate.read_bytes() == certificate_bytes
    assert key.read_bytes() == key_bytes
    assert key.stat().st_uid == 0 and key.stat().st_gid == account.pw_gid and key.stat().st_mode & 0o777 == 0o640
    print(json.dumps({'publish': 'passed', 'reload': 'passed', 'invalid_rollback': 'passed', 'inaccessible_log_refused': 'passed', 'retry': 'passed', 'removal': 'passed', 'unrelated_preserved': 'passed', 'certificate_preserved': 'passed', 'log_uid': stat.st_uid, 'log_gid': stat.st_gid, 'log_mode': '0600'}))
finally:
    try:
        if service is not None:
            try:
                command(['caddy', 'stop', '--address', 'unix/' + str(root / 'runtime/admin.sock')])
                service.wait(timeout=5)
            finally:
                try:
                    os.killpg(service.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                service.wait(timeout=5)
                service_output.close()
    finally:
        shutil.rmtree(root)
