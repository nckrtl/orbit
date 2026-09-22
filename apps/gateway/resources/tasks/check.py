"""Orbit-owned checks for an uncommitted task checkout. No caller-supplied commands."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import xml.etree.ElementTree as ET

PROJECTS = ('apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk')
COMMANDS = (('composer', 'validate', '--strict'), ('composer', 'check'), ('composer', 'test:affected'))
PROFILE = 'orbit-composer-v1'
MAX_SOURCE_BYTES = 12000


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def git(root, *arguments):
    return subprocess.check_output(['git', '-C', str(root), *arguments], stderr=subprocess.DEVNULL)


def files(root):
    names = git(root, 'ls-files', '--cached', '--others', '--exclude-standard', '-z').split(b'\0')
    result = {}
    for raw in sorted(set(names) - {b''}):
        name = raw.decode('utf-8', errors='strict')
        path = root / name
        if not path.parent.resolve().is_relative_to(root):
            raise ValueError('A source path traverses a symlink outside the checkout.')
        if not path.exists() and not path.is_symlink():
            result[name] = None
            continue
        mode = path.lstat().st_mode
        if stat.S_ISLNK(mode):
            target = os.readlink(path)
            if not path.resolve().is_relative_to(root):
                raise ValueError('External source symlinks cannot be verified.')
            result[name] = ['symlink', target]
        elif stat.S_ISREG(mode):
            result[name] = ['file', stat.S_IMODE(mode), hashlib.sha256(path.read_bytes()).hexdigest()]
        else:
            raise ValueError('Only source files and internal symlinks are supported.')
    return result


def identity(root, projects=PROJECTS):
    source = files(root)
    dependencies = {}
    for project in projects:
        path = root / project / 'vendor/composer/installed.json'
        dependencies[project] = hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None
    state = {
        'head': git(root, 'rev-parse', 'HEAD').decode().strip(),
        'branch': git(root, 'rev-parse', '--abbrev-ref', 'HEAD').decode().strip(),
        'source': digest(source),
        'dependencies': dependencies,
        'php': subprocess.check_output(['php', '-v'], stderr=subprocess.DEVNULL).decode().splitlines()[0],
        'profile': PROFILE,
    }
    return {**state, 'digest': digest(state)}


def snapshot(root, destination, manifest, projects):
    subprocess.run(['git', 'clone', '--shared', '--no-checkout', '--quiet', str(root), str(destination)],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(['git', '-C', str(destination), 'checkout', '--detach', '--quiet',
                    git(root, 'rev-parse', 'HEAD').decode().strip()], check=True)
    for name, entry in manifest.items():
        target = destination / name
        if target.is_symlink() or target.is_file():
            target.unlink()
        if entry is None:
            continue
        target.parent.mkdir(parents=True, exist_ok=True)
        if entry[0] == 'symlink':
            target.symlink_to(entry[1])
        else:
            shutil.copy2(root / name, target)
    if files(destination) != manifest:
        raise ValueError('Source changed while the snapshot was prepared.')
    for project in projects:
        vendor = root / project / 'vendor'
        if not vendor.is_dir() or vendor.is_symlink():
            raise ValueError('The task checkout needs installed project dependencies.')
        subprocess.run(['cp', '-a', '--reflink=auto', str(vendor), str(destination / project / 'vendor')], check=True)


def evidence_from_report(snapshot_root, project, report, references):
    evidence = {}
    if not report.is_file():
        return evidence
    # Reports are local process output, not user-uploaded XML. Refuse entities anyway.
    raw = report.read_bytes()
    if b'<!DOCTYPE' in raw or b'<!ENTITY' in raw or len(raw) > 16_000_000:
        raise ValueError('Test report is unsupported or too large.')
    cases = list(ET.fromstring(raw).iter('testcase'))
    for reference in references:
        if reference['project'] != project:
            continue
        relative = Path(reference['path'])
        if relative.is_absolute() or '..' in relative.parts or not str(relative).startswith('tests/'):
            raise ValueError('Evidence must name a test file inside its project.')
        source = snapshot_root / project / relative
        if source.is_symlink() or not source.resolve().is_relative_to(snapshot_root / project):
            raise ValueError('Evidence must not escape the project.')
        matches = [case for case in cases if case.get('name') == reference['test']
                   and (case.get('file') == str(relative) + '::' + reference['test']
                        or case.get('file') == str(source))]
        if len(matches) != 1 or any(matches[0].find(tag) is not None for tag in ('failure', 'error', 'skipped')):
            continue
        if int(matches[0].get('assertions', '0')) < 1:
            continue
        content = source.read_bytes()
        if len(content) > MAX_SOURCE_BYTES:
            continue
        evidence[reference['criterion_id']] = {
            'project': project, 'path': str(relative), 'test': reference['test'],
            'line': matches[0].get('line'), 'assertions': int(matches[0].get('assertions', '0')),
            'source': content.decode('utf-8', errors='strict'), 'source_digest': hashlib.sha256(content).hexdigest(),
            'result': 'passed',
        }
    return evidence


def execute(command, cwd, log, remaining):
    started = time.monotonic()
    with log.open('wb') as output:
        process = subprocess.Popen(command, cwd=cwd, stdout=output, stderr=subprocess.STDOUT,
                                   env={**os.environ, 'COMPOSER_PROCESS_TIMEOUT': '0'}, start_new_session=True)
        def interrupt(signum, frame):
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()
            raise InterruptedError('Task check interrupted.')
        handlers = {number: signal.signal(number, interrupt) for number in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP)}
        try:
            code = process.wait(timeout=max(0.01, remaining))
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()
            code = 124
        finally:
            for number, handler in handlers.items():
                signal.signal(number, handler)
    return {'command': list(command), 'exit_code': code, 'seconds': round(time.monotonic() - started, 3),
            'log': str(log), 'log_digest': hashlib.sha256(log.read_bytes()).hexdigest()}


def run(root, references, projects=PROJECTS, commands=COMMANDS, seconds=840):
    started = time.monotonic()
    runtime = Path(os.environ.get('ORBIT_HOME', str(Path.home() / '.orbit'))) / 'task-checks'
    runtime.mkdir(mode=0o700, parents=True, exist_ok=True)
    directory = Path(tempfile.mkdtemp(prefix='run-', dir=runtime))
    directory.chmod(0o700)
    tested = identity(root, projects)
    result = {'profile': PROFILE, 'identity': tested, 'checks': [], 'evidence': {}, 'passed': False,
              'unchanged': False, 'seconds': 0, 'log_directory': str(directory)}
    candidate = directory / 'source'
    try:
        snapshot(root, candidate, files(root), projects)
        if identity(root, projects) != tested:
            raise ValueError('Task inputs changed while preparing verification.')
        snapshot_inputs = files(candidate)
        for project in projects:
            for command in commands:
                if time.monotonic() - started >= seconds:
                    raise TimeoutError('Task checks exceeded their deadline.')
                log = directory / (str(len(result['checks'])) + '.log')
                check = execute(command, candidate / project, log, seconds - (time.monotonic() - started))
                result['checks'].append({'project': project, **check})
                if check['exit_code'] != 0:
                    return result
        for project, path in sorted({(ref['project'], ref['path']) for ref in references}):
            if time.monotonic() - started >= seconds:
                raise TimeoutError('Task checks exceeded their deadline.')
            relative = Path(path)
            if project not in projects or relative.is_absolute() or '..' in relative.parts or not path.startswith('tests/') or not path.endswith('.php'):
                raise ValueError('Evidence must name a PHP test file inside an allowed project.')
            source = candidate / project / relative
            if source.is_symlink() or not source.resolve().is_relative_to(candidate / project) or not source.is_file():
                raise ValueError('The referenced test file is unavailable.')
            report = directory / (str(len(result['checks'])) + '.xml')
            command = ('vendor/bin/pest', '--no-tia', '--compact', '--log-junit', str(report), path)
            check = execute(command, candidate / project, directory / (str(len(result['checks'])) + '.log'),
                            seconds - (time.monotonic() - started))
            result['checks'].append({'project': project, **check})
            if check['exit_code'] != 0:
                return result
            result['evidence'].update(evidence_from_report(candidate, project, report, references))
        result['unchanged'] = tested == identity(root, projects) and snapshot_inputs == files(candidate)
        result['passed'] = result['unchanged'] and all(check['exit_code'] == 0 for check in result['checks'])
        return result
    finally:
        result['seconds'] = round(time.monotonic() - started, 3)
        # Logs remain mode-0700 for diagnosis; the disposable source is never a task workspace.
        shutil.rmtree(candidate, ignore_errors=True)


def main():
    root = Path(sys.argv[1]).resolve(strict=True)
    mode = sys.argv[2]
    if mode == 'identity':
        result = identity(root)
    elif mode == 'run':
        references = json.loads(base64.b64decode(sys.argv[3], validate=True))
        if not isinstance(references, list) or not 1 <= len(references) <= 3:
            raise ValueError('One to three evidence references are required.')
        if any(reference['project'] not in PROJECTS for reference in references):
            raise ValueError('Unsupported Composer project.')
        result = run(root, references)
    else:
        raise ValueError('Unknown task verification operation.')
    print(json.dumps(result))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, TimeoutError, subprocess.SubprocessError, ET.ParseError):
        print(json.dumps({'error': 'Task verification could not produce a complete result.'}))
        sys.exit(1)
