#!/usr/bin/env python3
"""Prove instance-transfer.md on a leased, disposable Incus topology.

Usage: python3 apps/e2e/resources/proofs/large-sqlite-transfer.py TASK-840
Acquire the topology first. This script verifies Incus ownership before sizing
only the allocated workload guests to at least 4 GiB RAM and 2 GiB /tmp staging.
It transfers a non-sparse, random 1152 MiB payload, exceeding both 1 GB and 1 GiB.
It changes the disposable app-prod guest to app-dev (removing only its known
sample Instance and conflicting roles). It leaves the lease and its capacity
changes in place for inspection; release it after recording the evidence.

All guest assertions and cleanup commands are recorded. Product state is created
and removed through Orbit, never patched in the Gateway database. The database
queries below are read-only. Evidence belongs in .orbit-artifacts/, not Git.
"""

import datetime
import json
import os
import pathlib
import re
import subprocess
import sys
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[4]
ISSUE = sys.argv[1] if len(sys.argv) == 2 else ""
if not re.fullmatch(r"TASK-[1-9][0-9]*", ISSUE):
    raise SystemExit("Pass the allocated topology issue, e.g. TASK-840")
SLUG = f"t{ISSUE[5:]}-transfer-{uuid.uuid4().hex[:8]}"
PREFIX = f"{SLUG}-"
APPS = f"/home/orbit/apps/{SLUG}"
STAGING = "/home/orbit/orbit/apps/gateway/storage/app/transfer-staging"
MONITOR = f"/home/orbit/{SLUG}.monitor-ready"
PROCESSES = []
INSTANCES = []
PROJECT = None
SOURCE_ID = None
DESTINATION_ID = None
PARENT_CREATED = False
LEASE_VERIFIED = False
PAYLOAD_BYTES = 1152 * 1024 * 1024
MINIMUM_LARGE_BYTES = 1024 * 1024 * 1024
NODE_TMP_BYTES = 2 * 1024 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def harness(action, node=None, *args, timeout=60, label=None, expected=0):
    command = [str(ROOT / "bin/e2e-topology"), action, ISSUE]
    if node:
        command.append(node)
    command.extend(args)
    if label:
        command.append(f"--record={PREFIX}{label}")
    result = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=timeout + 90)
    print(result.stdout, end="", flush=True)
    print(result.stderr, end="", file=sys.stderr, flush=True)
    require(result.returncode == expected, f"{action} {node}: exit {result.returncode}, expected {expected}")
    return result.stdout


def on(node, label, *argv, timeout=60, expected=0):
    return harness("exec", node, f"--timeout={timeout}", f"--argv={json.dumps(argv)}",
                   timeout=timeout, label=label, expected=expected)


def decode(output):
    # exec returns the guest's output; status --json has one JSON document.
    return json.loads(output)


def orbit(label, *args, timeout=240):
    return decode(on("gateway", label, "orbit", *map(str, args), "--json", "--no-interaction", timeout=timeout))


def python(node, label, code, *args, timeout=60):
    return on(node, label, "python3", "-c", code, *map(str, args), timeout=timeout)


def spawn(node, name, code, *args):
    harness("spawn", node, name, f"--argv={json.dumps(['python3', '-u', '-c', code, *map(str, args)])}")
    PROCESSES.append((node, name))


def stop(node, name):
    harness("kill", node, name)
    PROCESSES.remove((node, name))


CAPACITY = r'''
import json, pathlib, os, subprocess
memory = {line.split(':')[0]: int(line.split()[1]) * 1024 for line in pathlib.Path('/proc/meminfo').read_text().splitlines() if line.startswith(('MemTotal:', 'MemAvailable:'))}
def space(path):
    fs = os.statvfs(path)
    return {'capacity_bytes': fs.f_blocks * fs.f_frsize, 'available_bytes': fs.f_bavail * fs.f_frsize}
print(json.dumps({'memory': memory, 'tmp': space('/tmp'), 'checkout_disk': space('/home/orbit'), 'tmp_filesystem': subprocess.check_output(['findmnt', '-n', '-o', 'FSTYPE', '/tmp'], text=True).strip()}))
'''


