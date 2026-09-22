<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final class TransferArchiveProgram
{
    public static function script(): string
    {
        return <<<'PYTHON'
            import ctypes
            import fcntl
            import json
            import os
            import pwd
            import stat
            import subprocess
            import sys

            initial_umask = os.umask(0o077)
            mode = sys.argv[1]
            request = json.load(sys.stdin)
            placement = request["placement"]
            attempt = request["id"]
            side = request["side"]
            root_path = placement["private_root"]
            workspace_name = attempt + ".work"
            claim_name = attempt + ".claimed"
            state_name = attempt + ".json"
            bundle_name = attempt + ".bundle"
            artifact_names = {"archive": "archive.tar", "bundle": bundle_name}

            def identity(metadata):
                return str(metadata.st_dev) + ":" + str(metadata.st_ino)

            def require(condition):
                if not condition:
                    raise ValueError("Transfer archive ownership is unconfirmed.")

            def metadata_at(parent, name):
                try:
                    return os.stat(name, dir_fd=parent, follow_symlinks=False)
                except FileNotFoundError:
                    return None

            def owned(metadata, directory=False):
                require(metadata is not None)
                require(metadata.st_uid == os.geteuid())
                require(stat.S_ISDIR(metadata.st_mode) if directory else stat.S_ISREG(metadata.st_mode))
                require(stat.S_IMODE(metadata.st_mode) == (0o700 if directory else 0o600))
                if not directory:
                    require(metadata.st_nlink == 1)

            def open_root():
                require(pwd.getpwnam(placement["execution_user"]).pw_uid == os.geteuid())
                parts = root_path.split("/")
                require(parts[0] == "" and parts[-2:] == [".orbit", "transfer-archives"])
                require(all(part not in ("", ".", "..") for part in parts[1:]))
                descriptor = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
                for position, part in enumerate(parts[1:], start=1):
                    if position >= len(parts) - 2:
                        try:
                            os.mkdir(part, 0o700, dir_fd=descriptor)
                        except FileExistsError:
                            pass
                    following = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=descriptor)
                    os.close(descriptor)
                    descriptor = following
                    if position >= len(parts) - 2:
                        owned(os.fstat(descriptor), directory=True)
                return descriptor

            def read_state(root):
                metadata = metadata_at(root, state_name)
                if metadata is None:
                    return None
                owned(metadata)
                descriptor = os.open(state_name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=root)
                try:
                    require(identity(os.fstat(descriptor)) == identity(metadata))
                    require(metadata.st_size <= 8192)
                    value = json.loads(os.read(descriptor, 8193))
                finally:
                    os.close(descriptor)
                require(set(value) == {"version", "id", "transfer_id", "side", "root", "phase", "receipt"})
                require(value["version"] == 1 and value["id"] == attempt)
                require(value["transfer_id"] == request["transfer_id"] and value["side"] == side)
                require(value["root"] == identity(os.fstat(root)))
                require(value["phase"] in ("creating", "ready", "claiming", "claimed", "cleaned"))
                return value

            def save_state(root, value):
                candidate = attempt + ".next"
                descriptor = os.open(candidate, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=root)
                try:
                    with os.fdopen(descriptor, "wb", closefd=False) as stream:
                        stream.write(json.dumps(value, separators=(",", ":")).encode())
                        stream.flush()
                        os.fsync(descriptor)
                    os.replace(candidate, state_name, src_dir_fd=root, dst_dir_fd=root)
                    os.fsync(root)
                finally:
                    os.close(descriptor)
                    if metadata_at(root, candidate) is not None:
                        os.unlink(candidate, dir_fd=root)

            def new_state(root):
                return {
                    "version": 1, "id": attempt, "transfer_id": request["transfer_id"],
                    "side": side, "root": identity(os.fstat(root)), "phase": "creating", "receipt": None,
                }

            def check_receipt(root, receipt):
                require(isinstance(receipt, dict) and set(receipt) == {"root", "workspace", "archive", "bundle"})
                require(receipt["root"] == identity(os.fstat(root)))
                expected = placement["receipt"]
                if expected is not None:
                    require(receipt == expected)

            def open_workspace(root, name, receipt, allow_missing=False):
                descriptor = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=root)
                try:
                    metadata = os.fstat(descriptor)
                    owned(metadata, directory=True)
                    require(identity(metadata) == receipt["workspace"])
                    present = set(os.listdir(descriptor))
                    require(present.issubset(set(artifact_names.values())))
                    for key, artifact in artifact_names.items():
                        metadata = metadata_at(descriptor, artifact)
                        if metadata is None and allow_missing:
                            continue
                        owned(metadata)
                        require(identity(metadata) == receipt[key])
                    return descriptor
                except BaseException:
                    os.close(descriptor)
                    raise

            def rename_without_replacement(root, source, destination):
                library = ctypes.CDLL(None, use_errno=True)
                rename = library.renameat2
                rename.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
                rename.restype = ctypes.c_int
                if rename(root, source.encode(), root, destination.encode(), 1) != 0:
                    raise OSError(ctypes.get_errno(), "Archive claim was refused.")

            def prepare(root, state):
                require(state is None and placement["receipt"] is None)
                require(metadata_at(root, workspace_name) is None and metadata_at(root, claim_name) is None)
                state = new_state(root)
                save_state(root, state)
                os.mkdir(workspace_name, 0o700, dir_fd=root)
                workspace = os.open(workspace_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=root)
                try:
                    receipt = {"root": identity(os.fstat(root)), "workspace": identity(os.fstat(workspace))}
                    for key, name in artifact_names.items():
                        descriptor = os.open(name, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=workspace)
                        receipt[key] = identity(os.fstat(descriptor))
                        os.close(descriptor)
                    os.fsync(workspace)
                    state.update(phase="ready", receipt=receipt)
                    save_state(root, state)
                    return receipt
                finally:
                    os.close(workspace)

            def cleanup(root, state):
                if state is None:
                    require(placement["receipt"] is None)
                    require(metadata_at(root, workspace_name) is None and metadata_at(root, claim_name) is None)
                    state = new_state(root)
                receipt = state["receipt"]
                if receipt is not None:
                    check_receipt(root, receipt)
                require(placement["receipt"] is None or receipt == placement["receipt"])
                original = metadata_at(root, workspace_name)
                claimed = metadata_at(root, claim_name)
                if original is None and claimed is None:
                    state["phase"] = "cleaned"
                    save_state(root, state)
                    return "CLEANED"
                require(state["phase"] != "cleaned" and receipt is not None)
                require(original is None or claimed is None)
                if original is not None:
                    workspace = open_workspace(root, workspace_name, receipt)
                    os.close(workspace)
                    state["phase"] = "claiming"
                    save_state(root, state)
                    rename_without_replacement(root, workspace_name, claim_name)
                    moved = metadata_at(root, claim_name)
                    if moved is None or identity(moved) != receipt["workspace"]:
                        try:
                            rename_without_replacement(root, claim_name, workspace_name)
                        except OSError:
                            pass
                        raise ValueError("Archive claim identity changed.")
                workspace = open_workspace(root, claim_name, receipt, allow_missing=state["phase"] == "claimed")
                try:
                    state["phase"] = "claimed"
                    save_state(root, state)
                    for name in artifact_names.values():
                        if metadata_at(workspace, name) is not None:
                            os.unlink(name, dir_fd=workspace)
                    require(not os.listdir(workspace))
                    require(identity(metadata_at(root, claim_name)) == receipt["workspace"])
                    os.rmdir(claim_name, dir_fd=root)
                    os.fsync(root)
                finally:
                    os.close(workspace)
                state["phase"] = "cleaned"
                save_state(root, state)
                return "CLEANED"

            def run_checked(arguments, **options):
                return subprocess.run(arguments, check=True, stderr=subprocess.DEVNULL, **options)

            def git_output(source, arguments):
                return run_checked(["git", *arguments], cwd=source, stdout=subprocess.PIPE).stdout.decode().strip()

            def open_artifact(workspace, key, flags):
                descriptor = os.open(artifact_names[key], flags | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=workspace)
                try:
                    metadata = os.fstat(descriptor)
                    owned(metadata)
                    require(identity(metadata) == placement["receipt"][key])
                    return descriptor
                except BaseException:
                    os.close(descriptor)
                    raise

            def capture(workspace):
                source = request["source_path"]
                head = git_output(source, ["rev-parse", "HEAD"])
                branch = git_output(source, ["rev-parse", "--abbrev-ref", "HEAD"])
                detached = branch == "HEAD"
                common = git_output(source, ["rev-parse", "--git-common-dir"]) if os.path.isfile(source + "/.git") else ""
                refs = git_output(source, ["for-each-ref", "--format=%(refname:short)", "refs/heads"])
                archive = open_artifact(workspace, "archive", os.O_RDWR)
                try:
                    os.ftruncate(archive, 0)
                    if request["layout"] == "worktree":
                        bundle = open_artifact(workspace, "bundle", os.O_RDWR)
                        try:
                            os.ftruncate(bundle, 0)
                            run_checked(["git", "bundle", "create", "-", "HEAD"], cwd=source, stdout=bundle)
                        finally:
                            os.close(bundle)
                        run_checked(["tar", "--exclude=.git", "-cf", "-", "."], cwd=source, stdout=archive)
                        run_checked([
                            "tar", "-rf", "/proc/self/fd/" + str(archive),
                            "-C", "/proc/self/fd/" + str(workspace), bundle_name,
                        ], pass_fds=(archive, workspace), stdout=subprocess.DEVNULL)
                    else:
                        run_checked(["tar", "-cf", "-", "."], cwd=source, stdout=archive)
                    os.fsync(archive)
                finally:
                    os.close(archive)
                return {
                    "head": head, "branch": "" if detached else branch, "detached": "1" if detached else "0",
                    "archive": root_path + "/" + workspace_name + "/archive.tar",
                    "common": common, "refs": " ".join(refs.splitlines()),
                }

            def materialize(workspace):
                with TransferDestination(request["destination_attempt"]) as owner:
                    checkout = owner.directory()
                    destination = "/proc/self/fd/" + str(checkout)
                    archive = open_artifact(workspace, "archive", os.O_RDONLY)
                    try:
                        run_checked(["tar", "-xf", "/proc/self/fd/" + str(archive), "-C", destination], pass_fds=(archive, checkout), stdout=subprocess.DEVNULL)
                    finally:
                        os.close(archive)
                    if not os.path.isdir(destination + "/.git"):
                        run_checked(["git", "init", "--quiet"], cwd=destination, pass_fds=(checkout,), stdout=subprocess.DEVNULL)
                        bundles = [name for name in os.listdir(destination) if name.endswith(".bundle") and os.path.isfile(destination + "/" + name)]
                        if len(bundles) == 1:
                            run_checked(["git", "fetch", "--quiet", "./" + bundles[0], "HEAD"], cwd=destination, pass_fds=(checkout,), stdout=subprocess.DEVNULL)
                            os.unlink(destination + "/" + bundles[0])
                        if request["detached"] or not request["branch"]:
                            arguments = ["git", "checkout", "--quiet", "--detach", request["head"]]
                        else:
                            arguments = ["git", "checkout", "--quiet", "-B", request["branch"], request["head"]]
                        run_checked(arguments, cwd=destination, pass_fds=(checkout,), stdout=subprocess.DEVNULL)
                    owner.confirm_current()
                return "MATERIALIZED"

            try:
                require(mode in ("prepare", "capture", "materialize", "cleanup"))
                require(side in ("source", "destination") and placement["workspace_name"] == attempt)
                root = open_root()
                expected = placement["receipt"]
                if expected is not None:
                    require(identity(os.fstat(root)) == expected["root"])
                lock = os.open(attempt + ".lock", os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600, dir_fd=root)
                owned(os.fstat(lock))
                fcntl.flock(lock, fcntl.LOCK_EX)
                state = read_state(root)
                if mode == "prepare":
                    result = prepare(root, state)
                elif mode == "cleanup":
                    result = cleanup(root, state)
                else:
                    require(state is not None and state["phase"] == "ready" and expected is not None)
                    check_receipt(root, state["receipt"])
                    workspace = open_workspace(root, workspace_name, state["receipt"])
                    try:
                        if mode == "capture":
                            result = capture(workspace)
                        else:
                            os.umask(initial_umask)
                            result = materialize(workspace)
                    finally:
                        os.close(workspace)
                print(json.dumps(result, separators=(",", ":")))
            except BaseException:
                sys.exit(20)
            PYTHON;
    }
}
