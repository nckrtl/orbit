#!/usr/bin/env python3
"""Delete one reviewed child without following a parent or final symlink."""

import argparse
import errno
import os
import stat
import sys


def fail(message: str) -> None:
    raise RuntimeError(message)


def remove_directory(parent_fd: int, name: str) -> None:
    """Remove a directory tree using descriptor-relative, no-follow operations."""
    directory_fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent_fd)
    try:
        for entry in os.scandir(directory_fd):
            entry_name = entry.name
            target = os.stat(entry_name, dir_fd=directory_fd, follow_symlinks=False)
            if stat.S_ISLNK(target.st_mode):
                os.unlink(entry_name, dir_fd=directory_fd)
                continue
            if stat.S_ISDIR(target.st_mode):
                remove_directory(directory_fd, entry_name)
            elif stat.S_ISREG(target.st_mode):
                os.unlink(entry_name, dir_fd=directory_fd)
            else:
                fail("The host deletion target contains an unexpected type.")
    finally:
        os.close(directory_fd)
    os.rmdir(name, dir_fd=parent_fd)


def open_absolute_directory(path: str) -> int:
    """Open every absolute directory component without following links."""
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    descriptor = os.open("/", flags)
    try:
        for component in path.split("/"):
            if not component:
                continue
            child = os.open(component, flags, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
    except BaseException:
        os.close(descriptor)
        raise
    return descriptor


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--kind", choices=("source_paths", "manifests", "locks"), required=True)
    parser.add_argument("--root", required=True)
    parser.add_argument("--path", required=True)
    args = parser.parse_args()

    if not args.root.startswith("/") or not args.path or "\\" in args.path:
        fail("The host deletion path is unsafe.")
    components = args.path.split("/")
    if any(component in ("", ".", "..") for component in components):
        fail("The host deletion path contains traversal or empty components.")
    expected_mode = stat.S_IFDIR if args.kind == "source_paths" else stat.S_IFREG
    try:
        root_fd = open_absolute_directory(args.root)
    except OSError as error:
        fail(f"The host deletion safe root cannot be opened: {error.strerror}.")

    try:
        parent_fd = root_fd
        opened = []
        try:
            for component in components[:-1]:
                try:
                    child_fd = os.open(
                        component,
                        os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                        dir_fd=parent_fd,
                    )
                except OSError as error:
                    fail(f"The host deletion parent is unsafe: {error.strerror}.")
                opened.append(child_fd)
                parent_fd = child_fd
            final = components[-1]
            try:
                target = os.stat(final, dir_fd=parent_fd, follow_symlinks=False)
            except FileNotFoundError:
                fail("The host deletion target is missing.")
            except OSError as error:
                fail(f"The host deletion target cannot be read: {error.strerror}.")
            if stat.S_ISLNK(target.st_mode):
                fail("The host deletion target is a symbolic link.")
            if stat.S_IFMT(target.st_mode) != expected_mode:
                fail("The host deletion target has an unexpected type.")
            try:
                if expected_mode == stat.S_IFDIR:
                    remove_directory(parent_fd, final)
                else:
                    os.unlink(final, dir_fd=parent_fd)
            except FileNotFoundError:
                fail("The host deletion target is missing.")
            except OSError as error:
                fail(f"The exact host deletion failed: {error.strerror}.")
        finally:
            for descriptor in reversed(opened):
                os.close(descriptor)
    finally:
        os.close(root_fd)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError) as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(1)