def prepare_node_capacity(status):
    # Capacity is test infrastructure, not a product patch. Do not touch the
    # Gateway or any guest outside this exact discovery lease. The Gateway's
    # default /tmp remains smaller than the transfer, strengthening that proof.
    require(os.environ.get('ORBIT_E2E_INCUS_REMOTE', 'local') == 'local', 'Only local Incus is supported')
    project = os.environ.get('ORBIT_E2E_INCUS_PROJECT', 'default')
    incus = ['incus', '--project', project]

    def host(*args):
        command = [*incus, *args]
        start = datetime.datetime.now(datetime.timezone.utc).isoformat()
        result = subprocess.run(command, text=True, capture_output=True, timeout=180)
        end = datetime.datetime.now(datetime.timezone.utc).isoformat()
        print('CAPACITY_HOST', json.dumps({'argv': command, 'start': start, 'end': end, 'exit': result.returncode, 'stdout': result.stdout, 'stderr': result.stderr}), flush=True)
        require(result.returncode == 0, 'Allocated guest capacity command failed')
        return result.stdout.strip()

    for node in ('app-dev', 'app-prod'):
        guest = 'local:' + status['topology']['instances'][node]
        for key, expected in [('owner', 'orbit-e2e'), ('issue', ISSUE), ('attempt', status['attempt_id'])]:
            require(host('config', 'get', guest, 'user.orbit.e2e.' + key) == expected, 'Incus guest ownership mismatch; refusing capacity change')
        before = decode(python(node, 'workload-staging-capacity-before', CAPACITY))
        require(before['tmp_filesystem'] == 'tmpfs', 'Unexpected allocated Node /tmp filesystem')
        if before['memory']['MemTotal'] < 3 * 1024 * 1024 * 1024:
            host_available = next(int(line.split()[1]) * 1024 for line in pathlib.Path('/proc/meminfo').read_text().splitlines() if line.startswith('MemAvailable:'))
            require(host_available >= 4 * 1024 * 1024 * 1024, 'Insufficient host memory headroom for allocated Node enlargement')
            # VM memory enlargement needs a restart; stop only this owned guest.
            # Leave the snapshot, shared Incus profiles, and other tasks untouched.
            host('stop', guest, '--timeout=60')
            host('config', 'set', guest, 'limits.memory=4GiB')
            host('start', guest)
            for _ in range(90):
                try:
                    ready = subprocess.run([*incus, 'exec', guest, '--', '/bin/true'], capture_output=True, timeout=10)
                    if ready.returncode == 0:
                        break
                except subprocess.TimeoutExpired:
                    pass
                time.sleep(2)
            else:
                raise RuntimeError('Allocated guest agent did not return after capacity restart')
        if before['tmp']['capacity_bytes'] < NODE_TMP_BYTES:
            on(node, 'increase-owned-node-tmp-staging-capacity', 'sudo', 'mount', '-o', f'remount,size={NODE_TMP_BYTES}', '/tmp')
        after = decode(python(node, 'workload-staging-capacity-sufficient', CAPACITY))
        require(after['memory']['MemTotal'] >= 3 * 1024 * 1024 * 1024, 'Insufficient workload Node memory')
        require(after['tmp']['available_bytes'] >= PAYLOAD_BYTES + 128 * 1024 * 1024, 'Insufficient Node archive staging space')
        require(after['checkout_disk']['available_bytes'] >= PAYLOAD_BYTES + 128 * 1024 * 1024, 'Insufficient Node checkout disk space')


STATE = r'''
import json, os, sqlite3, sys
c = sqlite3.connect('file:' + os.environ['DB_DATABASE'] + '?mode=ro', uri=True)
c.row_factory = sqlite3.Row
rows = [dict(r) for r in c.execute('SELECT * FROM instance_transfers WHERE instance_id=? ORDER BY created_at, id', (int(sys.argv[1]),))]
print(json.dumps(rows))
'''


