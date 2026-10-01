<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

/**
 * Node program that snapshots SQLite, resets runtime files, and rewrites source names.
 *
 * The backup is the transfer seeder's single-step sqlite3 backup: a mode=ro
 * connection, PRAGMA query_only, then backup(pages=-1).
 */
final class DevelopmentInstanceCopyIsolationProgram
{
    public static function script(): string
    {
        $script = <<<'PYTHON'
            import base64
            import os
            import sqlite3
            import stat
            import sys
            import urllib.parse

            class Unsafe(Exception):
                pass


            class Failure(Exception):
                pass


            HEADER = b"SQLite format 3\x00"
            SKIP = {".git", "vendor", "node_modules"}
            PATH_CONTINUES = set(b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-")
            HOST = set(b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.-")


            def main():
                try:
                    run()
                except Unsafe:
                    print("unsafe")
                except Exception:
                    print("failed", file=sys.stderr)
                    raise SystemExit(1)


            def run():
                mode = sys.argv[1]
                if mode == "scan":
                    source = sys.argv[2]
                    assert_directory(source)
                    scan(source)
                    print("ready")
                    return
                if mode == "environment":
                    read_environment(sys.argv[2])
                    return
                if mode != "prepare":
                    raise Failure()
                source, dest, source_domain, target_domain = sys.argv[2:6]
                assert_directory(source)
                assert_directory(dest)
                if os.path.realpath(source) == os.path.realpath(dest):
                    raise Failure()
                for relative in scan(source):
                    backup(source, dest, relative)
                source_path = source.encode()
                dest_path = dest.encode()
                isolate(
                    dest,
                    source_path,
                    dest_path,
                    source_domain.encode(),
                    target_domain.encode(),
                )
                retarget(dest, source_path, dest_path)
                print("ready")


            def assert_directory(path):
                if not path.startswith("/") or "\0" in path or path.endswith("/"):
                    raise Failure()
                if os.path.islink(path) or not os.path.isdir(path):
                    raise Failure()


            def scan(root):
                found = []
                for path in walk(root):
                    if os.path.islink(path):
                        if symlink_is_sqlite(path):
                            raise Unsafe()
                        continue
                    info = os.lstat(path)
                    if not stat.S_ISREG(info.st_mode):
                        continue
                    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
                    try:
                        header = os.read(descriptor, 16)
                    finally:
                        os.close(descriptor)
                    if header != HEADER:
                        continue
                    relative = os.path.relpath(path, root)
                    if relative.startswith(".."):
                        raise Failure()
                    found.append(relative)
                return found


            def walk(root):
                for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
                    dirnames[:] = [
                        name
                        for name in dirnames
                        if name not in SKIP and not os.path.islink(os.path.join(dirpath, name))
                    ]
                    for name in filenames:
                        yield os.path.join(dirpath, name)


            def symlink_is_sqlite(path):
                # Classification only. The snapshot never reads through a symlink.
                try:
                    descriptor = os.open(path, os.O_RDONLY)
                except OSError:
                    return False
                try:
                    return os.read(descriptor, 16) == HEADER
                finally:
                    os.close(descriptor)


            def backup(source_root, dest_root, relative):
                source_file = child(source_root, relative)
                dest_file = child(dest_root, relative)
                if os.path.islink(source_file):
                    raise Unsafe()
                descriptor = os.open(source_file, os.O_RDONLY | os.O_NOFOLLOW)
                os.close(descriptor)
                parent = os.path.dirname(dest_file)
                if os.path.islink(parent) or not os.path.isdir(parent):
                    raise Failure()
                temporary = dest_file + ".orbit-snapshot"
                if os.path.lexists(temporary):
                    os.unlink(temporary)
                source_uri = "file:" + urllib.parse.quote(source_file, safe="/") + "?mode=ro"
                source = sqlite3.connect(source_uri, uri=True, timeout=5.0)
                try:
                    source.execute("PRAGMA query_only = ON")
                    if os.path.lexists(dest_file) and os.path.islink(dest_file):
                        os.unlink(dest_file)
                    mode = 0o644
                    if os.path.isfile(dest_file) and not os.path.islink(dest_file):
                        mode = os.stat(dest_file).st_mode & 0o777
                    destination = sqlite3.connect(temporary)
                    try:
                        source.backup(destination, pages=-1)
                    finally:
                        destination.close()
                    os.chmod(temporary, mode)
                    os.replace(temporary, dest_file)
                finally:
                    source.close()
                    if os.path.lexists(temporary):
                        os.unlink(temporary)
                for suffix in ("-wal", "-shm"):
                    sibling = dest_file + suffix
                    if not os.path.lexists(sibling):
                        continue
                    if os.path.islink(sibling) or os.path.isfile(sibling):
                        os.unlink(sibling)
                        continue
                    raise Failure()


            def child(root, relative):
                if relative.startswith("/") or relative.startswith(".."):
                    raise Failure()
                current = root
                segments = relative.split("/")
                for index, segment in enumerate(segments):
                    if segment in ("", ".", ".."):
                        raise Failure()
                    current = os.path.join(current, segment)
                    if index < len(segments) - 1 and os.path.islink(current):
                        raise Failure()
                return current


            def place(root, relative):
                # Walk without following. A symlink ancestor is not the reset path, so callers
                # must not open, delete, or rewrite through it.
                current = root
                parts = relative.split("/")
                for index, segment in enumerate(parts):
                    if segment in ("", ".", ".."):
                        raise Failure()
                    current = os.path.join(current, segment)
                    final = index == len(parts) - 1
                    if os.path.islink(current):
                        return current, "symlink", final
                    if not os.path.lexists(current):
                        return current, "missing", final
                return current, "real", True


            def inside(root, path):
                root_b = os.fsencode(os.path.abspath(root))
                path_b = os.fsencode(os.path.abspath(path))
                if path_b == root_b:
                    return True
                return path_b.startswith(root_b + b"/")


            def open_root(path):
                return os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)


            def close_fds(fds):
                for fd in fds:
                    os.close(fd)


            def open_chain(root_fd, parts):
                # Every component is opened with O_NOFOLLOW from the previous directory fd.
                # A symlink ancestor cannot be followed, because no full path is used.
                current = root_fd
                owned = []
                for name in parts:
                    try:
                        nxt = os.open(
                            name,
                            os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                            dir_fd=current,
                        )
                    except OSError:
                        close_fds(owned)
                        return None
                    owned.append(nxt)
                    current = nxt
                return current, owned


            def list_fd(fd):
                return os.listdir("/proc/self/fd/" + str(fd))


            def lexical_parts(dest, absolute):
                absolute = os.path.abspath(absolute)
                if not inside(dest, absolute):
                    return None
                relative = os.path.relpath(absolute, os.path.abspath(dest))
                if relative == ".":
                    return []
                if relative.startswith(".."):
                    return None
                parts = relative.split("/")
                if any(part in ("", ".", "..") for part in parts):
                    return None
                return parts


            def link_parts(dest, source, target, current_parts, link):
                if link.startswith("/"):
                    updated = os.fsdecode(replace_path(os.fsencode(link), source, target))
                else:
                    base = os.path.abspath(dest)
                    if current_parts:
                        base = os.path.join(base, *current_parts)
                    updated = os.path.normpath(os.path.join(base, link))
                return lexical_parts(dest, updated)


            def resolve_parts(dest, root_fd, source, target, parts, follow_final, seen, depth):
                if depth > 16:
                    return None, "external"
                current = []
                for index, segment in enumerate(parts):
                    if segment in ("", ".", ".."):
                        raise Failure()
                    opened = open_chain(root_fd, current)
                    if opened is None:
                        return None, "external"
                    parent, owned = opened
                    try:
                        try:
                            info = os.lstat(segment, dir_fd=parent)
                        except FileNotFoundError:
                            return None, "missing"
                        final = index == len(parts) - 1
                        if not stat.S_ISLNK(info.st_mode):
                            if not final and not stat.S_ISDIR(info.st_mode):
                                return None, "missing"
                            current.append(segment)
                            continue
                        if final and not follow_final:
                            return current + [segment], "symlink"
                        ident = (info.st_dev, info.st_ino)
                        if ident in seen:
                            return None, "external"
                        seen.add(ident)
                        target_parts = link_parts(
                            dest,
                            source,
                            target,
                            current,
                            os.readlink(segment, dir_fd=parent),
                        )
                        if target_parts is None:
                            return None, "external"
                        resolved, kind = resolve_parts(
                            dest,
                            root_fd,
                            source,
                            target,
                            target_parts,
                            True,
                            seen,
                            depth + 1,
                        )
                        if kind == "missing":
                            return None, "missing"
                        if kind != "real":
                            return None, "external"
                        if not final or follow_final:
                            probe = open_chain(root_fd, resolved)
                            if probe is None:
                                return None, "external"
                            close_fds(probe[1])
                        if not final:
                            current = list(resolved)
                            continue
                        return resolved, "real"
                    finally:
                        close_fds(owned)
                return current, "real"


            def contained(dest, root_fd, source, target, relative, follow_final):
                parts = relative.split("/")
                if any(part in ("", ".", "..") for part in parts):
                    raise Failure()
                return resolve_parts(dest, root_fd, source, target, parts, follow_final, set(), 0)


            def isolate(dest, source, target, source_domain, target_domain):
                root_fd = open_root(dest)
                try:
                    planned = []
                    for relative in ("public/hot",):
                        planned.append(("unlink",) + contained(dest, root_fd, source, target, relative, False))
                    for relative in (
                        "storage/logs",
                        "storage/framework/cache",
                        "storage/framework/sessions",
                        "storage/framework/views",
                    ):
                        planned.append(("clear",) + contained(dest, root_fd, source, target, relative, False))
                    for relative in ("node_modules/.vite", "node_modules/.cache"):
                        planned.append(("remove",) + contained(dest, root_fd, source, target, relative, False))
                    env_parts, env_kind = contained(dest, root_fd, source, target, ".env", False)
                    cache_parts, cache_kind = contained(dest, root_fd, source, target, "bootstrap/cache", True)
                    for action, parts, kind in planned:
                        if kind == "external":
                            raise Failure()
                    if env_kind == "external" or cache_kind == "external":
                        raise Failure()
                    for action, parts, kind in planned:
                        if kind == "missing":
                            continue
                        if kind == "symlink" or action == "unlink":
                            unlink_parts(root_fd, parts)
                            continue
                        if action == "clear":
                            clear_parts(root_fd, parts)
                            continue
                        remove_parts(root_fd, parts)
                    if env_kind == "real" and env_parts == [".env"]:
                        rewrite_name(root_fd, ".env", source, target, source_domain, target_domain)
                    if cache_kind != "real":
                        return
                    opened = open_chain(root_fd, cache_parts)
                    if opened is None:
                        raise Failure()
                    cache_fd, owned = opened
                    try:
                        rewrite_tree(cache_fd, source, target, source_domain, target_domain)
                    finally:
                        close_fds(owned)
                finally:
                    os.close(root_fd)


            def unlink_parts(root_fd, parts):
                if not parts:
                    raise Failure()
                opened = open_chain(root_fd, parts[:-1])
                if opened is None:
                    raise Failure()
                parent, owned = opened
                try:
                    info = os.lstat(parts[-1], dir_fd=parent)
                    if stat.S_ISLNK(info.st_mode) or stat.S_ISREG(info.st_mode):
                        os.unlink(parts[-1], dir_fd=parent)
                finally:
                    close_fds(owned)


            def clear_parts(root_fd, parts):
                if not parts:
                    raise Failure()
                opened = open_chain(root_fd, parts[:-1])
                if opened is None:
                    raise Failure()
                parent, owned = opened
                try:
                    info = os.lstat(parts[-1], dir_fd=parent)
                    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
                        os.unlink(parts[-1], dir_fd=parent)
                        return
                    fd = os.open(
                        parts[-1],
                        os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                        dir_fd=parent,
                    )
                    try:
                        clear_fd(fd)
                    finally:
                        os.close(fd)
                finally:
                    close_fds(owned)


            def clear_fd(fd):
                for name in list_fd(fd):
                    if name == ".gitignore":
                        continue
                    info = os.lstat(name, dir_fd=fd)
                    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
                        os.unlink(name, dir_fd=fd)
                        continue
                    child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
                    try:
                        clear_fd(child)
                        empty = list_fd(child) == []
                    finally:
                        os.close(child)
                    if empty:
                        os.rmdir(name, dir_fd=fd)


            def remove_parts(root_fd, parts):
                if not parts:
                    raise Failure()
                opened = open_chain(root_fd, parts[:-1])
                if opened is None:
                    raise Failure()
                parent, owned = opened
                try:
                    info = os.lstat(parts[-1], dir_fd=parent)
                    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
                        os.unlink(parts[-1], dir_fd=parent)
                        return
                    fd = os.open(
                        parts[-1],
                        os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                        dir_fd=parent,
                    )
                    try:
                        remove_fd(fd)
                    finally:
                        os.close(fd)
                    os.rmdir(parts[-1], dir_fd=parent)
                finally:
                    close_fds(owned)


            def remove_fd(fd):
                for name in list_fd(fd):
                    info = os.lstat(name, dir_fd=fd)
                    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
                        os.unlink(name, dir_fd=fd)
                        continue
                    child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
                    try:
                        remove_fd(child)
                    finally:
                        os.close(child)
                    os.rmdir(name, dir_fd=fd)


            def rewrite_tree(fd, source, target, source_domain, target_domain):
                for name in list_fd(fd):
                    info = os.lstat(name, dir_fd=fd)
                    if stat.S_ISLNK(info.st_mode):
                        continue
                    if stat.S_ISDIR(info.st_mode):
                        child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
                        try:
                            rewrite_tree(child, source, target, source_domain, target_domain)
                        finally:
                            os.close(child)
                        continue
                    if stat.S_ISREG(info.st_mode):
                        rewrite_name(fd, name, source, target, source_domain, target_domain)


            def rewrite_name(dir_fd, name, source, target, source_domain, target_domain):
                original = read_name(dir_fd, name)
                if original is None:
                    return
                updated = replace_domain(
                    replace_path(original, source, target),
                    source_domain,
                    target_domain,
                )
                if updated == original:
                    return
                temporary = name + ".orbit-rewrite"
                try:
                    os.lstat(temporary, dir_fd=dir_fd)
                except FileNotFoundError:
                    pass
                else:
                    os.unlink(temporary, dir_fd=dir_fd)
                mode = os.lstat(name, dir_fd=dir_fd).st_mode & 0o777
                descriptor = os.open(
                    temporary,
                    os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                    mode,
                    dir_fd=dir_fd,
                )
                try:
                    write_all(descriptor, updated)
                    os.fsync(descriptor)
                finally:
                    os.close(descriptor)
                os.rename(temporary, name, src_dir_fd=dir_fd, dst_dir_fd=dir_fd)


            def read_name(dir_fd, name):
                # Read the whole file. A short read would drop the tail or leave a later
                # source path in place. Above the limit, fail before replacing anything.
                limit = 64 * 1024 * 1024
                info = os.lstat(name, dir_fd=dir_fd)
                if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode):
                    return None
                if info.st_size > limit:
                    raise Failure()
                descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=dir_fd)
                try:
                    chunks = []
                    total = 0
                    while True:
                        chunk = os.read(descriptor, 1024 * 1024)
                        if chunk == b"":
                            return b"".join(chunks)
                        total += len(chunk)
                        if total > limit:
                            raise Failure()
                        chunks.append(chunk)
                finally:
                    os.close(descriptor)


