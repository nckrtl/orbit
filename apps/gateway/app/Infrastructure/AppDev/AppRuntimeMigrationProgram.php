<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

final readonly class AppRuntimeMigrationProgram
{
    public static function script(): string
    {
        return <<<'PYTHON'
            import base64, hashlib, json, os, pathlib, pwd, re, shutil, stat, subprocess, sys, tempfile
            data = json.load(sys.stdin)
            identifier, action = data['id'], data['action']
            account = pwd.getpwnam(data['user'])
            data['uid'], data['gid'] = account.pw_uid, account.pw_gid
            if not re.fullmatch(r'[a-f0-9-]{36}', identifier): raise SystemExit(40)
            root_uid = os.geteuid()
            base = pathlib.Path('/var/lib/orbit/runtime-migrations')
            base.mkdir(parents=True, exist_ok=True, mode=0o700)
            if base.resolve() != base or base.stat().st_uid != root_uid or base.stat().st_mode & 0o077: raise SystemExit(40)
            root = base / identifier
            root.mkdir(mode=0o700, exist_ok=True)
            if root.is_symlink() or root.stat().st_uid != root_uid: raise SystemExit(40)
            os.chmod(root, 0o700)
            manifest_path = root / 'manifest.json'
            def run(arguments):
                subprocess.run(arguments, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            def active(unit):
                return subprocess.run(['systemctl', 'is-active', '--quiet', unit], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
            def atomic(path, contents, mode=0o600):
                descriptor, temporary = tempfile.mkstemp(prefix='.orbit-migration-', dir=path.parent)
                try:
                    with os.fdopen(descriptor, 'wb') as handle:
                        handle.write(contents)
                        handle.flush()
                        os.fsync(handle.fileno())
                    os.chmod(temporary, mode)
                    os.replace(temporary, path)
                    directory = os.open(path.parent, os.O_DIRECTORY)
                    try: os.fsync(directory)
                    finally: os.close(directory)
                finally:
                    if os.path.exists(temporary): os.unlink(temporary)
            def owned_file(path, tag):
                if path.is_symlink() or not path.is_file() or path.stat().st_uid != root_uid: raise SystemExit(41)
                lines = path.read_text().splitlines()
                expected = tag.splitlines()
                if any(lines.count(marker) != 1 for marker in expected): raise SystemExit(41)
                if tag.startswith('# Orbit Instance '):
                    for prefix in ('# Orbit Instance ', '# Orbit App '):
                        if [line for line in lines if line.startswith(prefix)] != [line for line in expected if line.startswith(prefix)]: raise SystemExit(41)
            if not manifest_path.exists():
                if action in ('rollback', 'cleanup'):
                    shutil.rmtree(root)
                    print('{}')
                    raise SystemExit(0)
                if action != 'prepare': raise SystemExit(41)
                records = []
                for index, item in enumerate(data['files']):
                    path = pathlib.Path(item['path'])
                    if path.parent.resolve() != path.parent: raise SystemExit(41)
                    if path.parent.exists() and (path.parent.stat().st_uid != root_uid or path.parent.stat().st_mode & 0o022): raise SystemExit(41)
                    record = {'path': str(path), 'tag': item['tag'], 'exists': path.exists(), 'backup': str(index), 'desired_digest': hashlib.sha256(base64.b64decode(item['contents'])).hexdigest()}
                    if path.exists():
                        owned_file(path, item['tag'])
                        record.update(mode=stat.S_IMODE(path.stat().st_mode), uid=path.stat().st_uid, gid=path.stat().st_gid)
                        atomic(root / str(index), path.read_bytes())
                    records.append(record)
                retired = []
                for index, item in enumerate(data.get('retired_files', [])):
                    path = pathlib.Path(item['path'])
                    if path.parent.resolve() != path.parent or path.is_symlink(): raise SystemExit(41)
                    record = dict(item, exists=path.exists())
                    if path.exists():
                        owned_file(path, item['tag'])
                        record.update(digest=hashlib.sha256(path.read_bytes()).hexdigest(), backup='retired-' + str(index))
                        atomic(root / record['backup'], path.read_bytes())
                    retired.append(record)
                caddy = pathlib.Path('/etc/caddy/Caddyfile')
                caddy_record = {'exists': caddy.exists(), 'link': os.readlink(caddy) if caddy.is_symlink() else None}
                if caddy_record['exists']:
                    if not caddy.is_file() or caddy.stat().st_uid != root_uid: raise SystemExit(41)
                    if caddy_record['link'] and not re.fullmatch(r'/etc/caddy/orbit-versions/[a-zA-Z0-9_.-]+/Caddyfile', caddy_record['link']): raise SystemExit(41)
                    atomic(root / 'Caddyfile', caddy.read_bytes(), 0o600)
                    caddy_record.update(mode=stat.S_IMODE(caddy.stat().st_mode), gid=caddy.stat().st_gid)
                stores = []
                for item in data['stores']:
                    source, target = pathlib.Path(item['old']), pathlib.Path(item['new'])
                    if source.parent.resolve() != source.parent or target.parent.resolve() != target.parent: raise SystemExit(42)
                    if source.is_symlink() or target.is_symlink() or target.exists(): raise SystemExit(42)
                    if source.exists():
                        if not source.is_dir() or source.stat().st_uid != data['uid']: raise SystemExit(42)
                        for entry in [source, *source.rglob('*')]:
                            if entry.is_symlink() or not (entry.is_file() or entry.is_dir()) or entry.stat().st_uid != data['uid']: raise SystemExit(42)
                        marker = source / '.orbit-runtime-migration'
                        if marker.exists() and marker.read_text() != identifier: raise SystemExit(42)
                    stores.append(dict(item, existed=source.exists()))
                states = {unit: active(unit) for unit in data['units']}
                for unit, running in states.items():
                    if running and not any(pathlib.Path(record['path']).name == unit and record['exists'] for record in records): raise SystemExit(41)
                manifest = {'plan_digest': data['plan_digest'], 'files': records, 'units': states, 'caddy': caddy_record, 'caddy_active': active('caddy.service'), 'stores': stores, 'retired_files': retired, 'activated': False}
                atomic(manifest_path, json.dumps(manifest).encode())
            if manifest_path.is_symlink() or manifest_path.stat().st_uid != root_uid: raise SystemExit(41)
            manifest = json.loads(manifest_path.read_text())
            if manifest['plan_digest'] != data['plan_digest']: raise SystemExit(41)
            if action == 'prepare':
                if len(data['files']) != len(manifest['files']): raise SystemExit(41)
                for item, recorded in zip(data['files'], manifest['files']):
                    if item['path'] != recorded['path'] or item['tag'] != recorded['tag'] or hashlib.sha256(base64.b64decode(item['contents'])).hexdigest() != recorded['desired_digest']: raise SystemExit(41)
                for index, item in enumerate(data['files']):
                    atomic(root / ('candidate-' + str(index)), base64.b64decode(item['contents']))
                candidates = [str(root / ('candidate-' + str(index))) for index, item in enumerate(data['files']) if item['path'].endswith('.service')]
                # Verify service files with their real .service suffix; no service is started here.
                for index, item in enumerate(data['files']):
                    if item['path'].endswith('.service'):
                        candidate = root / pathlib.Path(item['path']).name
                        atomic(candidate, base64.b64decode(item['contents']))
                        run(['systemd-analyze', 'verify', str(candidate)])
                print(json.dumps({'running': manifest['units']}))
            elif action == 'activate':
                if manifest['activated']:
                    for item in manifest['files']:
                        path = pathlib.Path(item['path'])
                        owned_file(path, item['tag'])
                        if hashlib.sha256(path.read_bytes()).hexdigest() != item['desired_digest']: raise SystemExit(41)
                    print('{}')
                    raise SystemExit(0)
                for unit, running in manifest['units'].items():
                    if running: run(['systemctl', 'stop', unit])
                for index, item in enumerate(manifest['stores']):
                    source, target = pathlib.Path(item['old']), pathlib.Path(item['new'])
                    if source.exists():
                        if source.is_symlink() or target.exists() or source.stat().st_uid != data['uid']: raise SystemExit(42)
                        atomic(source / '.orbit-runtime-migration', identifier.encode())
                        os.rename(source, target)
                    elif not item['existed'] and not target.exists():
                        if target.is_symlink(): raise SystemExit(42)
                        target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
                        if target.parent.resolve() != target.parent: raise SystemExit(42)
                        pending = root / ('empty-store-' + str(index))
                        if pending.exists(): shutil.rmtree(pending)
                        pending.mkdir(mode=0o700)
                        os.chown(pending, data['uid'], data['gid'])
                        atomic(pending / '.orbit-runtime-migration', identifier.encode())
                        os.rename(pending, target)
                    if (target / '.orbit-runtime-migration').read_text() != identifier: raise SystemExit(42)
                for index, item in enumerate(manifest['files']):
                    path = pathlib.Path(item['path'])
                    if path.exists(): owned_file(path, item['tag'])
                    candidate = root / ('candidate-' + str(index))
                    path.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
                    if path.parent.resolve() != path.parent or path.parent.stat().st_uid != root_uid or path.parent.stat().st_mode & 0o022: raise SystemExit(41)
                    if candidate.exists(): atomic(path, candidate.read_bytes())
                run(['systemctl', 'daemon-reload'])
                for unit, running in manifest['units'].items():
                    if running: run(['systemctl', 'start', unit])
                manifest['activated'] = True
                atomic(manifest_path, json.dumps(manifest).encode())
                print('{}')
            elif action == 'rollback':
                for unit, running in manifest['units'].items():
                    if running: run(['systemctl', 'stop', unit])
                for item in reversed(manifest['stores']):
                    source, target = pathlib.Path(item['old']), pathlib.Path(item['new'])
                    if target.exists():
                        if source.exists() or (target / '.orbit-runtime-migration').read_text() != identifier: raise SystemExit(42)
                        os.rename(target, source)
                    marker = source / '.orbit-runtime-migration'
                    if marker.exists() and marker.read_text() == identifier: marker.unlink()
                for item in manifest['files']:
                    path = pathlib.Path(item['path'])
                    if path.exists(): owned_file(path, item['tag'])
                    if item['exists']:
                        atomic(path, (root / item['backup']).read_bytes(), item['mode'])
                        os.chown(path, item['uid'], item['gid'])
                    elif path.exists(): path.unlink()
                caddy, record = pathlib.Path('/etc/caddy/Caddyfile'), manifest['caddy']
                if record['exists']:
                    if record['link']:
                        target = pathlib.Path(record['link'])
                        target.parent.mkdir(parents=True, exist_ok=True)
                        if target.parent.resolve() != target.parent: raise SystemExit(41)
                        atomic(target, (root / 'Caddyfile').read_bytes(), record['mode'])
                        os.chown(target, root_uid, record['gid'])
                        link = caddy.with_name('.migration-link-' + identifier)
                        if link.exists() or link.is_symlink(): link.unlink()
                        link.symlink_to(target)
                        os.replace(link, caddy)
                    else:
                        if caddy.is_symlink(): caddy.unlink()
                        atomic(caddy, (root / 'Caddyfile').read_bytes(), record['mode'])
                        os.chown(caddy, root_uid, record['gid'])
                    run(['caddy', 'validate', '--config', str(caddy), '--adapter', 'caddyfile'])
                    if manifest['caddy_active']: run(['systemctl', 'reload-or-restart', 'caddy.service'])
                elif caddy.exists() or caddy.is_symlink(): caddy.unlink()
                if not manifest['caddy_active']: run(['systemctl', 'stop', 'caddy.service'])
                run(['systemctl', 'daemon-reload'])
                for unit, running in manifest['units'].items():
                    if running: run(['systemctl', 'start', unit])
                manifest['activated'] = False
                atomic(manifest_path, json.dumps(manifest).encode())
                print('{}')
            elif action == 'cleanup':
                if not manifest['activated']: raise SystemExit(41)
                for item in manifest.get('retired_files', []):
                    path = pathlib.Path(item['path'])
                    if path.is_symlink(): raise SystemExit(41)
                    if not path.exists(): continue
                    owned_file(path, item['tag'])
                    if not item['exists'] or hashlib.sha256(path.read_bytes()).hexdigest() != item['digest']: raise SystemExit(41)
                    # Check all unit fragments and drop-ins, including non-Orbit and stopped units.
                    # A retained operation may still own a reference even when its service is down.
                    for directory in ['/etc/systemd/system', '/run/systemd/system', '/usr/lib/systemd/system']:
                        unit_root = pathlib.Path(directory)
                        if not unit_root.exists(): continue
                        for fragment in unit_root.rglob('*'):
                            if fragment.suffix not in ('.service', '.conf'): continue
                            if fragment.is_file() and str(path) in fragment.read_text(): raise SystemExit(43)
                for item in manifest['files']:
                    path = pathlib.Path(item['path'])
                    owned_file(path, item['tag'])
                    if hashlib.sha256(path.read_bytes()).hexdigest() != item['desired_digest']: raise SystemExit(41)
                for item in manifest['stores']:
                    source, target = pathlib.Path(item['old']), pathlib.Path(item['new'])
                    if source.exists(): raise SystemExit(42)
                    marker = target / '.orbit-runtime-migration'
                    if marker.exists():
                        if marker.read_text() != identifier: raise SystemExit(42)
                        marker.unlink()
                for item in manifest.get('retired_files', []):
                    path = pathlib.Path(item['path'])
                    if path.exists(): path.unlink()
                # Only this UUID's private snapshots/candidates are deleted, never a live store.
                shutil.rmtree(root)
                print('{}')
            else: raise SystemExit(40)
            PYTHON;
    }
}