PREPARE = r'''
import hashlib, json, os, pathlib, sqlite3, subprocess, sys, zlib
base = pathlib.Path(sys.argv[1]); size = int(sys.argv[2])
(base / 'public').mkdir(exist_ok=True)
(base / 'public/index.html').write_text('Disposable transfer proof\n')
(base / 'database').mkdir(exist_ok=True)
(base / '.transfer-proof').mkdir(exist_ok=True)
with (base / '.git/info/exclude').open('a') as f:
    f.write('\n/.transfer-proof/\n/database/\n')
payload = base / '.transfer-proof/payload.bin'
digest = hashlib.sha256(); compressed_sample_bytes = None
with payload.open('wb') as f:
    for _ in range(size // (1024 * 1024)):
        block = os.urandom(1024 * 1024); f.write(block); digest.update(block)
        if compressed_sample_bytes is None:
            compressed_sample_bytes = len(zlib.compress(block))
            assert compressed_sample_bytes >= len(block) * 0.99
    f.flush(); os.fsync(f.fileno())
assert payload.stat().st_size == size
assert payload.stat().st_blocks * 512 >= size  # Non-sparse, real bytes.
subprocess.run(['git', '-C', str(base), 'check-ignore', str(payload)], check=True)
c = sqlite3.connect(base / 'database/selected.sqlite')
c.execute('CREATE TABLE transfer_proof (value TEXT PRIMARY KEY)')
c.execute("INSERT INTO transfer_proof VALUES ('baseline')"); c.commit(); c.close()
print(json.dumps({'payload_bytes': size, 'payload_generator': 'os.urandom, a fresh random block per MiB', 'compression_sample_bytes': 1024 * 1024, 'compressed_sample_bytes': compressed_sample_bytes, 'payload_sha256': digest.hexdigest(), 'checkout_bytes': sum(p.stat().st_size for p in base.rglob('*') if p.is_file())}))
'''


KEEP_WAL = r'''
import pathlib, signal, sqlite3, sys, time
base = pathlib.Path(sys.argv[1])
c = sqlite3.connect(base / 'database/selected.sqlite')
assert c.execute('PRAGMA journal_mode=WAL').fetchone() == ('wal',)
c.execute('PRAGMA wal_autocheckpoint=0')
c.execute("INSERT INTO transfer_proof VALUES ('written-just-before-transfer')"); c.commit()
assert (base / 'database/selected.sqlite-wal').stat().st_size > 0
print('WAL_ROW_COMMITTED', time.time_ns(), flush=True)
# Keep the WAL present, but perform no more writes during transfer.
signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
while True: time.sleep(0.1)
'''


WATCH = r'''
import glob, json, os, pathlib, signal, sys, time
staging, ready = sys.argv[1:]
peak = 0; paths = set(); tmp = set(); samples = 0; done = False

def terminate(*_):
    global done
    done = True
signal.signal(signal.SIGTERM, terminate)
pathlib.Path(ready).touch()
print('STAGING_WATCH_READY', time.time_ns(), flush=True)
while not done:
    samples += 1
    tmp.update(glob.glob('/tmp/orbit-transfer-*'))
    for path in glob.glob(staging + '/orbit-transfer-*'):
        try: size = os.stat(path).st_size
        except FileNotFoundError: continue
        paths.add(os.path.realpath(path)); peak = max(peak, size)
    time.sleep(0.02)
print('PROOF_STAGING ' + json.dumps({'peak_bytes': peak, 'staging_paths': sorted(paths), 'gateway_tmp_paths': sorted(tmp), 'samples': samples}), flush=True)
'''


VERIFY = r'''
import hashlib, json, pathlib, sqlite3, sys
base = pathlib.Path(sys.argv[1]); expected_digest = sys.argv[2]; expected_rows = json.loads(sys.argv[3])
c = sqlite3.connect('file:' + str(base / 'database/selected.sqlite') + '?mode=ro', uri=True)
integrity = c.execute('PRAGMA integrity_check').fetchall()
rows = sorted(r[0] for r in c.execute('SELECT value FROM transfer_proof'))
assert integrity == [('ok',)], integrity
assert rows == sorted(expected_rows), rows
with (base / '.transfer-proof/payload.bin').open('rb') as f:
    digest = hashlib.file_digest(f, 'sha256').hexdigest()
assert digest == expected_digest, (digest, expected_digest)
print(json.dumps({'integrity_check': integrity, 'rows': rows, 'payload_sha256': digest}))
'''


