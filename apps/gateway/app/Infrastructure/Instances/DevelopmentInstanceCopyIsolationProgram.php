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


            def retargeted_link(path, source, target, dest):
                link = os.readlink(path)
                if link.startswith("/"):
                    updated = os.fsdecode(replace_path(os.fsencode(link), source, target))
                else:
                    updated = os.path.normpath(os.path.join(os.path.dirname(path), link))
                updated = os.path.abspath(updated)
                if not inside(dest, updated):
                    return None
                return updated


            def contained(dest, source, target, relative, follow_final):
                # An ancestor symlink is not opened. A retargeted target inside dest is
                # the contained directory. A target outside dest is external.
                current = os.path.abspath(dest)
                parts = relative.split("/")
                seen = set()
                for index, segment in enumerate(parts):
                    if segment in ("", ".", ".."):
                        raise Failure()
                    final = index == len(parts) - 1
                    nxt = os.path.join(current, segment)
                    if not inside(dest, nxt):
                        return nxt, "external"
                    try:
                        info = os.lstat(nxt)
                    except FileNotFoundError:
                        return nxt, "missing"
                    if not stat.S_ISLNK(info.st_mode):
                        if not final and not stat.S_ISDIR(info.st_mode):
                            return nxt, "missing"
                        current = nxt
                        continue
                    if final and not follow_final:
                        return nxt, "symlink"
                    updated = retargeted_link(nxt, source, target, dest)
                    guard = 0
                    while updated is not None:
                        guard += 1
                        if guard > 16 or updated in seen:
                            return nxt, "external"
                        seen.add(updated)
                        try:
                            info = os.lstat(updated)
                        except FileNotFoundError:
                            return updated, "missing"
                        if not stat.S_ISLNK(info.st_mode):
                            break
                        updated = retargeted_link(updated, source, target, dest)
                    if updated is None or not inside(dest, updated):
                        return nxt, "external"
                    if not final and not stat.S_ISDIR(info.st_mode):
                        return updated, "missing"
                    if final and follow_final and not stat.S_ISDIR(info.st_mode):
                        return updated, "external"
                    if final:
                        return updated, "real"
                    current = updated
                return current, "real"


            def isolate(dest, source, target, source_domain, target_domain):
                planned = []
                for relative in ("public/hot",):
                    planned.append(("unlink",) + contained(dest, source, target, relative, False))
                for relative in (
                    "storage/logs",
                    "storage/framework/cache",
                    "storage/framework/sessions",
                    "storage/framework/views",
                ):
                    planned.append(("clear",) + contained(dest, source, target, relative, False))
                for relative in ("node_modules/.vite", "node_modules/.cache"):
                    planned.append(("remove",) + contained(dest, source, target, relative, False))
                env_path, env_kind = contained(dest, source, target, ".env", False)
                cache_path, cache_kind = contained(dest, source, target, "bootstrap/cache", True)
                for action, path, kind in planned:
                    if kind == "external":
                        raise Failure()
                if env_kind == "external" or cache_kind == "external":
                    raise Failure()
                for action, path, kind in planned:
                    if kind == "missing":
                        continue
                    if kind == "symlink" or action == "unlink":
                        if kind == "symlink" or stat.S_ISREG(os.lstat(path).st_mode):
                            os.unlink(path)
                        continue
                    if action == "clear":
                        clear_directory(path)
                        continue
                    remove_tree(path)
                if env_kind == "real" and stat.S_ISREG(os.lstat(env_path).st_mode):
                    rewrite_file(env_path, source, target, source_domain, target_domain)
                if cache_kind != "real" or not stat.S_ISDIR(os.lstat(cache_path).st_mode):
                    return
                for dirpath, dirnames, filenames in os.walk(cache_path, followlinks=False):
                    dirnames[:] = [
                        name
                        for name in dirnames
                        if not os.path.islink(os.path.join(dirpath, name))
                    ]
                    for name in filenames:
                        candidate = os.path.join(dirpath, name)
                        if os.path.islink(candidate):
                            continue
                        rewrite_file(
                            candidate,
                            source,
                            target,
                            source_domain,
                            target_domain,
                        )


            def clear_directory(path):
                if not os.path.lexists(path):
                    return
                if os.path.islink(path):
                    os.unlink(path)
                    return
                if not os.path.isdir(path):
                    os.unlink(path)
                    return
                for entry in list(os.scandir(path)):
                    if entry.name == ".gitignore":
                        continue
                    if entry.is_symlink() or not entry.is_dir(follow_symlinks=False):
                        remove_tree(entry.path)
                        continue
                    clear_directory(entry.path)
                    if os.listdir(entry.path) == []:
                        os.rmdir(entry.path)


            def remove_tree(path):
                if not os.path.lexists(path):
                    return
                if os.path.islink(path) or not os.path.isdir(path):
                    os.unlink(path)
                    return
                for entry in os.scandir(path):
                    remove_tree(entry.path)
                os.rmdir(path)


            def rewrite_file(path, source, target, source_domain, target_domain):
                if os.path.islink(path) or not os.path.isfile(path):
                    return
                original = read_rewrite(path)
                updated = replace_domain(
                    replace_path(original, source, target),
                    source_domain,
                    target_domain,
                )
                if updated == original:
                    return
                temporary = path + ".orbit-rewrite"
                if os.path.lexists(temporary):
                    os.unlink(temporary)
                mode = os.lstat(path).st_mode & 0o777
                descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
                try:
                    write_all(descriptor, updated)
                    os.fsync(descriptor)
                finally:
                    os.close(descriptor)
                os.replace(temporary, path)


            def read_rewrite(path):
                # Read the whole file. A short read would drop the tail or leave a later
                # source path in place. Above the limit, fail before replacing anything.
                limit = 64 * 1024 * 1024
                info = os.lstat(path)
                if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode) or info.st_size > limit:
                    raise Failure()
                descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
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
