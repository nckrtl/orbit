<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final class TransferDestinationProgram
{
    public static function definitions(): string
    {
        return <<<'PYTHON'
            import ctypes
            import fcntl
            import hashlib
            import json
            import os
            import pwd
            import stat
            import sys

            class TransferDestination:
                scope_name = ".orbit-transfer-destinations"

                @staticmethod
                def require(condition):
                    if not condition:
                        raise ValueError("Transfer destination ownership is unconfirmed.")

                @staticmethod
                def identity(metadata):
                    return str(metadata.st_dev) + ":" + str(metadata.st_ino)

                @staticmethod
                def metadata(parent, name):
                    try:
                        return os.stat(name, dir_fd=parent, follow_symlinks=False)
                    except FileNotFoundError:
                        return None

                @classmethod
                def owned(cls, metadata, directory=True, private=False):
                    cls.require(metadata is not None and metadata.st_uid == os.geteuid())
                    cls.require(stat.S_ISDIR(metadata.st_mode) if directory else stat.S_ISREG(metadata.st_mode))
                    if private:
                        cls.require(stat.S_IMODE(metadata.st_mode) == (0o700 if directory else 0o600))
                    if not directory:
                        cls.require(metadata.st_nlink == 1)

                @classmethod
                def open_path(cls, path, create=False, private_suffix=False):
                    parts = path.split("/")
                    cls.require(parts[0] == "" and all(part not in ("", ".", "..") for part in parts[1:]))
                    descriptor = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
                    try:
                        for position, part in enumerate(parts[1:], start=1):
                            private = private_suffix and position >= len(parts) - 2
                            if create and (not private_suffix or private):
                                try:
                                    os.mkdir(part, 0o700 if private else 0o777, dir_fd=descriptor)
                                except FileExistsError:
                                    pass
                            following = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=descriptor)
                            os.close(descriptor)
                            descriptor = following
                            if private:
                                cls.owned(os.fstat(descriptor), private=True)
                        return descriptor
                    except BaseException:
                        os.close(descriptor)
                        raise

                @staticmethod
                def rename(source_parent, source, destination_parent, destination):
                    library = ctypes.CDLL(None, use_errno=True)
                    operation = library.renameat2
                    operation.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
                    operation.restype = ctypes.c_int
                    if operation(source_parent, source.encode(), destination_parent, destination.encode(), 1) != 0:
                        raise OSError(ctypes.get_errno(), "Destination claim was refused.")

                def __init__(self, specification):
                    self.specification = specification
                    self.attempt = specification["id"]
                    self.expected = specification["receipt"]
                    self.path = specification["destination_path"]
                    self.parent_path, self.name = os.path.split(self.path)
                    self.state_name = self.attempt + ".json"
                    self.stage_name = self.attempt + ".stage"
                    self.claim_name = self.attempt + ".claimed"
                    self.descriptors = []
                    self.journal = None
                    self.revision = 0
                    self.require(pwd.getpwnam(specification["execution_user"]).pw_uid == os.geteuid())
                    self.require(specification["private_root"].endswith("/.orbit/transfer-destinations"))
                    previous = os.umask(0o077)
                    try:
                        self.root = self.track(self.open_path(specification["private_root"], create=True, private_suffix=True))
                        if self.expected is not None:
                            self.require(self.identity(os.fstat(self.root)) == self.expected["root"])
                        self.lock = self.track(os.open(self.attempt + ".lock", os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600, dir_fd=self.root))
                        self.owned(os.fstat(self.lock), directory=False, private=True)
                        fcntl.flock(self.lock, fcntl.LOCK_EX)
                        self.state = self.read_state()
                    except BaseException:
                        self.close()
                        raise
                    finally:
                        os.umask(previous)

                def track(self, descriptor):
                    self.descriptors.append(descriptor)
                    return descriptor

                def close(self):
                    for descriptor in reversed(self.descriptors):
                        os.close(descriptor)
                    self.descriptors = []

                def __enter__(self):
                    return self

                def __exit__(self, kind, value, traceback):
                    self.close()

                def binding(self):
                    return {
                        "version": 1, "id": self.attempt, "transfer_id": self.specification["transfer_id"],
                        "node_id": self.specification["node_id"], "destination_path": self.path,
                        "root": self.identity(os.fstat(self.root)),
                    }

                def read_state(self):
                    metadata = self.metadata(self.root, self.state_name)
                    if metadata is None:
                        return None
                    self.owned(metadata, directory=False, private=True)
                    descriptor = self.track(os.open(self.state_name, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=self.root))
                    self.require(self.identity(os.fstat(descriptor)) == self.identity(metadata) and metadata.st_size == 32768)
                    self.journal = descriptor
                    records = [record for slot in (0, 1) if (record := self.read_record(slot)) is not None]
                    self.require(records)
                    if len(records) == 2:
                        self.require(abs(records[0]["revision"] - records[1]["revision"]) == 1)
                    latest = max(records, key=lambda record: record["revision"])
                    self.revision, state = latest["revision"], latest["state"]
                    self.require(isinstance(state, dict) and set(state) == set(self.binding()) | {"phase", "receipt"})
                    self.require(all(state[key] == value for key, value in self.binding().items()))
                    self.require(state["phase"] in ("creating", "staged", "owned", "claiming", "claimed", "cleaned"))
                    if state["receipt"] is not None:
                        self.check_receipt(state["receipt"])
                    self.require(self.expected is None or state["receipt"] == self.expected)
                    self.check_journal()
                    return state

                def read_record(self, slot):
                    frame = os.pread(self.journal, 16384, slot * 16384)
                    length = int.from_bytes(frame[:4], "big")
                    if len(frame) != 16384 or not 0 < length <= 16348:
                        return None
                    data = frame[36:36 + length]
                    if hashlib.sha256(data).digest() != frame[4:36]:
                        return None
                    try:
                        record = json.loads(data)
                    except (ValueError, UnicodeError):
                        return None
                    if not isinstance(record, dict) or set(record) != {"format", "revision", "state"} or record["format"] != 2:
                        return None
                    if type(record["revision"]) is not int or not 0 < record["revision"] < 2**63 or record["revision"] % 2 != slot:
                        return None
                    return record

                def check_journal(self):
                    current = self.open_path(self.specification["private_root"], private_suffix=True)
                    try:
                        self.require(self.identity(os.fstat(current)) == self.identity(os.fstat(self.root)))
                        metadata = self.metadata(current, self.state_name)
                        self.owned(metadata, directory=False, private=True)
                        self.require(self.identity(metadata) == self.identity(os.fstat(self.journal)) and metadata.st_size == 32768)
                    finally:
                        os.close(current)

                def check_receipt(self, receipt):
                    self.require(isinstance(receipt, dict) and set(receipt) == {"root", "parent", "scope", "checkout"})
                    self.require(receipt["root"] == self.identity(os.fstat(self.root)))
                    self.require(self.expected is None or receipt == self.expected)

                def save_state(self):
                    if self.journal is None:
                        self.journal = self.track(os.open(self.state_name, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=self.root))
                        os.ftruncate(self.journal, 32768)
                    self.check_journal()
                    descriptor = self.journal
                    revision = self.revision + 1
                    self.require(revision < 2**63)
                    data = json.dumps({"format": 2, "revision": revision, "state": self.state}, separators=(",", ":")).encode()
                    self.require(len(data) <= 16348)
                    frame = (len(data).to_bytes(4, "big") + hashlib.sha256(data).digest() + data).ljust(16384, b"\0")
                    offset, position = (revision % 2) * 16384, 0
                    while position < len(frame):
                        written = os.pwrite(descriptor, frame[position:], offset + position)
                        self.require(written > 0)
                        position += written
                    os.fsync(descriptor)
                    self.check_journal()
                    os.fsync(self.root)
                    self.revision = revision

                def parent_and_scope(self, create=False, receipt=None):
                    parent = self.track(self.open_path(self.parent_path, create=create))
                    self.owned(os.fstat(parent))
                    if create:
                        try:
                            os.mkdir(self.scope_name, 0o700, dir_fd=parent)
                        except FileExistsError:
                            pass
                    scope = self.track(os.open(self.scope_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent))
                    self.owned(os.fstat(scope), private=True)
                    if receipt is not None:
                        self.check_receipt(receipt)
                        self.require(self.identity(os.fstat(parent)) == receipt["parent"])
                        self.require(self.identity(os.fstat(scope)) == receipt["scope"])
                    return parent, scope

                def create(self):
                    self.require(self.specification["phase"] == "acquiring" and self.expected is None and self.state is None)
                    self.state = {**self.binding(), "phase": "creating", "receipt": None}
                    self.save_state()
                    parent, scope = self.parent_and_scope(create=True)
                    self.require(self.metadata(scope, self.stage_name) is None and self.metadata(scope, self.claim_name) is None)
                    os.mkdir(self.stage_name, 0o777, dir_fd=scope)
                    created = self.metadata(scope, self.stage_name)
                    self.owned(created)
                    stage = self.track(os.open(self.stage_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=scope))
                    self.owned(os.fstat(stage))
                    self.require(self.identity(os.fstat(stage)) == self.identity(created))
                    receipt = {
                        "root": self.identity(os.fstat(self.root)), "parent": self.identity(os.fstat(parent)),
                        "scope": self.identity(os.fstat(scope)), "checkout": self.identity(os.fstat(stage)),
                    }
                    os.fsync(stage)
                    os.fsync(scope)
                    self.state.update(phase="staged", receipt=receipt)
                    self.save_state()
                    self.require(self.identity(self.metadata(scope, self.stage_name)) == receipt["checkout"])
                    self.rename(scope, self.stage_name, parent, self.name)
                    placed = self.metadata(parent, self.name)
                    if placed is None or self.identity(placed) != receipt["checkout"]:
                        try:
                            self.rename(parent, self.name, scope, self.stage_name)
                        except OSError:
                            pass
                        raise ValueError("Destination placement identity changed.")
                    os.fsync(parent)
                    os.fsync(scope)
                    self.state["phase"] = "owned"
                    self.save_state()
                    return receipt

                def directory(self):
                    self.require(self.specification["phase"] == "owned" and self.expected is not None)
                    self.require(self.state is not None and self.state["phase"] == "owned")
                    parent, scope = self.parent_and_scope(receipt=self.expected)
                    self.require(self.metadata(scope, self.stage_name) is None and self.metadata(scope, self.claim_name) is None)
                    checkout = self.track(os.open(self.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent))
                    self.owned(os.fstat(checkout))
                    self.require(self.identity(os.fstat(checkout)) == self.expected["checkout"])
                    return checkout

                def confirm_current(self):
                    parent, scope = self.parent_and_scope(receipt=self.expected)
                    self.require(self.identity(self.metadata(parent, self.name)) == self.expected["checkout"])

                def clear_directory(self, directory, device):
                    for name in os.listdir(directory):
                        metadata = self.metadata(directory, name)
                        self.require(metadata is not None and metadata.st_dev == device)
                        if stat.S_ISDIR(metadata.st_mode):
                            child = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
                            try:
                                self.require(self.identity(os.fstat(child)) == self.identity(metadata))
                                self.clear_directory(child, device)
                                self.require(self.identity(self.metadata(directory, name)) == self.identity(metadata))
                                os.rmdir(name, dir_fd=directory)
                            finally:
                                os.close(child)
                        else:
                            os.unlink(name, dir_fd=directory)

                def cleanup_uncreated(self):
                    try:
                        parent = self.track(self.open_path(self.parent_path))
                    except FileNotFoundError:
                        return
                    if self.state is None:
                        self.require(self.metadata(parent, self.name) is None)
                    if self.metadata(parent, self.scope_name) is not None:
                        scope = self.track(os.open(self.scope_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent))
                        self.owned(os.fstat(scope), private=True)
                        self.require(self.metadata(scope, self.stage_name) is None and self.metadata(scope, self.claim_name) is None)

                def cleanup(self):
                    self.require(self.specification["phase"] in ("acquiring", "owned"))
                    if self.state is not None and self.state["phase"] == "cleaned":
                        return "CLEANED"
                    if self.state is None or self.state["receipt"] is None:
                        self.require(self.expected is None)
                        self.cleanup_uncreated()
                        self.state = {**self.binding(), "phase": "cleaned", "receipt": None}
                        self.save_state()
                        return "CLEANED"
                    receipt = self.state["receipt"]
                    parent, scope = self.parent_and_scope(receipt=receipt)
                    original = self.metadata(parent, self.name)
                    staged = self.metadata(scope, self.stage_name)
                    claimed = self.metadata(scope, self.claim_name)
                    self.require(staged is None or claimed is None)
                    if claimed is None:
                        if staged is not None:
                            self.require(self.state["phase"] in ("staged", "claiming"))
                            origin_parent, origin_name, origin = scope, self.stage_name, staged
                        elif original is not None and self.identity(original) == receipt["checkout"]:
                            origin_parent, origin_name, origin = parent, self.name, original
                        else:
                            self.require(self.state["phase"] == "claimed")
                            self.state["phase"] = "cleaned"
                            self.save_state()
                            return "CLEANED"
                        self.owned(origin)
                        self.require(self.identity(origin) == receipt["checkout"])
                        self.state["phase"] = "claiming"
                        self.save_state()
                        self.rename(origin_parent, origin_name, scope, self.claim_name)
                        moved = self.metadata(scope, self.claim_name)
                        if moved is None or self.identity(moved) != receipt["checkout"]:
                            try:
                                self.rename(scope, self.claim_name, origin_parent, origin_name)
                            except OSError:
                                pass
                            raise ValueError("Destination claim identity changed.")
                    checkout = self.track(os.open(self.claim_name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=scope))
                    self.owned(os.fstat(checkout))
                    self.require(self.identity(os.fstat(checkout)) == receipt["checkout"])
                    self.state["phase"] = "claimed"
                    self.save_state()
                    self.clear_directory(checkout, os.fstat(checkout).st_dev)
                    self.require(self.identity(self.metadata(scope, self.claim_name)) == receipt["checkout"])
                    os.rmdir(self.claim_name, dir_fd=scope)
                    os.fsync(scope)
                    self.state["phase"] = "cleaned"
                    self.save_state()
                    return "CLEANED"
            PYTHON;
    }

    public static function script(): string
    {
        return self::definitions()."\n".<<<'PYTHON'
            try:
                with TransferDestination(json.load(sys.stdin)) as destination:
                    if sys.argv[1] == "create":
                        result = destination.create()
                    elif sys.argv[1] == "cleanup":
                        result = destination.cleanup()
                    else:
                        raise ValueError("Unsupported destination operation.")
                    print(json.dumps(result, separators=(",", ":")))
            except BaseException:
                sys.exit(20)
            PYTHON;
    }
}
