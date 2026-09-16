<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final class YarnDependencyUpdatePresenceProgram
{
    public static function render(): string
    {
        return <<<'PYTHON'
import json, os, re, stat, sys
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
def yarn_declaration(manifest):
    names = []
    major = None
    if 'packageManager' in manifest:
        manager = manifest['packageManager']
        if not isinstance(manager, str):
            fail('invalid_manifest')
        match = re.fullmatch(r'(npm|pnpm|bun|yarn)@(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?', manager)
        if match is None:
            fail('unsupported_format')
        names.append(match.group(1))
        if match.group(1) == 'yarn':
            major = int(match.group(2))
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
            selected_major = None
            for entry in entries:
                if not isinstance(entry, dict) or not isinstance(entry.get('name'), str):
                    fail('invalid_manifest')
                version = entry.get('version')
                if version is not None and not isinstance(version, str):
                    fail('invalid_manifest')
                fail_mode = entry.get('onFail', 'error')
                if fail_mode not in ('ignore', 'warn', 'error', 'download'):
                    fail('invalid_manifest')
                on_fail = fail_mode
                if entry['name'] in ('npm', 'pnpm', 'bun', 'yarn'):
                    selected = entry['name']
                    if selected == 'yarn' and isinstance(version, str):
                        version_match = re.match(r'(0|[1-9][0-9]*)', version)
                        if version_match is not None:
                            selected_major = int(version_match.group(1))
                    break
            if selected is not None:
                names.append(selected)
                if selected == 'yarn' and selected_major is not None and major is None:
                    major = selected_major
            elif on_fail not in ('warn', 'ignore'):
                fail('unsupported_format')
    unique = []
    for name in names:
        if name not in unique:
            unique.append(name)
    return unique, major
def lock_has_metadata(dir_fd):
    try:
        fd = os.open('yarn.lock', os.O_RDONLY, dir_fd=dir_fd)
    except OSError:
        fail('unreadable_source')
    try:
        data = os.read(fd, 8192)
    except OSError:
        fail('unreadable_source')
    finally:
        os.close(fd)
    return b'__metadata' in data
try:
    path, = sys.argv[1:]
    fd = directory(path)
    try:
        has_manifest = present(fd, 'package.json')
        yarn_lock = present(fd, 'yarn.lock')
        yarnrc = present(fd, '.yarnrc.yml')
        yarn_config = present(fd, 'yarn.config.cjs')
        workspace = present(fd, 'pnpm-workspace.yaml')
        managers, yarn_major = yarn_declaration(load_manifest(fd)) if has_manifest else ([], None)
        modern_lock = lock_has_metadata(fd) if yarn_lock else False
    finally:
        os.close(fd)
    if workspace:
        fail('unsupported_layout')
    yarn_declared = 'yarn' in managers
    if not yarn_declared and not yarn_lock and not yarnrc and not yarn_config:
        print(json.dumps({'status': 'absent'}, separators=(',', ':')))
    else:
        family = 'modern' if yarnrc or yarn_config or modern_lock or (yarn_major is not None and yarn_major >= 2) else 'classic'
        print(json.dumps({'status': 'present', 'family': family}, separators=(',', ':')))
except Refusal as error:
    print(json.dumps({'error': str(error)}, separators=(',', ':')))
except (OSError, ValueError):
    print(json.dumps({'error': 'dependencies.unreadable_source'}, separators=(',', ':')))
PYTHON;
    }
}