# Capture archives currently outlive the product operation on the source Node.
# Explicitly audit and remove ONLY this proof's archives between transfers, so
# the Node's small tmpfs does not affect the independent rollback scenario.
ARCHIVES = r'''
import glob, json, os, stat, sys
mode, names_json, ids_json = sys.argv[1:]
paths = []
for name in json.loads(names_json):
    paths += glob.glob('/tmp/orbit-transfer-' + name + '-*.tar')
for ident in json.loads(ids_json):
    path = '/tmp/orbit-transfer-' + str(ident) + '.tar'
    if os.path.lexists(path): paths.append(path)
paths = sorted(set(paths))
for path in paths:
    s = os.lstat(path)
    assert stat.S_ISREG(s.st_mode) and s.st_uid == os.getuid(), path
print(json.dumps({'task_owned_node_archives': [{'path': p, 'bytes': os.stat(p).st_size} for p in paths], 'action': mode}))
if mode == 'remove':
    for path in paths: os.unlink(path)
else:
    assert not paths, paths
'''


# Successful SQLite seeds retain small replay receipts. After fixture destruction,
# remove only complete receipts whose recorded destination belongs to this run.
# Unknown/incomplete state, snapshots, and incoming files fail the cleanup audit.
SEED_RECEIPTS = r'''
import json, os, pathlib, re, stat, sys
root = pathlib.Path('/var/lib/orbit/app-instance-sqlite-seeds')
base = pathlib.Path(sys.argv[1]); identifiers = json.loads(sys.argv[2])
removed = []
for ident in identifiers:
    assert not list(root.glob(f'source-{ident}-target-{ident}-*')), 'Source seed state remains'
    for directory in root.glob(f'target-{ident}-*'):
        assert stat.S_ISDIR(directory.lstat().st_mode), directory
        assert sorted(p.name for p in directory.iterdir()) == ['state.json'], directory
        receipt = directory / 'state.json'
        assert stat.S_ISREG(receipt.lstat().st_mode), receipt
        state = json.loads(receipt.read_text())
        operation = state['operation']
        assert re.fullmatch('[0-9a-f]{64}', operation)
        assert directory.name == f'target-{ident}-{operation}'
        destination = pathlib.Path(state['destination'])
        assert base in destination.parents and not os.path.lexists(destination), destination
        assert state['status'] == 'complete', state
        snapshot = pathlib.Path(f'/tmp/orbit-sqlite-{operation}.sqlite')
        assert not snapshot.exists(), snapshot
        removed.append({'receipt': str(receipt), 'state': state})
        receipt.unlink(); directory.rmdir()
    assert not list(root.glob(f'target-{ident}-*'))
assert not base.exists(), base
assert not list(pathlib.Path('/tmp').glob('orbit-sqlite-*.sqlite')), 'SQLite snapshot remains'
print(json.dumps({'removed_complete_fixture_seed_receipts': removed, 'source_seed_state': 'absent', 'target_seed_state': 'absent'}))
'''


def archive_cleanup(label):
    names = json.dumps([f"{SLUG}-large", f"{SLUG}-failure", f"{SLUG}-recovered"])
    ids = json.dumps(INSTANCES)
    for node in ("app-dev", "app-prod"):
        python(node, label, ARCHIVES, "remove", names, ids)
        python(node, label + "-audit", ARCHIVES, "audit", names, ids)


def create_instance(name, domain):
    row = orbit("instance-create-" + name, "instance:create", PROJECT, SOURCE_ID, name)
    INSTANCES.append(row["id"])
    require(row["checkout_path"] == f"{APPS}/{name}", "Unexpected fixture placement")
    python("app-dev", "web-root-" + name, "import pathlib,sys; pathlib.Path(sys.argv[1], 'public').mkdir(exist_ok=True)", row["checkout_path"])
    route = orbit("route-create-" + name, "route:create", row["id"], domain, "--publication=private")
    require(route["status"] == "active", "Fixture Route did not activate")
    return row


def transfer(instance, name, label, expected=0):
    return decode(on("gateway", label, "orbit", "instance:transfer", str(instance["id"]), str(DESTINATION_ID),
                     f"--name={name}", f"--sqlite-source-path={instance['checkout_path']}/database/selected.sqlite",
                     "--force", "--json", "--no-interaction", timeout=600, expected=expected))


