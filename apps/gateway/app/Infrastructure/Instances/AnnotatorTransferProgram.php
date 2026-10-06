<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

/** Fixed Node programs for sealed, attempt-owned annotator publication and recovery. */
final readonly class AnnotatorTransferProgram
{
    private static function ownership(): string
    {
        return <<<'PY'
            import os, stat, sys, shutil
            from pathlib import Path

            class OwnershipError(RuntimeError):
                pass

            def report(kind, error, trace):
                print('OWNERSHIP_CONFLICT' if isinstance(error, OwnershipError) else 'INVALID_ARCHIVE_OR_PUBLICATION')
            sys.excepthook = report

            def exists(path):
                return os.path.lexists(path)

            def regular(path, uid, links=(1,)):
                metadata = os.lstat(path)
                if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != uid or metadata.st_nlink not in links or metadata.st_mode & 0o022:
                    raise OwnershipError('foreign receipt')

            def directory(path, uids):
                metadata = os.lstat(path)
                if not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid not in uids or metadata.st_mode & 0o022 or os.path.realpath(path) != str(path):
                    raise OwnershipError('foreign directory')

            def receipt_matches(path, owner, uid):
                sealed = path.name.endswith('.owner')
                regular(path, uid, (1, 2) if sealed else (1,))
                if path.read_bytes() != owner:
                    raise OwnershipError('foreign receipt')
                if path.stat().st_nlink == 2:
                    pending = Path(str(path) + '.pending')
                    regular(pending, uid, (2,))
                    if pending.stat().st_ino != path.stat().st_ino or pending.read_bytes() != owner:
                        raise OwnershipError('foreign receipt link')

            def final_matches(store, owner, uid):
                directory(store, (uid,))
                receipt_matches(store / '.orbit-transfer-owner', owner, uid)

            def prepare_parent(path, authority):
                missing = []
                ancestor = path
                while not exists(ancestor):
                    missing.append(ancestor)
                    ancestor = ancestor.parent
                directory(ancestor, (authority,))
                if ancestor.stat().st_mode & 0o022:
                    raise OwnershipError('unprotected parent')
                for child in reversed(missing):
                    child.mkdir(mode=0o755)
                directory(path, (authority,))
                if path.stat().st_mode & 0o022:
                    raise OwnershipError('unprotected parent')

            def sync_parent(path):
                fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
                try:
                    os.fsync(fd)
                finally:
                    os.close(fd)
            PY;
    }

    public static function restore(): string
    {
        return self::ownership()."\n".<<<'PY'
            import tarfile, ctypes, errno
            archive, store, staged, receipt = map(Path, sys.argv[1:5])
            owner = (sys.argv[5] + '\n').encode()
            uid, gid = map(int, sys.argv[6:8])
            authority = os.geteuid()
            if staged.parent != store.parent or receipt != Path(str(staged) + '.owner'):
                raise RuntimeError('invalid staging identity')
            regular(archive, uid)

            # Validate the complete manifest before creating any destination artifacts.
            fd = os.open(archive, os.O_RDONLY | os.O_NOFOLLOW)
            metadata = os.fstat(fd)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != uid or metadata.st_nlink != 1:
                os.close(fd)
                raise OwnershipError('foreign archive')
            with os.fdopen(fd, 'rb') as archive_file, tarfile.open(fileobj=archive_file, mode='r:') as bundle:
                if metadata.st_size < 1024 or metadata.st_size % 512:
                    raise RuntimeError('incomplete archive')
                archive_file.seek(-1024, os.SEEK_END)
                if archive_file.read(1024) != bytes(1024):
                    raise RuntimeError('incomplete archive')
                archive_file.seek(0)
                members = []
                names = {}
                for entry in bundle.getmembers():
                    name = entry.name
                    if name.startswith('./'):
                        name = name[2:]
                    if name in ('', '.') and entry.isdir():
                        if entry.uid != 0 or entry.gid != 0 or entry.mode & 0o7000 or entry.pax_headers:
                            raise RuntimeError('invalid archive root')
                        continue
                    parts = name.split('/')
                    if any(part in ('', '.', '..') for part in parts) or name.startswith('/') or '\\' in name:
                        raise RuntimeError('invalid archive path')
                    if name == '.orbit-transfer-owner' or name in names or entry.uid != 0 or entry.gid != 0:
                        raise RuntimeError('invalid archive ownership')
                    if entry.type not in (tarfile.DIRTYPE, tarfile.REGTYPE, tarfile.AREGTYPE) or entry.mode & 0o7000 or entry.pax_headers:
                        raise RuntimeError('invalid archive entry')
                    if entry.size < 0 or entry.offset_data + entry.size > metadata.st_size - 1024:
                        raise RuntimeError('invalid archive size')
                    names[name] = entry.isdir()
                    members.append((entry, parts))
                for entry, parts in members:
                    for depth in range(1, len(parts)):
                        ancestor = '/'.join(parts[:depth])
                        if ancestor in names and not names[ancestor]:
                            raise RuntimeError('invalid archive containment')

                prepare_parent(store.parent, authority)
                if exists(store):
                    final_matches(store, owner, uid)
                    receipt_matches(receipt, owner, authority)
                    archive.unlink()
                    raise SystemExit(0)

                pending = Path(str(receipt) + '.pending')
                if exists(receipt):
                    receipt_matches(receipt, owner, authority)
                else:
                    if exists(staged):
                        raise OwnershipError('foreign staging path')
                    if exists(pending):
                        regular(pending, authority)
                        if not owner.startswith(pending.read_bytes()):
                            raise RuntimeError('foreign pending receipt')
                        pending.unlink()
                    fd = os.open(pending, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
                    with os.fdopen(fd, 'wb') as output:
                        output.write(owner)
                        output.flush()
                        os.fsync(output.fileno())
                    os.link(pending, receipt, follow_symlinks=False)
                    pending.unlink()
                    sync_parent(store.parent)

                # A sealed external receipt precedes mkdir, including its crash window.
                if exists(staged):
                    directory(staged, (authority, uid))
                    shutil.rmtree(staged)
                if exists(pending):
                    regular(pending, authority, (1, 2))
                    if pending.read_bytes() != owner:
                        raise OwnershipError('foreign pending receipt')
                    pending.unlink()
                staged.mkdir(mode=0o700)
                marker = staged / '.orbit-transfer-owner'
                marker.write_bytes(owner)
                marker.chmod(0o600)
                os.chown(marker, uid, gid)
                for entry, parts in members:
                    destination = staged.joinpath(*parts)
                    parent = staged
                    for part in parts[:-1]:
                        parent = parent / part
                        parent.mkdir(mode=0o700, exist_ok=True)
                    if entry.isdir():
                        destination.mkdir(mode=0o700, exist_ok=True)
                    else:
                        source = bundle.extractfile(entry)
                        if source is None:
                            raise RuntimeError('invalid archive content')
                        fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
                        with source, os.fdopen(fd, 'wb') as output:
                            shutil.copyfileobj(source, output)
                            output.flush()
                            os.fsync(output.fileno())
                    os.chown(destination, uid, gid)
                for root, directories, _ in os.walk(staged):
                    for child in directories:
                        os.chown(Path(root) / child, uid, gid)
                os.chown(staged, uid, gid)
                sync_parent(staged)
                # Linux renameat2 publishes without ever replacing even an empty foreign directory.
                libc = ctypes.CDLL(None, use_errno=True)
                if libc.renameat2(-100, os.fsencode(staged), -100, os.fsencode(store), 1) != 0:
                    error = ctypes.get_errno()
                    if error == errno.EEXIST:
                        raise OwnershipError('foreign final publication')
                    raise OSError(error, 'store publication refused')
                sync_parent(store.parent)
                archive.unlink()
            PY;
    }

    public static function verify(): string
    {
        return self::ownership()."\n".<<<'PY'
            uid = int(sys.argv[1])
            if (len(sys.argv) - 2) % 2:
                raise OwnershipError('invalid store manifest')
            for offset in range(2, len(sys.argv), 2):
                store = Path(sys.argv[offset])
                owner = (sys.argv[offset + 1] + '\n').encode()
                directory(store.parent, (os.geteuid(),))
                final_matches(store, owner, uid)
            PY;
    }

    public static function cleanup(): string
    {
        return self::ownership()."\n".<<<'PY'
            store, staged, receipt = map(Path, sys.argv[1:4])
            owner = (sys.argv[4] + '\n').encode()
            uid = int(sys.argv[5])
            rollback = sys.argv[6] == 'rollback'
            authority = os.geteuid()
            directory(store.parent, (authority,))
            if staged.parent != store.parent or receipt != Path(str(staged) + '.owner'):
                raise RuntimeError('invalid staging identity')
            if not rollback or exists(store):
                final_matches(store, owner, uid)
            if exists(receipt):
                receipt_matches(receipt, owner, authority)
            if exists(staged):
                receipt_matches(receipt, owner, authority)
                directory(staged, (authority, uid))
            pending = Path(str(receipt) + '.pending')
            if exists(pending):
                regular(pending, authority, (1, 2))
                if not owner.startswith(pending.read_bytes()):
                    raise RuntimeError('foreign pending receipt')
            # Check every ownership boundary before removing any of this app's artifacts.
            if rollback and exists(store):
                shutil.rmtree(store)
            if exists(staged):
                shutil.rmtree(staged)
            for path in (receipt, pending):
                if exists(path):
                    path.unlink()
            sync_parent(store.parent)
            PY;
    }
}
