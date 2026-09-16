<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final class BunDependencyUpdatePresenceProgram
{
    public static function render(): string
    {
        return <<<'PYTHON'
import json, os, re, stat, subprocess, sys
class Refusal(Exception):
    pass
def fail(code):
    raise Refusal('dependencies.' + code)
def directory(path):
    if not path.startswith('/') or path == '/' or os.path.normpath(path) != path or os.path.realpath(path) != path:
        fail('unsafe_source')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
    try:
        for component in path.split('/')[1:]:
            next_fd = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        return fd
    except BaseException:
        os.close(fd)
        raise
def present(fd, name):
    try:
        info = os.stat(name, dir_fd=fd, follow_symlinks=False)
    except FileNotFoundError:
        return False
    except OSError:
        fail('unreadable_source')
    if not stat.S_ISREG(info.st_mode):
        fail('unsafe_source')
    return True
def object_pairs(pairs):
    obj = {}
    for key, value in pairs:
        if key in obj:
            fail('invalid_manifest')
        obj[key] = value
    return obj
def load_manifest(dir_fd):
    try:
        fd = os.open('package.json', os.O_RDONLY, dir_fd=dir_fd)
    except OSError:
        fail('unreadable_source')
    try:
        with os.fdopen(os.dup(fd), 'rb') as handle:
            manifest = json.load(handle, object_pairs_hook=object_pairs)
    except (OSError, ValueError):
        fail('invalid_manifest')
    finally:
        os.close(fd)
    if not isinstance(manifest, dict):
        fail('invalid_manifest')
    if 'workspaces' in manifest:
        fail('unsupported_layout')
    return manifest
def declared_managers(manifest):
    names = []
    if 'packageManager' in manifest:
        manager = manifest['packageManager']
        if not isinstance(manager, str):
            fail('invalid_manifest')
        match = re.fullmatch(r'(npm|pnpm|bun|yarn)@(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?', manager)
        if match is None:
            fail('unsupported_format')
        names.append(match.group(1))
    if 'devEngines' in manifest:
        engines = manifest['devEngines']
        if not isinstance(engines, dict):
            fail('invalid_manifest')
        if 'packageManager' in engines:
            entries = engines['packageManager']
            if isinstance(entries, dict):
                entries = [entries]
            if not isinstance(entries, list):
                fail('invalid_manifest')
            selected = None
            on_fail = 'error'
            for entry in entries:
                if not isinstance(entry, dict) or not isinstance(entry.get('name'), str):
                    fail('invalid_manifest')
                if entry.get('version') is not None and not isinstance(entry.get('version'), str):
                    fail('invalid_manifest')
                fail_mode = entry.get('onFail', 'error')
                if fail_mode not in ('ignore', 'warn', 'error', 'download'):
                    fail('invalid_manifest')
                on_fail = fail_mode
                if entry['name'] in ('npm', 'pnpm', 'bun', 'yarn'):
                    selected = entry['name']
                    break
            if selected is not None:
                names.append(selected)
            elif on_fail not in ('warn', 'ignore'):
                fail('unsupported_format')
    unique = []
    for name in names:
        if name not in unique:
            unique.append(name)
    return unique
def unique_signals(values):
    unique = []
    for name in values:
        if name not in unique:
            unique.append(name)
    return unique
def probe_vp(candidate, extra_env=None):
    try:
        info = os.stat(candidate)
    except OSError:
        return None
    if not stat.S_ISREG(info.st_mode) or not os.access(candidate, os.X_OK):
        return None
    env = None
    if extra_env is not None:
        env = os.environ.copy()
        env.update(extra_env)
    try:
        result = subprocess.run([candidate, '--version'], stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=10, check=False, env=env)
    except (OSError, subprocess.TimeoutExpired):
        return None
    if result.returncode != 0:
        return None
    first = result.stdout.decode('utf-8', 'replace').splitlines()[0] if result.stdout else ''
    match = re.fullmatch(r'vp v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)', first.strip())
    if match is None:
        return None
    return {'path': candidate, 'version': '.'.join(match.groups())}
def vite_plus():
    home = os.path.expanduser('~')
    candidates = [
        ('/opt/orbit/vite-plus/bin/vp', {'VP_HOME': '/opt/orbit/vite-plus'}),
        (home + '/.vite-plus/bin/vp', None),
        (home + '/.vite-plus/current/bin/vp', None),
        (home + '/.local/share/vite-plus/bin/vp', None),
        (home + '/.local/share/vite-plus/current/bin/vp', None),
    ]
    for candidate, extra_env in candidates:
        found = probe_vp(candidate, extra_env)
        if found is not None:
            return found
    return None
try:
    path, = sys.argv[1:]
    fd = directory(path)
    try:
        has_manifest = present(fd, 'package.json')
        shrinkwrap = present(fd, 'npm-shrinkwrap.json')
        package_lock = present(fd, 'package-lock.json')
        yarn_files = any(present(fd, name) for name in ('yarn.lock', '.yarnrc.yml', 'yarn.config.cjs'))
        workspace = present(fd, 'pnpm-workspace.yaml')
        pnpm_files = any(present(fd, name) for name in ('pnpm-lock.yaml', '.pnpmfile.cjs', 'pnpmfile.cjs'))
        bun_lock = present(fd, 'bun.lock')
        bun_lockb = present(fd, 'bun.lockb')
        bun_files = bun_lock or bun_lockb or present(fd, 'bunfig.toml')
        npm_lock = shrinkwrap or package_lock
        managers = declared_managers(load_manifest(fd)) if has_manifest else []
    finally:
        os.close(fd)
    if workspace:
        fail('unsupported_layout')
    signals = unique_signals([*managers, *(['yarn'] if yarn_files else []), *(['pnpm'] if pnpm_files else []), *(['bun'] if bun_files else []), *(['npm'] if npm_lock else [])])
    if 'yarn' in signals:
        fail('unsupported_format')
    if len(signals) > 1:
        fail('ambiguous_manager')
    if signals == ['bun']:
        if bun_lockb and not bun_lock:
            fail('unsupported_format')
        status = 'present' if has_manifest and bun_lock else 'incomplete'
    else:
        status = 'absent'
    payload = {'status': status}
    if status == 'present':
        payload['vp'] = vite_plus()
    print(json.dumps(payload, separators=(',', ':')))
except Refusal as error:
    print(json.dumps({'error': str(error)}, separators=(',', ':')))
except (OSError, ValueError):
    print(json.dumps({'error': 'dependencies.unreadable_source'}, separators=(',', ':')))
PYTHON;
    }
}