def proof():
    global PROJECT, SOURCE_ID, DESTINATION_ID, PARENT_CREATED, LEASE_VERIFIED
    branch = subprocess.check_output(["git", "branch", "--show-current"], cwd=ROOT, text=True).strip()
    require(ISSUE[5:] in branch.split("-"), "Issue is not in this workspace's branch")
    status = decode(harness("status", None, "--json"))
    require(status["issue"] == ISSUE and status["state"] == "discovery", "Wrong topology lease")
    require(status["topology"]["purpose"] == "discovery" and all(name.startswith(f"orbit-e2e-{ISSUE.lower()}-{status['attempt_id'][:8]}-") for name in status["topology"]["instances"].values()), "Guests are not owned by this task lease")
    LEASE_VERIFIED = True
    nodes = orbit("nodes-before", "node:list")["nodes"]
    source = next(n for n in nodes if n["name"] == "app-dev")
    destination = next(n for n in nodes if n["name"] == "app-prod")
    SOURCE_ID, DESTINATION_ID = source["id"], destination["id"]
    # Refuse to mutate a fleet reached through the wrong guest CLI configuration.
    for node in nodes:
        require(node["name"] in status["topology"]["instances"], "Unknown Node in the allocated Gateway")
        addresses = on(node["name"], "allocated-node-endpoint-ownership", "hostname", "-I").split()
        require(node["public_ssh_host"] in addresses, "Gateway Node endpoint is not this allocated guest")
    require(source["cluster_id"] == destination["cluster_id"] and source["cluster_id"] is not None, "Nodes must share the allocated active Cluster")
    require("app-dev" in source["roles"], "Source is not app-dev")
    prepare_node_capacity(status)
    if "app-prod" in destination["roles"]:
        for instance in orbit("destination-sample-audit", "instance:list")["instances"]:
            if instance["node_id"] == DESTINATION_ID:
                require(instance["name"] == "e2e-prod" and instance["project"]["slug"] == "laravel-typed", "Unknown destination fixture; refusing deletion")
                orbit("remove-disposable-production-sample", "instance:destroy", instance["id"], "--yes")
    for role in ("ingress", "app-prod"):
        if role in destination["roles"]:
            orbit("remove-disposable-" + role, "node:role:remove", DESTINATION_ID, role, "--force")
    if "app-dev" not in destination["roles"]:
        orbit("converge-second-app-dev", "node:role:add", DESTINATION_ID, "app-dev", "--converge", timeout=600)
    nodes = orbit("two-app-dev-nodes", "node:list")["nodes"]
    require(all("app-dev" in next(n for n in nodes if n["id"] == ident)["roles"] for ident in (SOURCE_ID, DESTINATION_ID)), "Missing active app-dev roles")
    python("gateway", "staging-baseline", r'''
import glob, json, os, sys
assert not glob.glob(sys.argv[1] + '/orbit-transfer-*')
assert not glob.glob('/tmp/orbit-transfer-*')
print(json.dumps({'gateway_tmp': os.statvfs('/tmp').f_blocks * os.statvfs('/tmp').f_frsize, 'staging_path': sys.argv[1]}))
''', STAGING)
    on("gateway", "gateway-staging-filesystem-is-not-tmpfs", "df", "-T", str(pathlib.PurePosixPath(STAGING).parent), "/tmp")
    project = orbit("project-create", "project:create", SLUG, "laravel-package", "https://github.com/github/gitignore.git", "--default-branch=main", "--apps=[{\"name\":\"web\",\"path\":\".\",\"web_root\":\"public\",\"type\":\"laravel-package\"}]", "--task-workspace-routed=false")
    PROJECT = project["id"]
    large = create_instance(f"{SLUG}-large", f"{SLUG}-large.orbit")
    prepared = python("app-dev", "large-checkout-generated", PREPARE, large["checkout_path"], PAYLOAD_BYTES, timeout=180)
    facts = decode(prepared.strip().splitlines()[-1])
    require(facts["payload_bytes"] > MINIMUM_LARGE_BYTES and facts["checkout_bytes"] > MINIMUM_LARGE_BYTES, "Payload and checkout must both exceed 1 GiB")
    spawn("gateway", SLUG + "-staging", WATCH, STAGING, MONITOR)
    python("gateway", "watcher-ready", "import pathlib,sys,time; p=pathlib.Path(sys.argv[1]);\nfor _ in range(100):\n if p.exists(): break\n time.sleep(.05)\nassert p.exists()", MONITOR)
    spawn("app-dev", SLUG + "-wal", KEEP_WAL, large["checkout_path"])
    python("app-dev", "row-committed-before-transfer", r'''
import pathlib, sqlite3, sys, time
p = pathlib.Path(sys.argv[1]) / 'database/selected.sqlite'
for _ in range(100):
    c = sqlite3.connect(p); rows = c.execute('SELECT value FROM transfer_proof').fetchall(); c.close()
    if ('written-just-before-transfer',) in rows: break
    time.sleep(.05)
assert ('written-just-before-transfer',) in rows
assert pathlib.Path(str(p) + '-wal').stat().st_size > 0
print('PRE_TRANSFER_ROW', time.time_ns(), rows, 'wal_bytes', pathlib.Path(str(p) + '-wal').stat().st_size)
''', large["checkout_path"])
    moved = transfer(large, large["name"], "large-sqlite-transfer-completes")
    require(moved["id"] == large["id"] and moved["node_id"] == DESTINATION_ID, "Transfer did not keep Instance ID and cut over")
    require(moved["transfer"]["cleanup_completed"] is True, "Transfer source cleanup is incomplete")
    stop("gateway", SLUG + "-staging")
    logs = harness("logs", "gateway", SLUG + "-staging", "--lines=100", label="gateway-disk-staging-not-tmp")
    match = re.search(r"PROOF_STAGING (\{[^\n]+\})", logs)
    require(match is not None, "Missing staging monitor report")
    report = decode(match[1])
    require(report["peak_bytes"] > MINIMUM_LARGE_BYTES and report["samples"] > 0, "Did not observe a disk-staged archive larger than 1 GiB")
    require(report["staging_paths"] and all(p.startswith(STAGING + "/") for p in report["staging_paths"]), "Archive staged outside the expected disk directory")
    require(report["gateway_tmp_paths"] == [], "Gateway /tmp was used for archive staging")
    python("app-prod", "destination-sqlite-integrity-and-last-row", VERIFY, moved["checkout_path"], facts["payload_sha256"], json.dumps(["baseline", "written-just-before-transfer"]))
    python("app-dev", "source-placement-removed", "import os,sys; assert not os.path.lexists(sys.argv[1]); print('SOURCE_PLACEMENT_ABSENT')", large["checkout_path"])
    harness("logs", "app-dev", SLUG + "-wal", "--lines=20", label="sqlite-last-write-timestamp")
    stop("app-dev", SLUG + "-wal")
    archive_cleanup("large-transfer-node-archive-audit-and-cleanup")

    # Independent, small disposable fixture: fail materialization after reservation.
    small = create_instance(f"{SLUG}-failure", f"{SLUG}-failure.orbit")
    prepared = python("app-dev", "rollback-checkout-generated", PREPARE, small["checkout_path"], 1024 * 1024)
    small_facts = decode(prepared.strip().splitlines()[-1])
    PARENT_CREATED = True
    on("app-prod", "force-destination-unwritable", "bash", "-seuc", 'mkdir -p -- "$1"; chmod 0555 -- "$1"; test ! -w "$1"; stat -c "%a %U %n" "$1"', "--", APPS)
    failed_name = f"{SLUG}-blocked"
    failure = transfer(small, failed_name, "forced-pre-cutover-failure", expected=1)
    require(failure["error"]["code"] == "instance.transfer_failed", "Did not reach the intended transfer failure")
    history = decode(python("gateway", "failed-transfer-rollback-is-closed", STATE, small["id"]))
    require(len(history) == 1, "Expected exactly one reserved failed transfer")
    failed = history[0]
    require(failed["status"] == "failed" and failed["cutover_at"] is None and failed["current_step"] == "reserved" and failed["failed_step"] == "source-captured" and failed["recovery_evidence"] is None and failed["imported_environment_keys"] in (None, "[]"), "Pre-cutover rollback was not completed and closed")
    current = orbit("source-remains-authoritative", "instance:show", small["id"])
    require(current["node_id"] == SOURCE_ID and current["status"] == "active" and current["domain"] == f"{SLUG}-failure.orbit", "Rollback changed the authoritative source")
    python("app-prod", "failed-destination-absent", "import os,sys; assert not os.path.lexists(sys.argv[1]); print('FAILED_DESTINATION_ABSENT')", failed["destination_path"])
    on("app-prod", "restore-fixture-parent-writable", "chmod", "0755", APPS)
    archive_cleanup("failed-transfer-node-archive-audit-and-cleanup")
    python("app-dev", "write-after-rollback", "import sqlite3,sys; c=sqlite3.connect(sys.argv[1]); c.execute(\"INSERT INTO transfer_proof VALUES ('after-rollback')\"); c.commit(); print('AFTER_ROLLBACK_ROW_COMMITTED')", small["checkout_path"] + "/database/selected.sqlite")
    recovered = transfer(small, f"{SLUG}-recovered", "different-transfer-request-proceeds")
    require(recovered["node_id"] == DESTINATION_ID and recovered["transfer"]["cleanup_completed"] is True, "Different request did not finish")
    history = decode(python("gateway", "distinct-transfer-record-after-closed-failure", STATE, small["id"]))
    require(len(history) == 2 and len({h["id"] for h in history}) == 2 and {h["status"] for h in history} == {"failed", "completed"}, "Different request did not create a fresh completed transfer")
    python("app-prod", "recovered-sqlite-integrity-and-fresh-row", VERIFY, recovered["checkout_path"], small_facts["payload_sha256"], json.dumps(["baseline", "after-rollback"]))
    archive_cleanup("recovered-transfer-node-archive-audit-and-cleanup")


