<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

/** Fixed stdin-only protocol. No caller program, environment values in argv, or application execution. */
final readonly class AppProjectionEnvironmentProgram
{
    public static function script(): string
    {
        return <<<'PYTHON'
            import base64, fcntl, hashlib, json, os, pathlib, pwd, re, stat, sys
            class Conflict(Exception):
                def __init__(self, code, reason): self.code, self.reason = code, reason
            def fail(reason='identity', code=43): raise Conflict(code, reason)
            def digest(value): return hashlib.sha256(value).hexdigest()
            def encoded(value): return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()
            def sync(fd): os.fsync(fd)
            held = []
            def directory(path, protected=False):
                if not isinstance(path, str) or not path.startswith('/') or os.path.normpath(path) != path: fail('path', 42)
                fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
                held.append(fd)
                for part in path.split('/')[1:]:
                    fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                    held.append(fd)
                meta = os.fstat(fd)
                if protected and (meta.st_uid != os.geteuid() or stat.S_IMODE(meta.st_mode) != 0o700): fail('protection')
                return fd
            def identity(meta):
                return [meta.st_dev, meta.st_ino, meta.st_uid, meta.st_gid, stat.S_IMODE(meta.st_mode), meta.st_size]
            def read(fd, name, uid, protected=False):
                try: f = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
                except FileNotFoundError: return None
                except OSError: fail('protection' if protected else 'file', 43 if protected else 42)
                try:
                    m = os.fstat(f)
                    if not stat.S_ISREG(m.st_mode) or m.st_nlink != 1 or m.st_uid != uid or m.st_size > 1048576: fail('file', 42)
                    if protected and stat.S_IMODE(m.st_mode) != 0o600: fail('protection')
                    if m.st_mode & 0o022 or not m.st_mode & 0o400: fail('permissions', 42)
                    contents = b''
                    while True:
                        chunk = os.read(f, 65536)
                        if not chunk: break
                        contents += chunk
                        if len(contents) > 1048576: fail('size', 42)
                    if identity(os.fstat(f)) != identity(m): fail('changed')
                    attributes = {name: base64.b64encode(os.getxattr(f, name)).decode() for name in sorted(os.listxattr(f))}
                    return {'identity': identity(m), 'digest': digest(contents), 'attributes': attributes, 'contents': contents}
                finally: os.close(f)
            def evidence(record):
                return None if record is None else {k: record[k] for k in ('identity', 'digest', 'attributes')}
            def create(fd, name, contents, uid, gid, mode=0o600, attributes=None):
                f = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=fd)
                try:
                    os.fchown(f, uid, gid)
                    os.fchmod(f, mode)
                    if attributes is not None:
                        for attribute in os.listxattr(f):
                            if attribute not in attributes: os.removexattr(f, attribute)
                        for attribute, value in attributes.items(): os.setxattr(f, attribute, base64.b64decode(value))
                    with os.fdopen(os.dup(f), 'wb') as out: out.write(contents); out.flush()
                    sync(f)
                finally: os.close(f)
                sync(fd)
                return evidence(read(fd, name, uid))
            def save(fd, name, value):
                temporary = name + '.next'
                # A leftover journal write is not a target artifact; only unlink after ownership checks.
                previous = read(fd, temporary, os.geteuid(), True)
                if previous is not None: os.unlink(temporary, dir_fd=fd)
                create(fd, temporary, encoded(value), os.geteuid(), os.getegid())
                os.replace(temporary, name, src_dir_fd=fd, dst_dir_fd=fd); sync(fd)
            def load(fd, name):
                value = read(fd, name, os.geteuid(), True)
                if value is None: fail('missing_receipt')
                return json.loads(value['contents'])
            def check_file(fd, name, expected, uid):
                current = read(fd, name, uid)
                if evidence(current) != expected: fail('foreign_replacement')
                return current
            def app_directory(path, checkout, uid, candidate=False):
                for value in (path, checkout):
                    if not isinstance(value, str) or not value.startswith('/') or os.path.normpath(value) != value: fail('path', 42)
                if checkout == '/' or (path != checkout and not path.startswith(checkout + '/')): fail('containment', 42)
                fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
                held.append(fd)
                chain, current_path = [], ''
                for part in path.split('/')[1:]:
                    current_path += '/' + part
                    try: child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                    except OSError: fail('directory', 42)
                    os.close(fd)
                    fd = child
                    held[-1] = fd
                    meta = os.fstat(fd)
                    within_checkout = current_path == checkout or current_path.startswith(checkout + '/')
                    # A root-owned sticky ancestor (e.g. /tmp) can contain an exclusive checkout.
                    # No writable shared directory is trusted at or beneath the checkout boundary.
                    sticky_ancestor = not within_checkout and meta.st_uid == 0 and bool(meta.st_mode & stat.S_ISVTX)
                    if meta.st_uid not in (0, os.geteuid(), uid) or (meta.st_mode & 0o022 and not sticky_ancestor): fail('directory', 42)
                    attributes = {name: base64.b64encode(os.getxattr(fd, name)).decode() for name in sorted(os.listxattr(fd))}
                    chain.append({'path': current_path, 'identity': identity(meta)[:5], 'attributes': attributes})
                required_mode = 0o700 if candidate else 0o500
                if meta.st_uid != uid or meta.st_mode & required_mode != required_mode: fail('directory', 42)
                return fd, chain
            def validate_target(item, uid):
                if item['old'] == item['candidate']: fail('same_path', 42)
                source, source_chain = app_directory(item['old'], item['old_checkout'], uid)
                destination, destination_chain = app_directory(item['candidate'], item['candidate_checkout'], uid, True)
                return source, destination, source_chain, destination_chain
            def current_directory(record, source=False):
                if (record['uid'], record['gid']) != (uid, gid): fail('binding')
                path = record['source_directory'] if source else record['directory']
                checkout = record['old_checkout'] if source else record['candidate_checkout']
                expected = record['source_chain'] if source else record['directory_chain']
                fd, chain = app_directory(path, checkout, record['uid'], not source)
                if chain != expected: fail('directory_changed')
                return fd
            def check_absence(record):
                check_file(current_directory(record), record['name'], None, record['uid'])
                check_file(current_directory(record, True), record['name'], None, record['uid'])
            def restore_current(fd, record):
                current = evidence(read(fd, record['name'], record['uid']))
                if record['restored']:
                    allowed = [record.get('restore_result')]
                else:
                    allowed = [record['before']]
                    if record['result'] is not None: allowed.append(record['result'])
                    if 'restore_result' in record: allowed.append(record['restore_result'])
                if current not in allowed: fail('foreign_replacement')
                return current
            def verify_records(records, operation):
                for record in records:
                    fd = current_directory(record)
                    current_directory(record, True)
                    if not record['mutates']: check_absence(record)
                    elif operation == 'restore':
                        if not record['restored']: fail('phase')
                        check_file(fd, record['name'], record.get('restore_result'), record['uid'])
                    else:
                        check_file(fd, record['name'], record['result'], record['uid'])
                        if operation == 'prepare': check_file(current_directory(record, True), record['name'], record['source_identity'], record['uid'])
            try:
                data = json.load(sys.stdin)
                action = data['action']
                if action not in ('prepare', 'recover', 'restore', 'cleanup'): fail('action')
                binding = data['binding']
                for key in ('step_id', 'projection_id', 'receipt_id'):
                    if not re.fullmatch(r'[a-f0-9-]{36}', binding[key]): fail('binding')
                for key in ('plan_digest', 'intent_digest'):
                    if not re.fullmatch(r'[a-f0-9]{64}', binding[key]): fail('binding')
                if type(binding['instance_id']) is not int or binding['instance_id'] < 1 or not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', binding['app']): fail('binding')
                owner = binding['owner']
                if not ((type(owner) is int and owner > 0) or (isinstance(owner, str) and re.fullmatch(r'[a-f0-9-]{36}', owner))): fail('binding')
                if binding.get('execution_user', data['user']) != data['user']: fail('binding')
                uid, gid = pwd.getpwnam(data['user']).pw_uid, pwd.getpwnam(data['user']).pw_gid
                base_path = '/var/lib/orbit/app-environments'
                try: parent = directory(os.path.dirname(base_path))
                except FileNotFoundError:
                    ancestor = directory(os.path.dirname(os.path.dirname(base_path)))
                    ancestor_meta = os.fstat(ancestor)
                    if ancestor_meta.st_uid != os.geteuid() or ancestor_meta.st_mode & 0o022: fail('protection')
                    os.mkdir(os.path.basename(os.path.dirname(base_path)), 0o755, dir_fd=ancestor); sync(ancestor)
                    parent = directory(os.path.dirname(base_path))
                parent_meta = os.fstat(parent)
                if parent_meta.st_uid != os.geteuid() or parent_meta.st_mode & 0o022: fail('protection')
                try: os.mkdir(os.path.basename(base_path), 0o700, dir_fd=parent); sync(parent)
                except FileExistsError: pass
                base = directory(base_path, True)
                fcntl.flock(base, fcntl.LOCK_EX)
                receipt_id = binding['receipt_id']
                fresh = False
                try: os.mkdir(receipt_id, 0o700, dir_fd=base); sync(base); fresh = True
                except FileExistsError: pass
                root = directory(base_path + '/' + receipt_id, True)
                if fresh and action == 'recover': fail('missing_receipt')
                if fresh:
                    if action == 'prepare':
                        records, occupied = [], set()
                        targets = data['contexts']
                        if not targets: fail('targets')
                        expected_targets = {}
                        for item in targets:
                            for key in ('old', 'candidate', 'old_checkout', 'candidate_checkout', 'release'):
                                expected_targets[item['scope'] + '.' + key] = item[key]
                        if expected_targets != data['targets']: fail('targets')
                        for item in targets:
                            if any(item['candidate'] == other['old'] for other in targets): fail('source_overlap', 42)
                        # Validate every source and destination before any live environment write.
                        for item in targets:
                            source, destination, source_chain, destination_chain = validate_target(item, uid)
                            for name in ('.env', '.env.testing'):
                                path = item['candidate'] + '/' + name
                                if path in occupied: fail('duplicate', 42)
                                occupied.add(path)
                                old = read(source, name, uid)
                                before = read(destination, name, uid)
                                desired = (data['files'] or {}).get(item['scope'] + ':' + name)
                                m = os.fstat(destination)
                                index = str(len(records))
                                record = {'directory': item['candidate'], 'directory_identity': identity(m)[:5], 'directory_chain': destination_chain, 'candidate_checkout': item['candidate_checkout'], 'old_checkout': item['old_checkout'], 'name': name, 'before': evidence(before), 'source_directory': item['old'], 'source_chain': source_chain, 'source_identity': evidence(old), 'uid': uid, 'gid': gid, 'mutates': desired is not None, 'pending': '.orbit-env-' + receipt_id + '-' + index, 'result': None, 'restored': False, 'candidate_snapshot': None, 'snapshot': None}
                                if desired is None:
                                    if old is not None or before is not None: fail('unconfigured_file', 42)
                                    records.append(record)
                                    continue
                                contents = base64.b64decode(desired['candidate'], validate=True)
                                old_contents = base64.b64decode(desired['old'], validate=True)
                                if len(contents) > 1048576: fail('size', 42)
                                if old is None or old['digest'] != digest(old_contents): fail('source', 42)
                                if before is not None and before['digest'] != digest(contents): fail('destination', 42)
                                fs = os.fstatvfs(destination)
                                if fs.f_flag & os.ST_RDONLY or fs.f_bavail * fs.f_frsize < len(contents) * 3 + 1048576: fail('capacity', 42)
                                record['desired_digest'] = digest(contents)
                                protected_fs = os.fstatvfs(root)
                                if protected_fs.f_flag & os.ST_RDONLY or protected_fs.f_bavail * protected_fs.f_frsize < len(contents) * 3 + 1048576: fail('capacity', 42)
                                record['candidate_snapshot'] = create(root, 'candidate-' + index, contents, os.geteuid(), os.getegid())
                                if before is not None: record['snapshot'] = create(root, 'before-' + index, before['contents'], os.geteuid(), os.getegid())
                                else: record['snapshot'] = None
                                records.append(record)
                        manifest = {'binding': binding, 'targets': data['targets'], 'records': records, 'complete': False, 'state': 'preparing', 'snapshot_cleaned': []}
                    else:
                        source_id = data['source_receipt']
                        if not re.fullmatch(r'[a-f0-9-]{36}', source_id) or source_id == receipt_id: fail('binding')
                        origin = directory(base_path + '/' + source_id, True)
                        original = load(origin, 'manifest.json')
                        if original['binding'] != data['source_binding']: fail('binding')
                        manifest = {'binding': binding, 'targets': data['targets'], 'source_binding': data['source_binding'], 'source_receipt': source_id, 'complete': False, 'state': action}
                    save(root, 'manifest.json', manifest)
                else:
                    manifest = load(root, 'manifest.json')
                    if manifest['binding'] != binding or manifest['targets'] != data['targets']: fail('binding')
                    if 'source_binding' in manifest and (manifest['source_binding'] != data.get('source_binding') or manifest['source_receipt'] != data.get('source_receipt')): fail('binding')
                operation = manifest['state']
                if action != 'recover' and ((action == 'prepare' and operation not in ('preparing', 'prepared')) or (action in ('restore', 'cleanup') and operation != action)): fail('phase')
                if operation in ('preparing', 'prepared'):
                    records = manifest['records']
                    for index, record in enumerate(records):
                        fd = current_directory(record)
                        source_fd = current_directory(record, True)
                        check_file(source_fd, record['name'], record['source_identity'], uid)
                        if not record['mutates']:
                            check_absence(record)
                            continue
                        check_file(root, 'candidate-' + str(index), record['candidate_snapshot'], os.geteuid())
                        if record['snapshot'] is not None: check_file(root, 'before-' + str(index), record['snapshot'], os.geteuid())
                        if record['result'] is None:
                            check_file(fd, record['name'], record['before'], uid)
                            contents = check_file(root, 'candidate-' + str(index), record['candidate_snapshot'], os.geteuid())['contents']
                            record['result'] = create(fd, record['pending'], contents, uid, gid)
                            save(root, 'manifest.json', manifest)
                        target = evidence(read(fd, record['name'], uid))
                        pending = evidence(read(fd, record['pending'], uid))
                        if target == record['before'] and pending == record['result']:
                            current_directory(record)
                            check_file(fd, record['name'], record['before'], uid)
                            os.replace(record['pending'], record['name'], src_dir_fd=fd, dst_dir_fd=fd); sync(fd)
                        elif target != record['result'] or pending is not None: fail('foreign_replacement')
                        check_file(fd, record['name'], record['result'], uid)
                    manifest['complete'], manifest['state'] = True, 'prepared'
                    verify_records(records, 'prepare')
                    save(root, 'manifest.json', manifest)
                elif operation in ('restore', 'cleanup'):
                    origin = directory(base_path + '/' + manifest['source_receipt'], True)
                    original = load(origin, 'manifest.json')
                    if original['binding'] != data['source_binding']: fail('binding')
                    records = original['records']
                    if operation == 'cleanup' and original['state'] not in ('prepared', 'cleaned'): fail('phase')
                    # Validate the entire set before removing/restoring any target.
                    for index, record in enumerate(records):
                        fd = current_directory(record)
                        current_directory(record, True)
                        if not record['mutates']:
                            check_absence(record)
                            continue
                        if operation == 'cleanup': check_file(fd, record['name'], record['result'], uid)
                        else:
                            restore_current(fd, record)
                            if not record['restored'] and record['snapshot'] is not None: check_file(origin, 'before-' + str(index), record['snapshot'], os.geteuid())
                        pending = evidence(read(fd, record['pending'], uid))
                        if pending is not None and pending != record['result']: fail('foreign_replacement')
                    # Revalidate recorded ownership again; the set-wide validation is not mutation authority.
                    for index, record in enumerate(records):
                        fd = current_directory(record)
                        current_directory(record, True)
                        if not record['mutates']:
                            check_absence(record)
                            continue
                        if operation == 'cleanup': check_file(fd, record['name'], record['result'], uid)
                        elif record['restored']: restore_current(fd, record)
                        if operation == 'restore' and not record['restored']:
                            current = restore_current(fd, record)
                            if current != record['before']:
                                if record['before'] is None:
                                    current_directory(record)
                                    current = restore_current(fd, record)
                                    if current is not None:
                                        check_file(fd, record['name'], record['result'], uid)
                                        os.unlink(record['name'], dir_fd=fd); sync(fd)
                                else:
                                    before = check_file(origin, 'before-' + str(index), record['snapshot'], os.geteuid())['contents']
                                    restore_name = record['pending'] + '-restore'
                                    if record.get('restore_result') is None:
                                        meta = record['before']['identity']
                                        record['restore_result'] = create(fd, restore_name, before, meta[2], meta[3], meta[4], record['before']['attributes'])
                                        save(origin, 'manifest.json', original)
                                    if current != record['restore_result']:
                                        check_file(fd, restore_name, record['restore_result'], uid)
                                        current_directory(record)
                                        current = restore_current(fd, record)
                                        if current != record['restore_result']:
                                            check_file(fd, record['name'], current, uid)
                                            os.replace(restore_name, record['name'], src_dir_fd=fd, dst_dir_fd=fd); sync(fd)
                            else: record['restore_result'] = record['before']
                            record['restored'] = True
                            save(origin, 'manifest.json', original)
                        pending = read(fd, record['pending'], uid)
                        if pending is not None:
                            check_file(fd, record['pending'], record['result'], uid)
                            os.unlink(record['pending'], dir_fd=fd); sync(fd)
                        if operation in ('restore', 'cleanup'):
                            for prefix, expected in (('candidate-', record['candidate_snapshot']), ('before-', record['snapshot'])):
                                if expected is None: continue
                                name = prefix + str(index)
                                if name in original['snapshot_cleaned']: continue
                                check_file(origin, name, expected, os.geteuid())
                                # Durable removal intent permits an absent snapshot after an interrupted unlink.
                                original['snapshot_cleaned'].append(name)
                                save(origin, 'manifest.json', original)
                                os.unlink(name, dir_fd=origin); sync(origin)
                    if operation in ('restore', 'cleanup'):
                        for name in original['snapshot_cleaned']:
                            value = read(origin, name, os.geteuid(), True)
                            if value is not None:
                                index = int(name.split('-')[-1])
                                expected = records[index]['snapshot' if name.startswith('before-') else 'candidate_snapshot']
                                check_file(origin, name, expected, os.geteuid())
                                os.unlink(name, dir_fd=origin); sync(origin)
                        original['state'] = 'cleaned' if operation == 'cleanup' else 'restored'; save(origin, 'manifest.json', original)
                    verify_records(records, operation)
                    manifest['complete'] = True; save(root, 'manifest.json', manifest)
                else: fail('phase')
                # Public evidence contains no bytes or per-key information (including low-entropy value hashes).
                records = manifest.get('records', [])
                artifacts = {'receipt': {'created': fresh, 'protection_fingerprint': digest(encoded([os.geteuid(), 0o700, 0o600])), 'result_fingerprint': digest(encoded(binding))}}
                # Stable output across a lost acknowledgment; created means this receipt's owned artifact.
                artifacts['receipt']['created'] = True
                for index, record in enumerate(records):
                    if not record['mutates']:
                        artifacts['environment-' + str(index)] = {'created': False, 'protection_fingerprint': digest(encoded([record['directory_chain'], record['source_chain']])), 'result_fingerprint': digest(encoded([record['name'], 'absent', record['directory_identity']]))}
                    elif record['result'] is not None:
                        artifacts['environment-' + str(index)] = {'created': record['before'] is None, 'protection_fingerprint': digest(encoded([record['directory_identity'], record['result']['identity'][2:5]])), 'result_fingerprint': digest(encoded(record['result']['identity']))}
                snapshots = {'environment-' + str(index): base_path + '/' + receipt_id + '/before-' + str(index) for index, record in enumerate(records) if record['snapshot'] is not None}
                output = dict(binding, complete=manifest['complete'], targets=data['targets'], snapshots=snapshots, artifacts=artifacts, result_fingerprint=digest(encoded({'binding': binding, 'targets': data['targets'], 'artifacts': artifacts})))
                print(json.dumps(output))
            except Conflict as error:
                # A definite preflight rejection records the known snapshots and proves no target was written.
                # An interrupted or damaged receipt still fails closed; it is never recaptured.
                if locals().get('fresh', False) and data.get('action') == 'prepare' and 'records' in locals() and 'manifest' not in locals():
                    try:
                        save(root, 'manifest.json', {'binding': binding, 'targets': data['targets'], 'records': records, 'complete': False, 'state': 'rejected', 'reason': error.reason, 'snapshot_cleaned': []})
                    except Exception:
                        print('{"conflict":"unsafe_or_damaged"}'); raise SystemExit(43)
                code = error.code if locals().get('fresh', False) and data.get('action') == 'prepare' else 43
                print(json.dumps({'conflict': error.reason})); raise SystemExit(code)
            except Exception:
                print('{"conflict":"unsafe_or_damaged"}'); raise SystemExit(43)
            finally:
                for fd in reversed(held): os.close(fd)
            PYTHON;
    }
}
