<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final class ComposerDependencyUpdatePresenceProgram
{
    public static function render(): string
    {
        return <<<'PYTHON'
import json, os, stat, sys
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
try:
    path, = sys.argv[1:]
    fd = directory(path)
    try:
        manifest = present(fd, 'composer.json')
        lock = present(fd, 'composer.lock')
    finally:
        os.close(fd)
    if manifest and lock:
        status = 'present'
    elif not manifest and not lock:
        status = 'absent'
    else:
        status = 'incomplete'
    print(json.dumps({'status': status}, separators=(',', ':')))
except Refusal as error:
    print(json.dumps({'error': str(error)}, separators=(',', ':')))
except (OSError, ValueError):
    print(json.dumps({'error': 'dependencies.unreadable_source'}, separators=(',', ':')))
PYTHON;
    }
}
