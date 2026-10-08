<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

/** Native ACL write-ahead evidence and the protected access/file handoff. */
final readonly class AppProjectionAccessProgram
{
    public static function library(): string
    {
        return <<<'PYTHON'
import hashlib as ah, json as aj, os as ao, pathlib as ap, pwd as aw, grp as ag, stat as ast, subprocess as asp, fcntl as af, ctypes as ac
ACCESS_BASE = '/var/lib/orbit/app-access'
def access_fail(): raise ValueError('access_evidence')
def access_encode(value): return aj.dumps(value, sort_keys=True, separators=(',', ':')).encode()
def access_directory(path, create=False):
    fd = ao.open('/', ao.O_RDONLY | ao.O_DIRECTORY)
    try:
        for part in ap.Path(path).parts[1:]:
            if create:
                try: ao.mkdir(part, 0o700, dir_fd=fd); ao.fsync(fd)
                except FileExistsError: pass
            child = ao.open(part, ao.O_RDONLY | ao.O_DIRECTORY | ao.O_NOFOLLOW, dir_fd=fd); ao.close(fd); fd = child
            m = ao.fstat(fd)
            if m.st_mode & 0o022 and not (m.st_uid == 0 and m.st_mode & ast.S_ISVTX): access_fail()
        return fd
    except Exception: ao.close(fd); raise
def access_read(fd):
    child = ao.open('manifest.json', ao.O_RDONLY | ao.O_NOFOLLOW, dir_fd=fd)
    try:
        m = ao.fstat(child)
        if not ast.S_ISREG(m.st_mode) or m.st_uid != ao.geteuid() or ast.S_IMODE(m.st_mode) != 0o600 or m.st_nlink != 1: access_fail()
        with ao.fdopen(ao.dup(child), 'rb') as stream: return aj.load(stream)
    finally: ao.close(child)
def access_save(fd, value):
    try: ao.unlink('manifest.next', dir_fd=fd)
    except FileNotFoundError: pass
    child = ao.open('manifest.next', ao.O_WRONLY | ao.O_CREAT | ao.O_EXCL | ao.O_NOFOLLOW, 0o600, dir_fd=fd)
    try:
        ao.fchmod(child, 0o600)
        with ao.fdopen(ao.dup(child), 'wb') as stream: stream.write(access_encode(value)); stream.flush()
        ao.fsync(child)
    finally: ao.close(child)
    ao.replace('manifest.next', 'manifest.json', src_dir_fd=fd, dst_dir_fd=fd); ao.fsync(fd)
def access_open(receipt, create=False):
    if len(receipt) != 36 or any(c not in 'abcdef0123456789-' for c in receipt): access_fail()
    parent = access_directory(str(ap.Path(ACCESS_BASE).parent), create)
    try:
        if create:
            try: ao.mkdir(ap.Path(ACCESS_BASE).name, 0o700, dir_fd=parent); ao.fsync(parent)
            except FileExistsError: pass
    finally: ao.close(parent)
    base = access_directory(ACCESS_BASE)
    try:
        if ao.fstat(base).st_uid != ao.geteuid() or ast.S_IMODE(ao.fstat(base).st_mode) != 0o700: access_fail()
        fresh = False
        if create:
            try: ao.mkdir(receipt, 0o700, dir_fd=base); ao.fsync(base); fresh = True
            except FileExistsError: pass
        fd = ao.open(receipt, ao.O_RDONLY | ao.O_DIRECTORY | ao.O_NOFOLLOW, dir_fd=base)
        if ao.fstat(fd).st_uid != ao.geteuid() or ast.S_IMODE(ao.fstat(fd).st_mode) != 0o700: ao.close(fd); access_fail()
        af.flock(fd, af.LOCK_EX)
        return fd, fresh
    finally: ao.close(base)
def access_acl(text):
    result = {}
    for entry in text.replace(',', '\n').splitlines():
        entry = entry.split('#')[0].strip()
        if not entry or entry == '*': continue
        parts = entry.split(':'); prefix = ''
        if parts[0] in ('d', 'default'): prefix = 'd:'; parts = parts[1:]
        if len(parts) != 3: access_fail()
        tag = {'user': 'u', 'group': 'g', 'mask': 'm', 'other': 'o'}.get(parts[0], parts[0])
        qualifier = parts[1]
        if qualifier and not qualifier.isdecimal(): qualifier = str(aw.getpwnam(qualifier).pw_uid if tag == 'u' else ag.getgrnam(qualifier).gr_gid)
        result[prefix + tag + ':' + qualifier] = parts[2]
    return result
access_lib = None
def access_actual_acl(path):
    global access_lib
    if access_lib is None:
        access_lib = ac.CDLL('libacl.so.1', use_errno=True)
        access_lib.acl_get_file.argtypes = [ac.c_char_p, ac.c_int]; access_lib.acl_get_file.restype = ac.c_void_p
        access_lib.acl_to_text.argtypes = [ac.c_void_p, ac.POINTER(ac.c_ssize_t)]; access_lib.acl_to_text.restype = ac.c_void_p
        access_lib.acl_free.argtypes = [ac.c_void_p]
    result = {}
    for kind, prefix in ((0x8000, ''), (0x4000, 'd:')):
        if kind == 0x4000 and not ast.S_ISDIR(ao.lstat(path).st_mode): continue
        acl = access_lib.acl_get_file(ao.fsencode(path), kind)
        if not acl: access_fail()
        text = None
        try:
            length = ac.c_ssize_t(); text = access_lib.acl_to_text(acl, ac.byref(length))
            if not text: access_fail()
            result.update({prefix + key: value for key, value in access_acl(ac.string_at(text, length.value).decode()).items()})
        finally:
            if text: access_lib.acl_free(text)
            access_lib.acl_free(acl)
    return result
def access_observe(path):
    m = ao.lstat(path)
    if not (ast.S_ISREG(m.st_mode) or ast.S_ISDIR(m.st_mode)): access_fail()
    value = {'identity': [m.st_dev, m.st_ino, m.st_uid, m.st_gid, ast.S_IMODE(m.st_mode)], 'acl': access_actual_acl(path),
        'attributes': {name: ao.getxattr(path, name).hex() for name in ao.listxattr(path) if name not in ('system.posix_acl_access', 'system.posix_acl_default')}}
    if ast.S_ISREG(m.st_mode) and (ap.Path(path).name in ('.env', '.env.testing', 'config.php')):
        if m.st_nlink != 1 or m.st_size > 1048576: access_fail()
        fd = ao.open(path, ao.O_RDONLY | ao.O_NOFOLLOW)
        try:
            with ao.fdopen(ao.dup(fd), 'rb') as stream: value['digest'] = ah.sha256(stream.read(1048577)).hexdigest()
            if ao.fstat(fd).st_ino != m.st_ino: access_fail()
        finally: ao.close(fd)
    return value
def access_verify(manifest):
    expected = dict(manifest['baseline'])
    for operation in manifest['operations']:
        for path, pair in operation['paths'].items(): expected[path] = pair['after'] if operation['complete'] else pair
    for path, value in expected.items():
        try: current = access_observe(path)
        except FileNotFoundError: current = None
        if isinstance(value, dict) and 'before' in value:
            if current not in (value['before'], value['after']): access_fail()
        elif current != value: access_fail()
def access_handoff(data, acknowledge=False):
    if 'access_binding' not in data: return False
    fd, _ = access_open(data['access_binding']['receipt_id'])
    try:
        manifest = access_read(fd)
        if manifest['binding'] != data['access_binding'] or not manifest['ready'] or manifest['aborted']: access_fail()
        if not manifest['file_initialized']: access_verify(manifest)
        if acknowledge and not manifest['file_initialized']:
            manifest['file_initialized'] = True; access_save(fd, manifest)
        return not manifest['file_initialized']
    finally: ao.close(fd)
PYTHON;
    }

    public static function script(): string
    {
        return self::library()."\n".<<<'PYTHON'
import sys
try:
    if len(sys.argv) > 1:
        receipt, arguments = sys.argv[1], sys.argv[2:]
        if '--restore' in ' '.join(arguments): access_fail()
        fd, _ = access_open(receipt)
        try:
            manifest = access_read(fd)
            if manifest['ready'] or manifest['file_initialized'] or manifest['aborted']: access_fail()
            access_verify(manifest)
            key = ah.sha256(access_encode(arguments)).hexdigest()
            operation = next((item for item in manifest['operations'] if item['key'] == key), None)
            if operation is None:
                output = asp.check_output(['setfacl', '--test', *arguments], text=True)
                paths = {}
                for line in output.splitlines():
                    path, acl = line.split(': ', 1)
                    before = access_observe(path); after = aj.loads(aj.dumps(before)); intended = access_acl(acl)
                    if 'u:' in intended: after['acl'] = {key: value for key, value in after['acl'].items() if key.startswith('d:')}
                    if 'd:u:' in intended: after['acl'] = {key: value for key, value in after['acl'].items() if not key.startswith('d:')}
                    after['acl'].update(intended)
                    bits = lambda value: sum(bit for letter, bit in (('r', 4), ('w', 2), ('x', 1)) if letter in value)
                    after['identity'][4] = (before['identity'][4] & ~0o777) | (bits(after['acl']['u:']) << 6) | (bits(after['acl'].get('m:', after['acl']['g:'])) << 3) | bits(after['acl']['o:'])
                    paths[path] = {'before': before, 'after': after}
                operation = {'key': key, 'arguments': arguments, 'paths': paths, 'complete': False}
                manifest['operations'].append(operation); access_save(fd, manifest)
            if not operation['complete']:
                for path, pair in operation['paths'].items():
                    current = access_observe(path)
                    if current == pair['before']:
                        # The native owner supplies its exact options and operands; execute the batch once.
                        asp.run(['setfacl', *arguments], check=True); break
                    if current != pair['after']: access_fail()
                for path, pair in operation['paths'].items():
                    if access_observe(path) != pair['after']: access_fail()
                operation['complete'] = True; access_save(fd, manifest)
        finally: ao.close(fd)
    else:
        data = aj.load(sys.stdin); binding = data['binding']; action = data['action']
        fd, fresh = access_open(binding['receipt_id'], action == 'begin' and not data.get('recover', False))
        try:
            if fresh:
                baseline = {}
                for path in data['paths']:
                    for parent in (ap.Path(path).parent, *ap.Path(path).parent.parents):
                        if str(parent) == '/': continue
                        try: baseline[str(parent)] = access_observe(str(parent))
                        except FileNotFoundError: baseline[str(parent)] = None
                    try: baseline[path] = access_observe(path)
                    except FileNotFoundError: baseline[path] = None
                manifest = {'binding': binding, 'baseline': baseline, 'operations': [], 'ready': False, 'file_initialized': False, 'aborted': False}
                access_save(fd, manifest)
            else:
                manifest = access_read(fd)
                if manifest['binding'] != binding: access_fail()
            if not manifest['file_initialized']: access_verify(manifest)
            if action == 'finish': manifest['ready'] = True; access_save(fd, manifest)
            elif action == 'abort':
                if manifest['file_initialized']: access_fail()
                manifest['aborted'] = True; access_save(fd, manifest)
            elif action != 'begin': access_fail()
            print(aj.dumps({'ready': manifest['ready'], 'file_initialized': manifest['file_initialized'], 'aborted': manifest['aborted']}))
        finally: ao.close(fd)
except Exception:
    print('{"conflict":"access_evidence"}'); raise SystemExit(43)
PYTHON;
    }
}
