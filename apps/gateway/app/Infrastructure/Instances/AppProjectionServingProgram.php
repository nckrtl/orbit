<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

/** Protected cached APP_URL checkpoints. Application PHP is never executed. */
final readonly class AppProjectionServingProgram
{
    public static function script(): string
    {
        return AppProjectionAccessProgram::library()."\n".<<<'PYTHON'
            import base64, ctypes, fcntl, hashlib, json, os, pathlib, pwd, re, stat, sys
            held = []
            def fail(): raise ValueError('protected_evidence')
            def digest(value): return hashlib.sha256(value).hexdigest()
            def encoded(value): return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
            def attrs(fd): return {name: base64.b64encode(os.getxattr(fd, name)).decode() for name in sorted(os.listxattr(fd))}
            def meta(fd):
                m = os.fstat(fd)
                return {'identity': [m.st_dev, m.st_ino, m.st_uid, m.st_gid, stat.S_IMODE(m.st_mode)], 'attributes': attrs(fd)}
            def directory(path, protected=False):
                path = str(path)
                if not path.startswith('/') or os.path.normpath(path) != path: fail()
                fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
                chain, current = [], ''
                try:
                    for part in path.split('/')[1:]:
                        child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                        os.close(fd); fd = child; current += '/' + part
                        m = os.fstat(fd)
                        if m.st_uid not in (0, os.geteuid(), uid): fail()
                        if m.st_mode & 0o022 and not (m.st_uid == 0 and m.st_mode & stat.S_ISVTX): fail()
                        chain.append([current, meta(fd)])
                    if protected and (m.st_uid != os.geteuid() or stat.S_IMODE(m.st_mode) != 0o700): fail()
                    held.append(fd)
                    return fd, chain
                except Exception: os.close(fd); raise
            def parent_fd(path): return directory(path.parent)[0]
            def read_at(parent, name, owner, protected=False):
                try: fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
                except FileNotFoundError: return None
                try:
                    m = os.fstat(fd)
                    if not stat.S_ISREG(m.st_mode) or m.st_uid != owner or m.st_nlink != 1 or m.st_size > 1048576 or m.st_mode & 0o022: fail()
                    if protected and stat.S_IMODE(m.st_mode) != 0o600: fail()
                    with os.fdopen(os.dup(fd), 'rb') as stream: contents = stream.read(1048577)
                    if len(contents) > 1048576: fail()
                    value = dict(meta(fd), digest=digest(contents), contents=contents)
                    if meta(fd)['identity'] != value['identity']: fail()
                    return value
                finally: os.close(fd)
            def read(path, owner, protected=False): return read_at(parent_fd(path), path.name, owner, protected)
            def evidence(value): return None if value is None else {k: value[k] for k in ('identity', 'digest', 'attributes')}
            def create_at(parent, name, contents, owner, group, mode, attributes=None):
                fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
                try:
                    os.fchown(fd, owner, group); os.fchmod(fd, mode)
                    if attributes is not None:
                        for name in os.listxattr(fd):
                            if name not in attributes: os.removexattr(fd, name)
                        for name, value in attributes.items(): os.setxattr(fd, name, base64.b64decode(value))
                    with os.fdopen(os.dup(fd), 'wb') as stream: stream.write(contents); stream.flush()
                    os.fsync(fd)
                finally: os.close(fd)
                os.fsync(parent)
            def create(path, contents, owner, group, mode):
                fd = parent_fd(path); create_at(fd, path.name, contents, owner, group, mode)
                return evidence(read_at(fd, path.name, owner))
            def save(path, value):
                fd = parent_fd(path); name = path.name + '.next'
                if read_at(fd, name, os.geteuid(), True) is not None: os.unlink(name, dir_fd=fd)
                create_at(fd, name, encoded(value), os.geteuid(), os.getegid(), 0o600)
                os.replace(name, path.name, src_dir_fd=fd, dst_dir_fd=fd); os.fsync(fd)
            def load(root):
                value = read(root / 'manifest.json', os.geteuid(), True)
                if value is None: fail()
                return json.loads(value['contents'])
            def target(record, fd):
                observed, chain = directory(pathlib.Path(record['path']).parent)
                if chain != record['chain'] or meta(fd) != chain[-1][1] or meta(observed) != meta(fd): fail()
                return evidence(read_at(fd, pathlib.Path(record['path']).name, uid))
            def staging_parent(path, checkout, target_fd):
                for ancestor in (path.parent, *path.parent.parents):
                    if ancestor == checkout or checkout in ancestor.parents: continue
                    fd, chain = directory(ancestor)
                    if os.fstat(fd).st_dev != os.fstat(target_fd).st_dev: break
                    if os.fstat(fd).st_uid != os.geteuid(): continue
                    base = ancestor / '.orbit-app-serving-stages'
                    try: os.mkdir(base.name, 0o700, dir_fd=fd); os.fsync(fd)
                    except FileExistsError: pass
                    parent, chain = directory(base, True)
                    if os.fstat(parent).st_dev != os.fstat(target_fd).st_dev: fail()
                    return str(base), chain
                fail()
            def stage_parent(record):
                fd, chain = directory(pathlib.Path(record['stage_parent']), True)
                if chain != record['stage_parent_chain']: fail()
                return fd
            def stage(record, target_fd, source, origin):
                target(record, target_fd)
                parent = stage_parent(record)
                name = record['stage_name']
                if not record.get('stage_intended'):
                    try: os.stat(name, dir_fd=parent, follow_symlinks=False)
                    except FileNotFoundError: pass
                    else: fail()
                    record['stage_intended'] = True; save(origin / 'manifest.json', source)
                try: os.mkdir(name, 0o700, dir_fd=parent); os.fsync(parent)
                except FileExistsError: pass
                fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent); held.append(fd)
                protection = meta(fd)
                if protection['identity'][2] != os.geteuid() or protection['identity'][4] != 0o700: fail()
                if record.get('stage_identity') is not None and protection != record['stage_identity']: fail()
                binding = encoded([source['binding'], record['path']])
                marker = read_at(fd, 'owner.json', os.geteuid(), True)
                if marker is None:
                    if record.get('stage_identity') is not None or os.listdir(fd): fail()
                    create_at(fd, 'owner.json', binding, os.geteuid(), os.getegid(), 0o600)
                elif marker['contents'] != binding: fail()
                if record.get('stage_identity') is None:
                    record['stage_identity'] = protection; save(origin / 'manifest.json', source)
                return fd
            def verify_stage(record, parent, fd):
                parent = stage_parent(record)
                observed = os.open(record['stage_name'], os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                try:
                    if meta(observed) != record['stage_identity'] or meta(fd) != record['stage_identity']: fail()
                finally: os.close(observed)
            def proposal(record, key, name, snapshot, expected_snapshot, stage_fd, source, origin):
                desired = read(snapshot, os.geteuid(), True)
                if evidence(desired) != expected_snapshot: fail()
                existing = read_at(stage_fd, name, uid)
                if not record.get(key + '_intended'):
                    if existing is not None: fail()
                    record[key + '_intended'] = True; save(origin / 'manifest.json', source)
                if record.get(key) is None:
                    if existing is None:
                        m = record['before']['identity']
                        create_at(stage_fd, name, desired['contents'], m[2], m[3], m[4], record['before']['attributes'])
                        # creation checkpoint: the file is still inside the recorded private directory
                        existing = read_at(stage_fd, name, uid)
                    # Lost creation acknowledgment is authorized only by private scope + committed intent.
                    if existing['digest'] != desired['digest'] or existing['identity'][2:5] != record['before']['identity'][2:5] or existing['attributes'] != record['before']['attributes']: fail()
                    record[key] = evidence(existing); save(origin / 'manifest.json', source)
                elif evidence(existing) != record[key]: fail()
                return record[key]
            libc = ctypes.CDLL(None, use_errno=True)
            def exchange(src_fd, src_name, dst_fd, dst_name):
                rc = libc.renameat2(src_fd, ctypes.c_char_p(src_name.encode()), dst_fd, ctypes.c_char_p(dst_name.encode()), 2)
                if rc != 0: raise OSError(ctypes.get_errno(), 'exchange')
                os.fsync(src_fd); os.fsync(dst_fd)
            def publish(record, fd, stage_fd, name, before, after):
                if target(record, fd) != before: fail()
                verify_stage(record, fd, stage_fd)
                if evidence(read_at(stage_fd, name, uid)) != after: fail()
                # Exchange retains any racing foreign target instead of destroying its bytes.
                exchange(stage_fd, name, fd, pathlib.Path(record['path']).name)
                try:
                    if evidence(read_at(stage_fd, name, uid)) != before or target(record, fd) != after: fail()
                except Exception:
                    # Roll back through the held directories only while the target is still our file.
                    if evidence(read_at(fd, pathlib.Path(record['path']).name, uid)) == after:
                        exchange(stage_fd, name, fd, pathlib.Path(record['path']).name)
                    raise
            def patch(contents, url):
                pattern = rb"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|/\*.*?\*/|//[^\n]*|\#[^\n]*|=>|[()\[\],]|[A-Za-z_][A-Za-z0-9_]*|\S"
                tokens = [t for t in re.finditer(pattern, contents, re.S) if not t.group().startswith((b'/*', b'//', b'#'))]
                stack, pending, matches = [], None, []
                for index, token in enumerate(tokens):
                    value = token.group()
                    if value in (b'(', b'['):
                        stack.append((stack[-1] if stack else []) + ([pending] if pending is not None else [])); pending = None
                    elif value in (b')', b']'):
                        if not stack: fail()
                        stack.pop(); pending = None
                    elif value == b',': pending = None
                    elif value[:1] in (b"'", b'"') and index + 1 < len(tokens) and tokens[index + 1].group() == b'=>':
                        pending = value[1:-1]
                        if len(stack) == 2 and stack[-1] == [b'app'] and pending == b'url':
                            if index + 2 >= len(tokens): fail()
                            match = tokens[index + 2]
                            if match.group()[:1] not in (b"'", b'"'): fail()
                            matches.append(match)
                if stack or len(matches) != 1: fail()
                match = matches[0]
                replacement = b"'" + url.replace('\\', '\\\\').replace("'", "\\'").encode() + b"'"
                return contents[:match.start()] + replacement + contents[match.end():]
            try:
                data = json.load(sys.stdin); binding, targets = data['binding'], data['targets']
                for key in ('step_id', 'projection_id', 'receipt_id'):
                    if not re.fullmatch(r'[a-f0-9-]{36}', binding[key]): fail()
                for key in ('plan_digest', 'intent_digest'):
                    if not re.fullmatch(r'[a-f0-9]{64}', binding[key]): fail()
                account = pwd.getpwnam(data['user']); uid = account.pw_uid
                base = pathlib.Path('/var/lib/orbit/app-serving'); parent, _ = directory(base.parent)
                if os.fstat(parent).st_uid != os.geteuid(): fail()
                try: os.mkdir(base.name, 0o700, dir_fd=parent); os.fsync(parent)
                except FileExistsError: pass
                base_fd, _ = directory(base, True); fcntl.flock(base_fd, fcntl.LOCK_EX)
                root = base / binding['receipt_id']; fresh = False
                try: os.mkdir(root.name, 0o700, dir_fd=base_fd); os.fsync(base_fd); fresh = True
                except FileExistsError: pass
                directory(root, True)
                if fresh and data['recover'] and not access_handoff(data): fail()
                phase = data['phase']
                if fresh:
                    if phase == 'prepare':
                        access_handoff(data)
                        records = []
                        for index, path in enumerate(data['cache_paths']):
                            file = pathlib.Path(path)
                            try: fd, chain = directory(file.parent)
                            except FileNotFoundError:
                                records.append({'path': path, 'before': None, 'result': None, 'absent_directory': True}); continue
                            original = read_at(fd, file.name, uid)
                            record = {'path': path, 'chain': chain, 'before': evidence(original), 'result': None, 'restored': False, 'stage_name': '.orbit-serving-' + binding['receipt_id'] + '-' + str(index), 'stage_identity': None}
                            if original is not None:
                                record['stage_parent'], record['stage_parent_chain'] = staging_parent(file, pathlib.Path(data['checkout_path']), fd)
                                record['snapshot'] = create(root / ('before-' + str(index)), original['contents'], os.geteuid(), os.getegid(), 0o600)
                                record['candidate'] = create(root / ('candidate-' + str(index)), patch(original['contents'], data['url']), os.geteuid(), os.getegid(), 0o600)
                            records.append(record)
                        manifest = {'binding': binding, 'targets': targets, 'phase': phase, 'records': records, 'cleaned': [], 'complete': False}
                    else:
                        origin = base / data['source_receipt']; directory(origin, True); source = load(origin)
                        if source['binding'] != data['source_binding']: fail()
                        manifest = {'binding': binding, 'targets': targets, 'phase': phase, 'source_receipt': data['source_receipt'], 'source_binding': data['source_binding'], 'complete': False}
                    save(root / 'manifest.json', manifest)
                else:
                    manifest = load(root)
                    if manifest['binding'] != binding or manifest['targets'] != targets or manifest['phase'] != phase: fail()
                if phase == 'prepare': access_handoff(data, True)
                if phase == 'prepare': source, origin = manifest, root
                else:
                    origin = base / manifest['source_receipt']; directory(origin, True); source = load(origin)
                    if source['binding'] != manifest['source_binding']: fail()
                opened = []
                for index, record in enumerate(source['records']):
                    file = pathlib.Path(record['path'])
                    if record.get('absent_directory'):
                        if file.exists() or file.is_symlink(): fail()
                        continue
                    fd = parent_fd(file); current = target(record, fd); opened.append((record, fd))
                    if record['before'] is None:
                        if current is not None: fail()
                        continue
                    before_path, candidate_path = origin / ('before-' + str(index)), origin / ('candidate-' + str(index))
                    if phase == 'prepare':
                        if record.get('stage_cleaned'): fail()
                        stage_fd = stage(record, fd, source, origin)
                        if evidence(read(before_path, os.geteuid(), True)) != record['snapshot']: fail()
                        if record['result'] is None:
                            if current != record['before']: fail()
                            proposal(record, 'result', 'candidate', candidate_path, record['candidate'], stage_fd, source, origin)
                        if current == record['before']:
                            # before prepare publication
                            publish(record, fd, stage_fd, 'candidate', record['before'], record['result'])
                            # after prepare exchange
                        elif current != record['result']: fail()
                        elif evidence(read_at(stage_fd, 'candidate', uid)) != record['before']: fail()
                        if target(record, fd) != record['result']: fail()
                    elif phase == 'restore':
                        allowed = [value for value in (record['before'], record['result']) if value is not None]
                        if record.get('restore_result') is not None: allowed.append(record['restore_result'])
                        if current not in allowed: fail()
                        if not record.get('restored'):
                            if current == record['before']: record['restore_result'] = record['before']
                            else:
                                stage_fd = stage(record, fd, source, origin)
                                if record.get('restore_result') is None:
                                    proposal(record, 'restore_result', 'restore', before_path, record['snapshot'], stage_fd, source, origin)
                                if current != record['restore_result']:
                                    # before restore publication
                                    publish(record, fd, stage_fd, 'restore', current, record['restore_result'])
                                    # after restore exchange
                                elif evidence(read_at(stage_fd, 'restore', uid)) != record['result']: fail()
                            record['restored'] = True; save(origin / 'manifest.json', source)
                        if target(record, fd) != record['restore_result']: fail()
                    elif phase == 'cleanup':
                        if record['result'] is None or current != record['result']: fail()
                    else: fail()
                if data['finish'] and phase in ('restore', 'cleanup'):
                    for index, record in enumerate(source['records']):
                        if record['before'] is None: continue
                        fd = parent_fd(pathlib.Path(record['path']))
                        expected_target = record.get('restore_result') if phase == 'restore' else record['result']
                        if target(record, fd) != expected_target: fail()
                        if record.get('stage_intended') and not record.get('stage_cleaned'):
                            stage_fd = stage(record, fd, source, origin)
                            for name, key, snapshot_key, snapshot_path in (('candidate', 'result', 'candidate', origin / ('candidate-' + str(index))), ('restore', 'restore_result', 'snapshot', origin / ('before-' + str(index)))):
                                value = read_at(stage_fd, name, uid)
                                if value is None: continue
                                if record.get(key) is None:
                                    proposal(record, key, name, snapshot_path, record[snapshot_key], stage_fd, source, origin)
                                    value = read_at(stage_fd, name, uid)
                                allowed = [item for item in (record['before'], record['result'], record.get('restore_result')) if item is not None]
                                if evidence(value) not in allowed: fail()
                                os.unlink(name, dir_fd=stage_fd); os.fsync(stage_fd)
                            if set(os.listdir(stage_fd)) != {'owner.json'}: fail()
                            verify_stage(record, fd, stage_fd)
                            record['stage_cleaned'] = True; save(origin / 'manifest.json', source)
                            os.unlink('owner.json', dir_fd=stage_fd); os.fsync(stage_fd)
                            os.rmdir(record['stage_name'], dir_fd=stage_parent(record)); os.fsync(stage_parent(record))
                        elif record.get('stage_cleaned'):
                            try: stage_fd = os.open(record['stage_name'], os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=stage_parent(record))
                            except FileNotFoundError: stage_fd = None
                            if stage_fd is not None:
                                held.append(stage_fd); verify_stage(record, fd, stage_fd)
                                if set(os.listdir(stage_fd)) - {'owner.json'}: fail()
                                if os.listdir(stage_fd):
                                    marker = read_at(stage_fd, 'owner.json', os.geteuid(), True)
                                    if marker['contents'] != encoded([source['binding'], record['path']]): fail()
                                    os.unlink('owner.json', dir_fd=stage_fd)
                                os.rmdir(record['stage_name'], dir_fd=stage_parent(record)); os.fsync(stage_parent(record))
                        for prefix, expected in (('before-', record['snapshot']), ('candidate-', record['candidate'])):
                            name = prefix + str(index); path = origin / name; origin_fd = parent_fd(path)
                            current = evidence(read_at(origin_fd, name, os.geteuid(), True))
                            if name not in source['cleaned']:
                                if current != expected: fail()
                                source['cleaned'].append(name); save(origin / 'manifest.json', source)
                            elif current is not None and current != expected: fail()
                            if target(record, fd) != expected_target: fail()
                            if current is not None: os.unlink(name, dir_fd=origin_fd); os.fsync(origin_fd)
                for record, fd in opened:
                    expected = record.get('restore_result') if phase == 'restore' else record['result']
                    if target(record, fd) != expected: fail()
                if data['finish']: manifest['complete'] = True; save(root / 'manifest.json', manifest)
                identities = [[r['path'], r.get('chain'), None if (r.get('restore_result') if phase == 'restore' else r['result']) is None else (r.get('restore_result') if phase == 'restore' else r['result'])['identity']] for r in source['records']]
                artifact = {'created': True, 'protection_fingerprint': digest(encoded([os.geteuid(), 0o700, 0o600])), 'result_fingerprint': digest(encoded([binding, identities]))}
                snapshots = {'cache-' + str(i): str(origin / ('before-' + str(i))) for i, r in enumerate(source['records']) if r['before'] is not None}
                print(json.dumps(dict(binding, complete=manifest['complete'], targets=targets, snapshots=snapshots, artifacts={'serving': artifact}, result_fingerprint=digest(encoded([binding, targets, artifact])))))
            except Exception:
                print('{"conflict":"protected_evidence"}'); raise SystemExit(43)
            finally:
                for fd in reversed(held): os.close(fd)
            PYTHON;
    }
}