def cleanup():
    # Attempt every owned cleanup; any failed cleanup or audit fails the proof.
    if not LEASE_VERIFIED:
        return
    errors = []

    def attempt(function, *args):
        try:
            return function(*args)
        except Exception as error:
            errors.append(str(error))

    for node, name in PROCESSES[:]:
        attempt(stop, node, name)
    if PARENT_CREATED:
        attempt(on, "app-prod", "cleanup-restore-parent-mode", "chmod", "0755", APPS)
    if PROJECT is not None:
        listing = attempt(orbit, "cleanup-list-owned-instances", "instance:list")
        if listing:
            for row in listing["instances"]:
                if row["project_id"] == PROJECT:
                    attempt(orbit, "cleanup-destroy-instance", "instance:destroy", row["id"], "--yes", "--force")
        attempt(orbit, "cleanup-destroy-project", "project:destroy", PROJECT, "--yes")
        attempt(archive_cleanup, "cleanup-owned-node-archives")
        for node in ("app-dev", "app-prod"):
            attempt(on, node, "cleanup-empty-project-directory", "bash", "-seuc", 'if [ -d "$1" ]; then rmdir -- "$1"; fi; test ! -e "$1"', "--", APPS)
    if PROJECT is not None:
        for node in ("app-dev", "app-prod"):
            attempt(on, node, "cleanup-sqlite-receipts-and-snapshot-audit", "sudo", "python3", "-c", SEED_RECEIPTS, APPS, json.dumps(INSTANCES))
    attempt(python, "gateway", "cleanup-monitor-file", "import pathlib,sys; pathlib.Path(sys.argv[1]).unlink(missing_ok=True); assert not pathlib.Path(sys.argv[1]).exists()", MONITOR)
    listing = attempt(orbit, "cleanup-instance-and-route-audit", "instance:list")
    if listing:
        attempt(require, not any(r["project_id"] == PROJECT for r in listing["instances"]), "Fixture Instance remains")
    projects = attempt(orbit, "cleanup-project-audit", "project:list")
    if projects:
        attempt(require, not any(r["slug"] == SLUG for r in projects["projects"]), "Fixture Project remains")
    routes = attempt(orbit, "cleanup-route-audit", "route:list")
    if routes:
        attempt(require, not any(SLUG in r["domain"] for r in routes["routes"]), "Fixture Route remains")
    attempt(python, "gateway", "cleanup-gateway-staging-audit", "import glob,sys; paths=glob.glob(sys.argv[1]+'/orbit-transfer-*')+glob.glob('/tmp/orbit-transfer-*'); assert not paths, paths; print('GATEWAY_STAGING_AND_TMP_EMPTY')", STAGING)
    if errors:
        raise RuntimeError("Cleanup/audit failed: " + "; ".join(errors))
    print("PROOF_CLEANUP_COMPLETE", SLUG, flush=True)


try:
    print("PROOF_START", SLUG, subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(), flush=True)
    proof()
finally:
    cleanup()
print("PROOF_PASSED", SLUG, flush=True)