            def write_all(descriptor, payload):
                view = memoryview(payload)
                while len(view) > 0:
                    written = os.write(descriptor, view)
                    if written <= 0:
                        raise Failure()
                    view = view[written:]

            def retarget(dest, source, target):
                for dirpath, dirnames, filenames in os.walk(dest, followlinks=False):
                    for name in list(dirnames) + list(filenames):
                        path = os.path.join(dirpath, name)
                        if not os.path.islink(path):
                            continue
                        link = os.readlink(path)
                        if not link.startswith("/"):
                            continue
                        raw = os.fsencode(link)
                        updated = replace_path(raw, source, target)
                        if updated == raw:
                            continue
                        os.unlink(path)
                        os.symlink(os.fsdecode(updated), path)
                    dirnames[:] = [
                        name
                        for name in dirnames
                        if not os.path.islink(os.path.join(dirpath, name))
                    ]


            def replace_path(value, source, target):
                if source == b"" or source == target:
                    return value
                return replace_at(value, source, target, PATH_CONTINUES, False)


            def replace_domain(value, source, target):
                if source == b"" or target == b"" or source == target:
                    return value
                return replace_at(value, source, target, HOST, True)


            def replace_at(value, source, target, boundary, both_sides):
                result = bytearray()
                offset = 0
                length = len(source)
                while True:
                    position = value.find(source, offset)
                    if position < 0:
                        result += value[offset:]
                        return bytes(result)
                    nxt = position + length
                    after = nxt < len(value) and value[nxt] in boundary
                    if both_sides:
                        before = position > 0 and value[position - 1] in boundary
                        matched = not before and not after
                    else:
                        matched = not after
                    if not matched:
                        result += value[offset : position + 1]
                        offset = position + 1
                        continue
                    result += value[offset:position]
                    result += target
                    offset = nxt


            def read_environment(root):
                assert_directory(root)
                path = os.path.join(root, ".env")
                if not os.path.lexists(path):
                    print("missing")
                    return
                if os.path.islink(path) or not os.path.isfile(path):
                    print("unsafe")
                    return
                with open(path, "rb") as handle:
                    data = handle.read(1048577)
                if len(data) > 1048576:
                    raise Failure()
                print(base64.b64encode(data).decode("ascii"))


            if __name__ == "__main__":
                main()
            PYTHON;

        return self::dedent($script);
    }

    private static function dedent(string $script): string
    {
        $lines = preg_split('/\R/', $script) ?: [];
        $indents = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            preg_match('/\A[ ]*/', $line, $match);
            $indents[] = strlen($match[0] ?? '');
        }

        $strip = $indents === [] ? 0 : min($indents);
        $prefix = str_repeat(' ', $strip);
        $dedented = array_map(
            static fn (string $line): string => str_starts_with($line, $prefix) ? substr($line, $strip) : $line,
            $lines,
        );

        return rtrim(implode("\n", $dedented), "\n")."\n";
    }
}
