<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final class TransferGitSnapshotProgram
{
    public static function definitions(): string
    {
        return <<<'PYTHON'
            import base64
            import io
            import json
            import os
            import re
            import stat
            import subprocess
            import tarfile

            class TransferGitSnapshot:
                manifest_name = ".git/orbit-transfer-snapshot.json"
                pack_name = ".git/orbit-transfer-snapshot.pack"
                maximum_file = 16 * 1024 * 1024
                maximum_manifest = 96 * 1024 * 1024
                portable_files = {"index", "shallow", "info/exclude", "info/attributes", "info/sparse-checkout", "info/orbit-transfer-attributes"}
                normalized_config = {
                    "core.repositoryformatversion", "core.bare", "core.worktree", "core.hookspath",
                    "core.fsmonitor", "core.excludesfile", "core.attributesfile", "extensions.objectformat", "extensions.refstorage",
                    "extensions.worktreeconfig", "extensions.partialclone", "core.alternaterefscommand", "core.alternaterefsprefixes",
                }

                @staticmethod
                def require(condition):
                    if not condition:
                        raise ValueError("The captured Git snapshot is incomplete.")

                @staticmethod
                def git(checkout, arguments, **options):
                    environment = {**os.environ, "GIT_OPTIONAL_LOCKS": "0", "GIT_NO_REPLACE_OBJECTS": "1", "GIT_NO_LAZY_FETCH": "1"}
                    return subprocess.run(
                        ["git", "-c", "core.fsmonitor=false", *arguments], cwd="/proc/self/fd/" + str(checkout), pass_fds=(checkout,),
                        env=environment, check=True, stderr=subprocess.DEVNULL,
                        **({"stdout": subprocess.PIPE} | options),
                    ).stdout

                @classmethod
                def path(cls, checkout, name):
                    return os.fsdecode(cls.git(checkout, ["rev-parse", "--path-format=absolute", "--git-path", name]).removesuffix(b"\n"))

                @classmethod
                def contents(cls, path):
                    parent_path, name = os.path.split(path)
                    try:
                        parent = TransferSource.open_path(parent_path)
                    except FileNotFoundError:
                        return None
                    try:
                        try:
                            descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
                        except FileNotFoundError:
                            return None
                        try:
                            metadata = os.fstat(descriptor)
                            cls.require(stat.S_ISREG(metadata.st_mode) and metadata.st_uid == os.geteuid() and metadata.st_nlink == 1)
                            cls.require(metadata.st_size <= cls.maximum_file)
                            with os.fdopen(os.dup(descriptor), "rb") as stream:
                                data = stream.read(cls.maximum_file + 1)
                            cls.require(len(data) == metadata.st_size)
                            return base64.b64encode(data).decode("ascii")
                        finally:
                            os.close(descriptor)
                    finally:
                        os.close(parent)

                @classmethod
                def configuration(cls, checkout, scope):
                    raw = cls.git(checkout, ["config", scope, "--includes", "--null", "--list"])
                    cls.require(len(raw) <= cls.maximum_file)
                    result = []
                    for entry in raw.split(b"\0"):
                        if not entry:
                            continue
                        key, separator, value = entry.partition(b"\n")
                        result.append([key.decode("utf-8"), base64.b64encode(value if separator else b"true").decode("ascii")])
                    return result

                @staticmethod
                def config_key(key):
                    return isinstance(key, str) and re.fullmatch(r"[A-Za-z][A-Za-z0-9-]*\.(?:[^\0\r\n]+\.)?[A-Za-z][A-Za-z0-9-]*", key) is not None

                @classmethod
                def restore_config(cls, checkout, directory, metadata):
                    configuration = []
                    for key, value in metadata["config"]:
                        normalized = key.lower()
                        if normalized in cls.normalized_config or normalized.startswith(("include.", "includeif.")):
                            continue
                        if normalized.startswith("remote.") and normalized.endswith((".promisor", ".partialclonefilter")):
                            continue
                        configuration.append((key, base64.b64decode(value)))
                    captured_keys = {key for key, value in configuration}
                    defaults = [(key, base64.b64decode(value)) for key, value in cls.configuration(checkout, "--local") if key not in captured_keys]
                    if metadata["files"]["info/orbit-transfer-attributes"] is not None:
                        configuration.append(("core.attributesfile", os.fsencode(cls.path(checkout, "info/orbit-transfer-attributes"))))
                    quote = lambda value: b'"' + value.replace(b'\\', b'\\\\').replace(b'"', b'\\"').replace(b'\n', b'\\n').replace(b'\t', b'\\t').replace(b'\b', b'\\b') + b'"'
                    descriptor = os.open("config", os.O_WRONLY | os.O_TRUNC | os.O_NOFOLLOW, dir_fd=directory)
                    os.fchmod(descriptor, 0o600)
                    with os.fdopen(descriptor, "wb") as target:
                        for key, value in defaults + configuration:
                            cls.require(cls.config_key(key))
                            section, remainder = key.split(".", 1)
                            subsection, separator, name = remainder.rpartition(".")
                            target.write(b"[" + section.encode() + (b" " + quote(subsection.encode()) if separator else b"") + b"]\n\t" + (name if separator else remainder).encode() + b" = " + quote(value) + b"\n")
                    cls.configuration(checkout, "--local")

                @classmethod
                def index_objects(cls, checkout):
                    entries = cls.git(checkout, ["ls-files", "--stage", "-z"]).split(b"\0")
                    cls.require(all(entry.split(b" ", 1)[0] != b"160000" for entry in entries if entry))
                    identities = sorted({entry.split(b"\t", 1)[0].split(b" ")[1].decode("ascii") for entry in entries if entry})
                    return [identity for identity in identities if set(identity) != {"0"}]

                @classmethod
                def refs(cls, checkout):
                    return [line.decode().split("\0") for line in cls.git(checkout, ["for-each-ref", "--format=%(refname)%00%(objectname)%00%(symref)"]).splitlines()]

                @classmethod
                def read(cls, checkout):
                    head = cls.git(checkout, ["rev-parse", "--verify", "HEAD^{commit}"]).decode().strip()
                    symbolic_head = cls.git(checkout, ["rev-parse", "--symbolic-full-name", "HEAD"]).decode().strip()
                    cls.require(symbolic_head == "HEAD" or symbolic_head.startswith("refs/heads/"))
                    branch = None if symbolic_head == "HEAD" else symbolic_head.removeprefix("refs/heads/")
                    object_format = cls.git(checkout, ["rev-parse", "--show-object-format"]).decode().strip()
                    refs = cls.refs(checkout)
                    cls.require(branch is None or any(ref[0] == symbolic_head and ref[1] == head for ref in refs))
                    configuration = cls.configuration(checkout, "--local")
                    has_worktree_config = any(key.lower() == "extensions.worktreeconfig" for key, value in configuration)
                    if has_worktree_config and cls.git(checkout, ["config", "--local", "--includes", "--type=bool", "--get", "extensions.worktreeConfig"]).strip() == b"true":
                        configuration += cls.configuration(checkout, "--worktree")
                    files = {name: cls.contents(cls.path(checkout, name)) for name in sorted(cls.portable_files)}
                    files["info/orbit-transfer-attributes"] = None
                    for option, portable in (("core.excludesfile", "info/exclude"), ("core.attributesfile", "info/orbit-transfer-attributes")):
                        if not any(key.lower() == option for key, value in configuration):
                            continue
                        configured_path = os.fsdecode(cls.git(checkout, ["config", "--path", "--get", option]).removesuffix(b"\n"))
                        if not configured_path.startswith("/"):
                            source_path = os.fsdecode(cls.git(checkout, ["rev-parse", "--show-toplevel"]).removesuffix(b"\n"))
                            configured_path = os.path.normpath(os.path.join(source_path, configured_path))
                        configured = cls.contents(configured_path)
                        if configured is not None:
                            combined = base64.b64decode(configured)
                            if option == "core.excludesfile":
                                combined += b"\n" + base64.b64decode(files[portable] or "")
                            cls.require(len(combined) <= cls.maximum_file)
                            files[portable] = base64.b64encode(combined).decode("ascii")
                    shared = os.fsdecode(cls.git(checkout, ["rev-parse", "--path-format=absolute", "--shared-index-path"]).removesuffix(b"\n"))
                    if shared:
                        name = os.path.basename(shared)
                        cls.require(re.fullmatch(r"sharedindex\.[0-9a-f]{40}(?:[0-9a-f]{24})?", name) is not None)
                        files[name] = cls.contents(shared)
                        cls.require(files[name] is not None)
                    index_objects = cls.index_objects(checkout)
                    cls.require(files["index"] == cls.contents(cls.path(checkout, "index")))
                    return {
                        "head": head, "branch": branch, "object_format": object_format,
                        "refs": refs, "config": configuration, "files": files, "index_objects": index_objects,
                    }

                @classmethod
                def append(cls, checkout, archive, pack, snapshot, transfer_id, attempt):
                    identities = sorted({snapshot["head"], *[ref[1] for ref in snapshot["refs"]], *snapshot["index_objects"]})
                    os.ftruncate(pack, 0)
                    os.lseek(pack, 0, os.SEEK_SET)
                    cls.git(checkout, ["pack-objects", "--stdout", "--revs"], input=("\n".join(identities) + "\n").encode(), stdout=pack)
                    os.fsync(pack)
                    cls.require(cls.read(checkout) == snapshot)
                    manifest = {"version": 1, "transfer_id": transfer_id, "attempt": attempt, **snapshot}
                    data = json.dumps(manifest, separators=(",", ":")).encode()
                    cls.require(len(data) <= cls.maximum_manifest)
                    os.lseek(archive, 0, os.SEEK_SET)
                    with os.fdopen(os.dup(archive), "r+b") as target, tarfile.open(fileobj=target, mode="a") as bundle:
                        metadata = tarfile.TarInfo(cls.manifest_name)
                        metadata.mode, metadata.size = 0o600, len(data)
                        bundle.addfile(metadata, io.BytesIO(data))
                        metadata = tarfile.TarInfo(cls.pack_name)
                        metadata.mode, metadata.size = 0o600, os.fstat(pack).st_size
                        os.lseek(pack, 0, os.SEEK_SET)
                        with os.fdopen(os.dup(pack), "rb") as source:
                            bundle.addfile(metadata, source)
                    cls.require(cls.read(checkout) == snapshot)

                @classmethod
                def inspect(cls, archive, request):
                    os.lseek(archive, 0, os.SEEK_SET)
                    with os.fdopen(os.dup(archive), "rb") as source, tarfile.open(fileobj=source, mode="r:") as bundle:
                        metadata = None
                        seen = set()
                        for member in bundle:
                            cls.require(not member.name.startswith("/") and ".." not in member.name.split("/"))
                            name = member.name.removeprefix("./").rstrip("/")
                            cls.require(name == "." or all(part not in ("", ".", "..") for part in name.split("/")))
                            if name == ".git" or name.startswith(".git/"):
                                cls.require(name in (cls.manifest_name, cls.pack_name) and name not in seen and member.isreg())
                                seen.add(name)
                                if name == cls.manifest_name:
                                    cls.require(member.size <= cls.maximum_manifest)
                                    metadata = json.load(bundle.extractfile(member))
                            if member.islnk():
                                cls.require(not member.linkname.startswith("/") and ".." not in member.linkname.split("/"))
                        cls.require(seen == {cls.manifest_name, cls.pack_name})
                    cls.require(isinstance(metadata, dict) and set(metadata) == {"version", "transfer_id", "attempt", "head", "branch", "object_format", "refs", "config", "files", "index_objects"})
                    cls.require(metadata["version"] == 1 and metadata["transfer_id"] == request["transfer_id"] and metadata["attempt"] == request["id"])
                    cls.require(metadata["head"] == request["head"] and metadata["branch"] == (request["branch"] or None))
                    cls.require((metadata["branch"] is None) == request["detached"])
                    cls.require(metadata["object_format"] in ("sha1", "sha256"))
                    size = 40 if metadata["object_format"] == "sha1" else 64
                    valid_identity = lambda value: isinstance(value, str) and re.fullmatch("[0-9a-f]{" + str(size) + "}", value) is not None
                    cls.require(valid_identity(metadata["head"]))
                    cls.require(isinstance(metadata["refs"], list) and isinstance(metadata["config"], list) and isinstance(metadata["files"], dict))
                    cls.require(isinstance(metadata["index_objects"], list) and all(valid_identity(value) for value in metadata["index_objects"]))
                    for ref in metadata["refs"]:
                        cls.require(isinstance(ref, list) and len(ref) == 3 and isinstance(ref[0], str) and ref[0].startswith("refs/") and valid_identity(ref[1]))
                        cls.require(isinstance(ref[2], str) and (ref[2] == "" or ref[2].startswith("refs/")))
                    cls.require(len({ref[0] for ref in metadata["refs"]}) == len(metadata["refs"]))
                    cls.require(metadata["branch"] is None or any(ref[0] == "refs/heads/" + metadata["branch"] and ref[1] == metadata["head"] for ref in metadata["refs"]))
                    for pair in metadata["config"]:
                        cls.require(isinstance(pair, list) and len(pair) == 2 and all(isinstance(value, str) for value in pair))
                        cls.require(cls.config_key(pair[0]))
                        cls.require(b"\0" not in base64.b64decode(pair[1], validate=True))
                    cls.require(cls.portable_files.issubset(metadata["files"]) and len(metadata["files"]) <= len(cls.portable_files) + 1)
                    for name, value in metadata["files"].items():
                        cls.require(name in cls.portable_files or re.fullmatch(r"sharedindex\.[0-9a-f]{40}(?:[0-9a-f]{24})?", name) is not None)
                        cls.require(value is None or isinstance(value, str) and len(base64.b64decode(value, validate=True)) <= cls.maximum_file)
                    return metadata

                @classmethod
                def restore(cls, checkout, metadata):
                    cls.git(checkout, ["init", "--quiet", "--template=", "--object-format=" + metadata["object_format"]])
                    directory = os.open(".git", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=checkout)
                    try:
                        os.makedirs("/proc/self/fd/" + str(directory) + "/info", mode=0o755, exist_ok=True)
                        for name, value in metadata["files"].items():
                            if value is None:
                                continue
                            parent = directory
                            leaf = name
                            if name.startswith("info/"):
                                parent = os.open("info", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
                                leaf = name.removeprefix("info/")
                            try:
                                descriptor = os.open(leaf, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600, dir_fd=parent)
                                with os.fdopen(descriptor, "wb") as target:
                                    target.write(base64.b64decode(value, validate=True))
                            finally:
                                if parent != directory:
                                    os.close(parent)
                        pack = os.open("orbit-transfer-snapshot.pack", os.O_RDONLY | os.O_NOFOLLOW, dir_fd=directory)
                        try:
                            cls.git(checkout, ["index-pack", "--stdin", "--strict"], stdin=pack)
                        finally:
                            os.close(pack)
                        for ref, identity, symbolic in metadata["refs"]:
                            cls.git(checkout, ["check-ref-format", ref])
                            if not symbolic:
                                cls.git(checkout, ["update-ref", ref, identity])
                        for ref, identity, symbolic in metadata["refs"]:
                            if symbolic:
                                cls.git(checkout, ["check-ref-format", symbolic])
                                cls.git(checkout, ["symbolic-ref", ref, symbolic])
                        if metadata["branch"] is None:
                            cls.git(checkout, ["update-ref", "--no-deref", "HEAD", metadata["head"]])
                        else:
                            cls.git(checkout, ["symbolic-ref", "HEAD", "refs/heads/" + metadata["branch"]])
                        cls.restore_config(checkout, directory, metadata)
                        cls.require(cls.git(checkout, ["rev-parse", "--verify", "HEAD^{commit}"]).decode().strip() == metadata["head"])
                        cls.require(cls.refs(checkout) == metadata["refs"])
                        cls.require(cls.index_objects(checkout) == metadata["index_objects"])
                        if metadata["index_objects"]:
                            objects = cls.git(checkout, ["cat-file", "--batch-check=%(objectname) %(objecttype)"], input=("\n".join(metadata["index_objects"]) + "\n").encode())
                            cls.require(all(line.split(b" ")[-1] in (b"blob", b"tree", b"commit") for line in objects.splitlines()))
                        os.unlink("orbit-transfer-snapshot.json", dir_fd=directory)
                        os.unlink("orbit-transfer-snapshot.pack", dir_fd=directory)
                    finally:
                        os.close(directory)
            PYTHON;
    }
}
