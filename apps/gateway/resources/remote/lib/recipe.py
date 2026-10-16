# Library: recipe apt, package and source helpers shared by convergence and Docker migration.
"""Fixed, synchronous recipe operations. Only bounded package failure diagnostics escape this boundary."""
from contextlib import contextmanager, ExitStack
from datetime import datetime, timezone
import gzip
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import subprocess
import sys
import tarfile
import tempfile
import zipfile
from urllib.parse import urlsplit

POLICY = Path('/usr/sbin/policy-rc.d')
APT_ETC = Path('/etc/apt')
DPKG_LOG = Path('/var/log/dpkg.log')
UBUNTU_KEYRING = '/usr/share/keyrings/ubuntu-archive-keyring.gpg'
MAX_SNAPSHOT_LOOKUPS = 3


class RecipeFailure(Exception):
    def __init__(self, stage='', *, result=None, packages=None):
        super().__init__(stage)
        self.result = result
        self.packages = packages or []


class RecipeInterrupted(RecipeFailure):
    pass


def command_output(result):
    """Node convergence keeps manager output private; image builds override this hook for root-only logs."""
    pass


def run(argv, *, required=True, env=None, timeout=600):
    try:
        with subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                              start_new_session=True, env=env) as process:
            try:
                stdout, stderr = process.communicate(timeout=timeout)
            except BaseException as error:
                os.killpg(process.pid, signal.SIGTERM)
                try:
                    stdout, stderr = process.communicate(timeout=3)
                except subprocess.TimeoutExpired:
                    os.killpg(process.pid, signal.SIGKILL)
                    stdout, stderr = process.communicate()
                result = subprocess.CompletedProcess(argv, process.returncode, stdout, stderr)
                command_output(result)
                if isinstance(error, RecipeInterrupted):
                    raise
                raise RecipeFailure(result=result) from error
            result = subprocess.CompletedProcess(argv, process.returncode, stdout, stderr)
    except FileNotFoundError:
        result = subprocess.CompletedProcess(argv, 127, '', 'Executable not found: ' + argv[0] + '\n')
    command_output(result)
    if required and result.returncode:
        raise RecipeFailure(result=result)
    return result


def platform_check(architecture):
    values = {}
    for line in Path('/etc/os-release').read_text().splitlines():
        key, sep, value = line.partition('=')
        if sep and key in ('ID', 'VERSION_CODENAME'):
            if not re.fullmatch(r'''(?:[A-Za-z0-9._-]+|"[A-Za-z0-9._-]+"|'[A-Za-z0-9._-]+')''', value):
                raise RecipeFailure('platform:unknown:unknown')
            value = value.strip('"\'')
            if key in values or not re.fullmatch(r'[A-Za-z0-9._-]+', value):
                raise RecipeFailure('platform:unknown:unknown')
            values[key] = value
    if values != {'ID': 'ubuntu', 'VERSION_CODENAME': 'resolute'}:
        raise RecipeFailure('platform:' + values.get('ID', 'unknown') + ':' + values.get('VERSION_CODENAME', 'unknown'))
    if run(['dpkg', '--print-architecture']).stdout.strip() != architecture:
        raise RecipeFailure('architecture')


def packages():
    architecture = run(['dpkg', '--print-architecture']).stdout.strip()
    result = run(['dpkg-query', '-W', '-f=${Package}\t${Architecture}\t${Version}\t${Status}\n'])
    return {name: version for name, arch, version, status in
            (line.split('\t', 3) for line in result.stdout.splitlines())
            if status == 'install ok installed' and arch in (architecture, 'all')}


def ubuntu_origin(package, version, options=None, snapshots=None):
    origins = [policy_origin(package, version, [])]
    if options is not None:
        origins.append(policy_origin(package, version, options))
    # Check every listing before accepting any trusted listing, including cached snapshots.
    if any(untrusted for trusted, untrusted, status in origins):
        return False
    if any(trusted for trusted, untrusted, status in origins):
        return True
    return any(status for trusted, untrusted, status in origins) and snapshot_origin(package, version, snapshots)


def install_snapshot(package, version):
    """Prefer the version's first install/upgrade action; triggers rewrite status records."""
    architecture = run(['dpkg', '--print-architecture']).stdout.strip()
    action = status = None
    try:
        for path in DPKG_LOG.parent.glob(DPKG_LOG.name + '*'):
            opener = gzip.open if path.suffix == '.gz' else open
            with opener(path, 'rt', encoding='utf-8', errors='replace') as stream:
                for line in stream:
                    fields = line.split()
                    if len(fields) != 6 or fields[5] != version:
                        continue
                    is_action = fields[2] in ('install', 'upgrade')
                    name = fields[3] if is_action else fields[4]
                    if (not is_action and fields[2:4] != ['status', 'installed']
                            or name not in (package, package + ':' + architecture, package + ':all')):
                        continue
                    installed = datetime.strptime(' '.join(fields[:2]), '%Y-%m-%d %H:%M:%S').astimezone(timezone.utc)
                    if is_action:
                        action = min(action, installed) if action else installed
                    else:
                        status = min(status, installed) if status else installed
    except (OSError, EOFError, ValueError):
        return None
    installed = action or status
    return installed.strftime('%Y%m%dT%H%M%SZ') if installed else None


def apt_environment(options):
    environment = dict(os.environ, LC_ALL='C')
    if '-c' in options:
        environment['APT_CONFIG'] = options[options.index('-c') + 1]
    return environment


@contextmanager
def temporary_indexes(source):
    """Read authenticated indexes without system hooks, locks, caches, or list writes."""
    with tempfile.TemporaryDirectory(prefix='orbit-recipe-indexes-') as directory:
        work = Path(directory)
        (work / 'ubuntu.sources').write_text(source)
        for name in ('lists', 'cache', 'log'):
            (work / name).mkdir()
        config = work / 'apt.conf'
        # APT_CONFIG is read before the normal apt.conf.d hooks. Apply the same configuration
        # to apt-cache so it can only inspect these temporary, authenticated lists.
        config.write_text('Dir::Etc::parts "-";\nDir::Etc::main "-";\n')
        options = ['-c', str(config), '-o', 'Dir::Etc::sourcelist=' + str(work / 'ubuntu.sources'),
                   '-o', 'Dir::Etc::sourceparts=-', '-o', 'Dir::State::lists=' + str(work / 'lists'),
                   '-o', 'Dir::Cache=' + str(work / 'cache'), '-o', 'Dir::Log=' + str(work / 'log'),
                   '-o', 'Debug::NoLocking=true', '-o', 'APT::Sandbox::User=',
                   '-o', 'Acquire::Languages=none', '-o', 'Acquire::Retries=0',
                   '-o', 'Acquire::http::Timeout=15', '-o', 'Acquire::https::Timeout=15',
                   '-o', 'APT::Update::Error-Mode=any']
        run(['apt-get', 'update', *options], env=apt_environment(options), timeout=60)
        yield options


class SnapshotLookups(ExitStack):
    """Keep authenticated indexes and failed lookups per timestamp until this run ends."""
    def __init__(self):
        super().__init__()
        self.lookups = {}

    def options(self, snapshot):
        if snapshot not in self.lookups:
            if len(self.lookups) >= MAX_SNAPSHOT_LOOKUPS:
                raise RecipeFailure('snapshot-lookup-limit')
            self.lookups[snapshot] = 'snapshot-unavailable'
            try:
                self.lookups[snapshot] = self.enter_context(temporary_indexes(snapshot_source(snapshot)))
            except RecipeInterrupted:
                raise
            except (RecipeFailure, OSError):
                pass
        result = self.lookups[snapshot]
        if isinstance(result, str):
            raise RecipeFailure(result)
        return result


def snapshot_origin(package, version, snapshots=None):
    if snapshots is None:
        with SnapshotLookups() as snapshots:
            return snapshot_origin(package, version, snapshots)
    snapshot = install_snapshot(package, version)
    if snapshot is None:
        raise RecipeFailure('dpkg-log-missing')
    try:
        options = snapshots.options(snapshot)
        trusted, untrusted, status = policy_origin(package, version, options)
    except RecipeInterrupted:
        raise
    except RecipeFailure as error:
        raise RecipeFailure(str(error) or 'snapshot-unavailable') from error
    except OSError as error:
        raise RecipeFailure('snapshot-unavailable') from error
    if not trusted or untrusted:
        raise RecipeFailure('snapshot-unproved')
    return True


def deb822_fields(paragraph):
    fields = {}
    key = None
    for line in paragraph.splitlines():
        if not line.strip() or line.startswith('#'):
            continue
        if line[:1].isspace():
            if key is None:
                raise RecipeFailure('apt-source')
            fields[key] += ' ' + line.strip()
            continue
        key, separator, value = line.partition(':')
        key = key.lower()
        if not separator or key in fields or not re.fullmatch(r'[a-z][a-z0-9-]*', key):
            raise RecipeFailure('apt-source')
        fields[key] = value.strip()
    return fields


def ubuntu_signed_sources(options):
    """Match the source URI, suite and component to an explicitly scoped Ubuntu keyring."""
    source = next((option.split('=', 1)[1] for option in options if option.startswith('Dir::Etc::sourcelist=')), None)
    paths = [Path(source)] if source else [APT_ETC / 'sources.list',
        *sorted((APT_ETC / 'sources.list.d').glob('*.sources')),
        *sorted((APT_ETC / 'sources.list.d').glob('*.list'))]
    trusted = set()
    for path in paths:
        if not path.is_file():
            continue
        document = path.read_text()
        entries = []
        if path.suffix == '.sources':
            for paragraph in re.split(r'\n\s*\n', document):
                fields = deb822_fields(paragraph)
                if (fields.get('signed-by') == UBUNTU_KEYRING and 'deb' in fields.get('types', '').split()
                        and fields.get('enabled', 'yes').lower() != 'no'):
                    entries.append((fields.get('uris', '').split(), fields.get('suites', '').split(), fields.get('components', '').split()))
        else:
            for line in document.splitlines():
                entry = re.fullmatch(r'\s*deb\s+\[([^]]+)\]\s+(\S+)\s+(\S+)\s+([^#]+)(?:#.*)?', line)
                if entry and ('signed-by=' + UBUNTU_KEYRING) in entry.group(1).split():
                    entries.append(([entry.group(2)], [entry.group(3)], entry.group(4).split()))
        for uris, suites, components in entries:
            trusted.update((uri.rstrip('/'), suite + '/' + component)
                           for uri in uris for suite in suites for component in components)
    return trusted


def ubuntu_index(index, signed_sources):
    fields = index.split()
    if len(fields) != 4:
        return False
    uri, suite, architecture, kind = fields
    url = urlsplit(uri)
    host = url.hostname or ''
    ubuntu_host = host in ('archive.ubuntu.com', 'security.ubuntu.com', 'ports.ubuntu.com', 'snapshot.ubuntu.com') or host.endswith('.archive.ubuntu.com')
    return url.scheme in ('http', 'https') and (ubuntu_host or (uri.rstrip('/'), suite) in signed_sources)


def policy_origin(package, version, options):
    """Classify every index for this exact version; a newer candidate proves nothing."""
    environment = apt_environment(options)
    policy = run(['apt-cache', 'policy', *options], env=environment).stdout
    trusted = set()
    signed_sources = ubuntu_signed_sources(options)
    index = None
    for line in policy.splitlines():
        entry = re.fullmatch(r'\s*-?\d+\s+(.+ Packages)\s*', line)
        if entry:
            index = ' '.join(entry.group(1).split())
        elif line.strip().startswith('release '):
            fields = dict(item.split('=', 1) for item in line.strip()[8:].split(',') if '=' in item)
            if (index and ubuntu_index(index, signed_sources) and fields.get('o') == 'Ubuntu' and fields.get('n') == 'resolute'
                    and fields.get('a') in ('resolute', 'resolute-updates', 'resolute-security')):
                trusted.add(index)
            index = None
    architecture = run(['dpkg', '--print-architecture']).stdout.strip()
    policy = run(['apt-cache', 'policy', package + ':' + architecture, *options], env=environment).stdout
    selected = None
    found_trusted = found_untrusted = status = False
    for line in policy.splitlines():
        entry = re.fullmatch(r'\s*(?:\*\*\*\s+)?(\S+)\s+-?\d+(?:\s+\([^)]*\))?\s*', line)
        if entry:
            selected = entry.group(1)
            continue
        entry = re.fullmatch(r'\s*-?\d+\s+(.+?)\s*', line)
        if selected == version and entry:
            index = ' '.join(entry.group(1).split())
            if index == '/var/lib/dpkg/status':
                status = True
            elif index in trusted:
                found_trusted = True
            else:
                found_untrusted = True
        if not entry:
            selected = None
    return found_trusted, found_untrusted, status


def assess(tools, versions=None, options=None, snapshots=None, git_names=()):
    """Ubuntu packages have floors. Artifacts, including the Sury PHP build, stay exact."""
    if snapshots is None:
        with SnapshotLookups() as snapshots:
            return assess(tools, versions, options, snapshots, git_names)
    versions = inspect(tools) if versions is None else versions
    report = {}
    for name, pin in tools.items():
        installed = versions[name]
        status = 'current' if installed == pin['version'] else 'missing' if installed is None else 'different'
        reason = ''
        if name in git_names and pin['source'] == 'artifact' and installed is not None and installed != pin['version']:
            try:
                if not git_owned(name, installed, git_names) and not ubuntu_origin(name, installed, options, snapshots):
                    status = 'unsupported_newer'
            except RecipeInterrupted:
                raise
            except RecipeFailure as error:
                status = 'unsupported_newer'
                reason = ':' + str(error)
        if pin['source'] == 'ubuntu' and installed is not None and installed != pin['version']:
            comparison = run(['dpkg', '--compare-versions', installed, 'gt', pin['version']], required=False)
            if comparison.returncode == 0:
                try:
                    status = 'newer_than_recipe' if ubuntu_origin(pin['package'], installed, options, snapshots) else 'unsupported_newer'
                except RecipeInterrupted:
                    raise
                except RecipeFailure as error:
                    status = 'unsupported_newer'
                    reason = ':' + str(error)
            elif comparison.returncode == 1:
                status = 'older_than_recipe'
            else:
                raise RecipeFailure('inventory:' + name)
        report[name] = {'source': pin['source'], 'version': pin['version'], 'installed': installed,
                        'changed': status not in ('current', 'newer_than_recipe', 'unsupported_newer'), 'status': status}
        if status == 'unsupported_newer':
            report[name]['refusal'] = 'origin:' + name + reason
    return report


def audit_packages(environment):
    result = run(['dpkg', '--audit'], env=environment)
    # dpkg reports incomplete packages on stdout but can still exit successfully.
    if result.stdout.strip() or result.stderr.strip():
        raise RecipeFailure('packages', result=result)


def supported(report):
    for tool in report.values():
        if tool['status'] == 'unsupported_newer':
            raise RecipeFailure(tool['refusal'])


def newer_packages(report):
    return {name: {'installed': tool['installed'], 'version': tool['version']}
            for name, tool in report.items() if tool['status'] == 'newer_than_recipe'}


def snapshot_source(snapshot):
    # The snapshot /ubuntu/ archive includes arm64. /ubuntu-ports/ is not public.
    return (f"Types: deb\nURIs: https://snapshot.ubuntu.com/ubuntu/{snapshot}/\n"
              'Suites: resolute resolute-updates resolute-security\nComponents: main universe restricted multiverse\n'
              'Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\nCheck-Valid-Until: no\n')


def ubuntu_sources_repair():
    path = APT_ETC / 'sources.list.d/ubuntu.sources'
    if not path.is_file() or path.is_symlink():
        return None
    content = path.read_bytes()
    match = re.fullmatch(rb'Types: deb\nURIs: https://snapshot\.ubuntu\.com/ubuntu/(\d{8}T\d{6}Z)/\n.*', content, re.DOTALL)
    if not match or content != snapshot_source(match.group(1).decode()).encode():
        return None
    return content


def rolling_source(architecture):
    archive = 'http://ports.ubuntu.com/ubuntu-ports' if architecture == 'arm64' else 'http://archive.ubuntu.com/ubuntu'
    security = 'http://ports.ubuntu.com/ubuntu-ports' if architecture == 'arm64' else 'http://security.ubuntu.com/ubuntu'
    return (f'Types: deb\nURIs: {archive}\nSuites: resolute resolute-updates resolute-backports\n'
              f'Components: main universe restricted multiverse\nSigned-By: {UBUNTU_KEYRING}\n\n'
              f'Types: deb\nURIs: {security}\nSuites: resolute-security\n'
              f'Components: main universe restricted multiverse\nSigned-By: {UBUNTU_KEYRING}\n')


def backup_apt_source(path, content, owner, *, check_only=False):
    """All source backups live directly in /etc/apt, outside APT's source directories."""
    backup = APT_ETC / (path.name + '.orbit-' + owner + '-backup')
    if backup.exists() or backup.is_symlink():
        if (backup.is_symlink() or not backup.is_file() or backup.read_bytes() != content):
            raise RecipeFailure('apt-source')
    elif not check_only:
        publish(backup, content)
    return backup


def git_packages(payload):
    return {name: payload['tools'][name] for name in payload.get('git_packages', []) if name in payload['tools']}


def check_git_siblings(payload):
    tools = git_packages(payload)
    if not tools:
        return
    output = run(['dpkg-query', '-W', '-f=${Package}\t${source:Package}\t${db:Status-Status}\n']).stdout
    for line in output.splitlines():
        name, source, status = line.split('\t', 2)
        if status == 'installed' and source == 'git' and name not in tools:
            raise RecipeFailure('git-dependency:' + name)


def repair_ubuntu_sources(architecture):
    content = ubuntu_sources_repair()
    if content is None:
        return False
    path = APT_ETC / 'sources.list.d/ubuntu.sources'
    backup = backup_apt_source(path, content, 'snapshot')
    publish(path, rolling_source(architecture).encode())
    run(['logger', '--tag', 'orbit-recipe', '--', 'Restored generic Ubuntu sources in ' + str(path) + '; backup: ' + str(backup)])
    return True


def git_owned(name, version, names):
    """Only root's receipt of a verified recipe install can carry ownership across a later pin bump."""
    path = APT_ETC / 'orbit-git.json'
    if path.is_symlink() or not path.is_file() or path.stat().st_uid != 0 or path.stat().st_mode & 0o022:
        return False
    try:
        receipt = json_file(path)
    except (OSError, ValueError):
        return False
    if set(receipt) != set(names):
        return False
    if any(not isinstance(value, str) or not re.fullmatch(r'[0-9][0-9A-Za-z.+:~_-]*', value)
           for value in receipt.values()):
        return False
    return receipt[name] == version and len(set(receipt.values())) == 1


def record_git(payload, versions):
    tools = git_packages(payload)
    if not tools:
        return
    if any(versions.get(name) != pin['version'] for name, pin in tools.items()):
        raise RecipeFailure('inventory:git')
    publish(APT_ETC / 'orbit-git.json', json.dumps({name: pin['version'] for name, pin in tools.items()}, sort_keys=True).encode())


def git_sources():
    """Retire only git-core's resolute/main entries; preserve unrelated source paragraphs and inline keys."""
    changes = []
    paths = [APT_ETC / 'sources.list', *sorted((APT_ETC / 'sources.list.d').glob('*.sources')),
             *sorted((APT_ETC / 'sources.list.d').glob('*.list'))]
    for path in paths:
        if not path.is_file():
            continue
        content = path.read_text()
        if not re.search(r'(?:ppa\.launchpadcontent\.net|ppa\.launchpad\.net)/git-core/ppa', content):
            continue
        if path.is_symlink():
            raise RecipeFailure('apt-source')
        blocks = re.split(r'(\n\s*\n)', content) if path.suffix == '.sources' else content.splitlines(keepends=True)
        result = []
        for block in blocks:
            active = '\n'.join(line for line in block.splitlines() if not line.lstrip().startswith('#'))
            if not re.search(r'(?:ppa\.launchpadcontent\.net|ppa\.launchpad\.net)/git-core/ppa', active):
                result.append(block)
                continue
            if path.suffix == '.sources':
                fields = deb822_fields(block)
                uris = fields.get('uris', '').split()
                suites = fields.get('suites', '').split()
                components = fields.get('components', '').split()
                types = fields.get('types', '').split()
            else:
                entry = re.fullmatch(r'\s*(deb(?:-src)?)\s+(?:\[[^]]+\]\s+)?(\S+)\s+(\S+)\s+([^#]+?)(?:\s*#.*)?\s*', block)
                if not entry:
                    raise RecipeFailure('apt-source')
                types, uris, suites, components = [entry[1]], [entry[2]], [entry[3]], entry[4].split()
            allowed = {scheme + '://' + host + '/git-core/ppa/ubuntu'
                       for scheme in ('http', 'https') for host in ('ppa.launchpad.net', 'ppa.launchpadcontent.net')}
            if (not uris or any(uri.rstrip('/') not in allowed for uri in uris)
                    or suites != ['resolute'] or components != ['main'] or not types
                    or set(types) - {'deb', 'deb-src'}):
                raise RecipeFailure('apt-source')
        replacement = ''.join(result)
        if replacement != content:
            changes.append((path, content.encode(), replacement.encode()))
    return changes


def git_preferences(tools):
    return ''.join(f'Package: {name}\nPin: version {tools[name]["version"]}\nPin-Priority: 1001\n\n'
                   f'Package: {name}\nPin: version *\nPin-Priority: -1\n\n' for name in tools).encode()


def configure_git(payload, *, apply=False):
    tools = git_packages(payload)
    if not tools:
        return []
    changes = git_sources()
    preference = APT_ETC / 'preferences.d/orbit-git'
    if preference.is_symlink():
        raise RecipeFailure('apt-source')
    expected = git_preferences(tools)
    pin_changed = not preference.is_file() or preference.read_bytes() != expected
    actions = (['retire_git_source'] if changes else []) + (['pin_git'] if pin_changed else [])
    for path, before, _after in changes:
        backup_apt_source(path, before, 'git', check_only=True)
    if apply:
        retire_sources(changes, 'git')
    return actions


def retire_sources(changes, owner):
    """Retire validated entries with the same durable backup contract for Git and Docker."""
    for path, before, after in changes:
        backup_apt_source(path, before, owner)
        if after.strip():
            publish(path, after)
        else:
            path.unlink()


def pin_git(payload):
    tools = git_packages(payload)
    if tools:
        publish(APT_ETC / 'preferences.d/orbit-git', git_preferences(tools))


def plan(payload):
    check_git_siblings(payload)
    actions = configure_git(payload)
    if ubuntu_sources_repair() is None:
        report = assess(payload['tools'], git_names=git_packages(payload))
    else:
        with temporary_indexes(rolling_source(payload['architecture'])) as options:
            report = assess(payload['tools'], options=options, git_names=git_packages(payload))
        actions.insert(0, 'repair_ubuntu_sources')
    return {**report, **({'_actions': actions} if actions else {})}


def snapshot_options(payload, work):
    source = snapshot_source(payload['snapshot'])
    path = work / 'ubuntu.sources'
    publish(path, source.encode())
    # Keep normal sources and their cached origin metadata for unattended-upgrades and read-only plans.
    return ['-o', 'Dir::Etc::sourcelist=' + str(path), '-o', 'Dir::Etc::sourceparts=-',
            '-o', 'APT::Get::List-Cleanup=false']


def package_command(argv, packages, **kwargs):
    try:
        return run(argv, **kwargs)
    except RecipeFailure as error:
        error.packages = packages
        raise


def install_exact_debs(debs, tools, installed, options, environment):
    """APT's downgrade switch is global. Check its plan and hold every Ubuntu floor in place."""
    artifacts = {Path(deb).stem for deb in debs}
    retained = [pin['package'] + '=' + installed[pin['package']] for pin in tools.values()
                if pin['source'] == 'ubuntu' and pin['package'] in installed]
    argv = ['apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove', '--allow-downgrades',
            '-o', 'Dpkg::Options::=--force-confold', *options, '--', *debs, *retained]
    environment = dict(environment, LC_ALL='C')
    plan = package_command([*argv[:2], '--simulate', *argv[2:]], sorted(artifacts), env=environment)
    for line in plan.stdout.splitlines():
        if not line.startswith('Inst '):
            continue
        entry = re.match(r'Inst (\S+)(?: \[([^]]+)\])? \((\S+)', line)
        if not entry:
            raise RecipeFailure(packages=sorted(artifacts))
        name, old, new = entry.groups()
        name = name.split(':', 1)[0]
        old = old or installed.get(name)
        if old is not None and name not in artifacts:
            comparison = run(['dpkg', '--compare-versions', new, 'lt', old], required=False)
            if comparison.returncode != 1:
                raise RecipeFailure(packages=[name])
    package_command(argv, sorted(artifacts), env=environment)


def failure_output(value):
    """Redact before bounding output; the Gateway also applies its shared activity sanitizer."""
    value = re.sub(r'-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----', '[REDACTED]', value)
    value = re.sub(r'(?i)\b([a-z][a-z0-9+.-]*://)[^/@\s]+@', r'\1[REDACTED]@', value)
    value = re.sub(r'(?i)\b((?:proxy-)?authorization)\s*:[^\r\n]*', r'\1: [REDACTED]', value)
    value = re.sub(r"(?i)\b([\w-]*(?:token|secret|password|passwd|passphrase|credential|key|cookie|authorization)[\w-]*)\s*[:=]\s*(?:\"[^\"]*\"|'[^']*'|[^\s&#]+)", r'\1=[REDACTED]', value)
    value = re.sub(r'(?i)\bBearer\s+\S+', 'Bearer [REDACTED]', value)
    value = re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]', '', value)
    return value.encode('utf-8', errors='replace')[-8000:].decode('utf-8', errors='replace')


def binary_version(binary, env=None):
    result = run([str(binary), '--version'], required=False, env=env)
    match = re.search(r'(?:^|[\s v])([0-9]+\.[0-9]+\.[0-9]+)\b', result.stdout) if result.returncode == 0 else None
    return match.group(1) if match else None


def json_file(path):
    try:
        value = json.loads(path.read_text())
        return value if isinstance(value, dict) else {}
    except FileNotFoundError:
        return {}


def inspect(tools):
    installed = packages()
    versions = {}
    javascript = set(tools) & {'node', 'pnpm', 'bun', 'vp', 'vp-cli'}
    scope = None
    if javascript:
        account = pwd.getpwnam(os.environ['SUDO_USER']) if os.environ.get('SUDO_USER') else pwd.getpwuid(os.getuid())
        scope = vite_scope(account)
    for name, pin in tools.items():
        if pin['source'] == 'ubuntu' or (pin['source'] == 'artifact' and any(a['url'].endswith('.deb') for a in pin.get('artifacts', {}).values())):
            versions[name] = installed.get(pin['package'] if pin['source'] == 'ubuntu' else name)
        elif name == 'bun':
            versions[name] = binary_version('/opt/orbit/bun/bin/bun')
        elif name == 'vp-cli':
            binary = (scope / 'bin/vp').resolve()
            # Even --version can bootstrap an unmarked Vite+ executable. Never invoke one in a read.
            ready = binary.is_file() and (binary.parent / '.vp-setup-complete').is_file()
            versions[name] = binary_version(binary, dict(os.environ, VP_HOME=str(scope))) if ready else None
        elif name == 'vp':
            metadata = scope / 'recipe/vp-current/package.json'
            if not metadata.is_file():
                metadata = scope / 'current/node_modules/vite-plus/package.json'
            versions[name] = json_file(metadata).get('version')
        elif name == 'pnpm':
            versions[name] = json_file(scope / 'recipe/pnpm-current/package.json').get('version')
        elif name == 'node':
            # A moving default can fetch a runtime even for node --version. Inspect exact cached defaults directly.
            default = json_file(scope / 'config.json').get('defaultNodeVersion', '')
            binary = scope / 'js_runtime/node' / default / 'bin/node' if isinstance(default, str) and re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', default) else None
            versions[name] = binary_version(binary) if binary and binary.is_file() else None
        else:
            raise RecipeFailure()
        if versions[name] is not None and not isinstance(versions[name], str):
            raise RecipeFailure()
    return versions


def safe_directory(path, uid=0, gid=0):
    path = Path(path)
    if path.is_symlink() or (path.exists() and (not path.is_dir() or path.stat().st_uid != uid)):
        raise RecipeFailure()
    if not path.exists():
        path.mkdir(mode=0o755)
        os.chown(path, uid, gid)
    return path


def publish(path, content, mode=0o644, uid=0, gid=0, replace_link=False):
    path = Path(path)
    if (path.is_symlink() and (not replace_link or path.lstat().st_uid != uid)) or (not path.is_symlink() and path.exists() and (not path.is_file() or path.stat().st_uid != uid)):
        raise RecipeFailure()
    fd, candidate = tempfile.mkstemp(prefix='.orbit-recipe-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
        os.chmod(candidate, mode)
        os.chown(candidate, uid, gid)
        os.replace(candidate, path)
    finally:
        Path(candidate).unlink(missing_ok=True)


def download(pin, architecture, destination):
    artifact = pin['artifacts'][architecture]
    algorithm = 'sha256' if 'sha256' in artifact else 'sha512'
    url = artifact['url']
    if not url.startswith('https://'):
        raise RecipeFailure()
    # curl permits HTTPS redirects only, including GitHub's release-asset redirect.
    run(['curl', '--fail', '--silent', '--show-error', '--location', '--proto', '=https',
         '--proto-redir', '=https', '--connect-timeout', '15', '--max-time', '600',
         '--output', str(destination), '--', url])
    with destination.open('rb') as stream:
        digest = hashlib.file_digest(stream, algorithm).hexdigest()
    if digest != artifact[algorithm]:
        raise RecipeFailure()
    return destination


def download_deb(name, pin, payload, destination):
    artifact = pin['artifacts'][payload['architecture']]
    last_failure = None
    for url in artifact_candidates(name, artifact, payload.get('artifact_mirror', '')):
        selected = {**pin, 'artifacts': {payload['architecture']: {**artifact, 'url': url}}}
        try:
            return download(selected, payload['architecture'], destination)
        except RecipeInterrupted:
            raise
        except RecipeFailure as error:
            # A digest mismatch has no failed curl result and must never fall back.
            if error.result is None:
                raise
            last_failure = error
    raise last_failure or RecipeFailure()


def extract(archive, destination):
    destination.mkdir()
    if zipfile.is_zipfile(archive):
        with zipfile.ZipFile(archive) as source:
            for member in source.infolist():
                if member.filename.startswith('/') or '..' in Path(member.filename).parts or (member.external_attr >> 16) & 0o170000 == 0o120000:
                    raise RecipeFailure()
            source.extractall(destination)
    else:
        with tarfile.open(archive) as source:
            source.extractall(destination, filter='data')
    return destination


def active_services():
    output = run(['systemctl', 'list-units', '--type=service', '--state=active', '--no-legend', '--plain']).stdout
    return {line.split()[0] for line in output.splitlines() if line.strip()}


def services_for(changed, before_active):
    services = set()
    for package in changed:
        output = run(['dpkg-query', '-L', package], required=False).stdout
        for filename in output.splitlines():
            if re.fullmatch(r'/(?:usr/)?lib/systemd/system/[A-Za-z0-9@_.:-]+\.service', filename):
                unit = Path(filename).name
                if '@.service' in unit:
                    prefix = unit.removesuffix('@.service') + '@'
                    services.update(active for active in before_active if active.startswith(prefix) and active.endswith('.service'))
                else:
                    services.add(unit)
        php = re.match(r'php([0-9]+\.[0-9]+)-', package)
        if php:
            services.add('php' + php.group(1) + '-fpm.service')
    return sorted(services & before_active)


def vite_scope(account):
    home = Path(account.pw_dir)
    candidates = [Path('/opt/orbit/vite-plus'), home / '.vite-plus', home / '.local/share/vite-plus']
    for candidate in candidates:
        if candidate.is_symlink() or (candidate.exists() and (not candidate.is_dir() or candidate.stat().st_uid != account.pw_uid)):
            raise RecipeFailure()
        if (candidate / 'bin/vp').exists():
            return candidate
    return home / '.local/share/vite-plus'


def activate_vite_package(scope, package, uid, gid):
    active = scope / 'current/node_modules/vite-plus'
    if not active.is_symlink() or active.lstat().st_uid != uid:
        raise RecipeFailure()
    previous = active.resolve(strict=True)
    manifest = json_file(package / 'package.json')
    dependencies = dict(manifest.get('dependencies', {}))
    architecture = 'x64' if run(['dpkg', '--print-architecture']).stdout.strip() == 'amd64' else 'arm64'
    native = '@voidzero-dev/vite-plus-linux-' + architecture + '-gnu'
    if native in manifest.get('optionalDependencies', {}):
        dependencies[native] = manifest['optionalDependencies'][native]
    # Retain the existing dependency closure only when its exact versions satisfy this distribution.
    # Missing/new dependencies require recipe pins; never let Vite+'s installer fetch them implicitly.
    resolved = {}
    for name, constraint in dependencies.items():
        expected = constraint.rsplit('@', 1)[-1] if constraint.startswith('npm:') else constraint.lstrip('=')
        dependency = previous / 'node_modules' / name
        if not dependency.exists():
            dependency = previous.parent / name
        if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?', expected) or json_file(dependency / 'package.json').get('version') != expected:
            raise RecipeFailure()
        resolved[name] = dependency.resolve(strict=True)
    modules = safe_directory(package / 'node_modules', uid, gid)
    for name, dependency in resolved.items():
        destination = modules / name
        if name.startswith('@'):
            safe_directory(destination.parent, uid, gid)
        if not destination.exists():
            destination.symlink_to(dependency, target_is_directory=True)
            os.chown(destination, uid, gid, follow_symlinks=False)
    candidate = active.parent / '.orbit-recipe-vite-plus'
    if candidate.exists() or candidate.is_symlink():
        raise RecipeFailure()
    candidate.symlink_to(package, target_is_directory=True)
    os.chown(candidate, uid, gid, follow_symlinks=False)
    os.replace(candidate, active)


def package_link(runtime, name, package, uid, gid):
    alias = runtime / (name + '-current')
    if alias.exists() or alias.is_symlink():
        if not alias.is_symlink() or alias.lstat().st_uid != uid:
            raise RecipeFailure()
    candidate = runtime / (name + '-candidate')
    if candidate.exists() or candidate.is_symlink():
        raise RecipeFailure()
    candidate.symlink_to(package, target_is_directory=True)
    os.chown(candidate, uid, gid, follow_symlinks=False)
    os.replace(candidate, alias)


def install_javascript(tools, downloads, work, account):
    """Keep the existing Vite+ scope and global store; replace only its pinned runtime inputs."""
    uid, gid = account.pw_uid, account.pw_gid
    home = Path(account.pw_dir)
    scope = vite_scope(account)
    if not scope.exists():
        safe_directory(home / '.local', uid, gid)
        safe_directory(home / '.local/share', uid, gid)
    safe_directory('/opt/orbit')
    safe_directory(scope, uid, gid)
    bin_dir = safe_directory(scope / 'bin', uid, gid)
    runtime = safe_directory(scope / 'recipe', uid, gid)
    for name in ('node', 'vp', 'vp-cli', 'pnpm', 'bun'):
        if name not in downloads:
            continue
        unpacked = extract(downloads[name], work / ('unpack-' + name))
        # Every directory is content-addressed; no mutable installer/channel or package resolution runs.
        identifier = hashlib.sha256(downloads[name].read_bytes()).hexdigest()
        target = runtime / (name + '-' + identifier)
        if not target.exists():
            shutil.copytree(unpacked, target, symlinks=True)
            for parent, directories, files in os.walk(target):
                os.chown(parent, uid, gid)
                for entry in directories + files:
                    os.chown(Path(parent) / entry, uid, gid, follow_symlinks=False)
        safe_directory(target, uid, gid)
        if name == 'node':
            root = next(target.iterdir())
            cache = safe_directory(scope / 'js_runtime', uid, gid)
            cache = safe_directory(cache / 'node', uid, gid)
            cached = cache / tools[name]['version']
            if cached.is_symlink():
                if cached.lstat().st_uid != uid:
                    raise RecipeFailure()
                cached.unlink()
            elif cached.exists():
                safe_directory(cached, uid, gid)
                shutil.rmtree(cached)
            cached.symlink_to(root, target_is_directory=True)
            os.chown(cached, uid, gid, follow_symlinks=False)
        elif name == 'vp-cli':
            publish(bin_dir / 'vp', (target / 'vp').read_bytes(), 0o755, uid, gid, replace_link=True)
            # Standalone Vite+ otherwise runs an unpinned installer on its first invocation.
            publish(bin_dir / '.vp-setup-complete', b'', 0o644, uid, gid)
        elif name == 'vp':
            # Retain the separately pinned JS distribution alongside the standalone dispatcher.
            if json.loads((target / 'package/package.json').read_text())['version'] != tools[name]['version']:
                raise RecipeFailure()
            activate_vite_package(scope, target / 'package', uid, gid)
            package_link(runtime, 'vp', target / 'package', uid, gid)
        elif name == 'pnpm':
            package_link(runtime, 'pnpm', target / 'package', uid, gid)
            native = target / 'package/pnpm'
            executable = target / 'package/bin/pnpm.cjs'
            if json_file(target / 'package/package.json').get('version') != tools[name]['version']:
                raise RecipeFailure()
            if native.is_file() and native.read_bytes()[:4] == b'\x7fELF':
                publish(bin_dir / 'pnpm', native.read_bytes(), 0o755, uid, gid, replace_link=True)
            elif executable.is_file():
                publish(bin_dir / 'pnpm', ('#!/bin/sh\nexec "' + str(bin_dir / 'node') + '" "' + str(executable) + '" "$@"\n').encode(), 0o755, uid, gid, replace_link=True)
            else:
                raise RecipeFailure()
        elif name == 'bun':
            executable = next(target.glob('*/bun'))
            os.chmod(executable, 0o755)
            safe_directory('/opt/orbit/bun', uid, gid)
            safe_directory('/opt/orbit/bun/bin', uid, gid)
            publish('/opt/orbit/bun/bin/bun', executable.read_bytes(), 0o755, uid, gid, replace_link=True)
            publish('/usr/local/bin/bun', b'#!/bin/sh\nexec /opt/orbit/bun/bin/bun "$@"\n', 0o755, replace_link=True)
    if 'node' in tools:
        for binary in ('node', 'npm', 'npx'):
            shim = bin_dir / binary
            if shim.exists() or shim.is_symlink():
                if shim.lstat().st_uid != uid:
                    raise RecipeFailure()
                shim.unlink()
            shim.symlink_to('vp')
            os.chown(shim, uid, gid, follow_symlinks=False)
        run(['sudo', '-u', account.pw_name, '-H', 'env', 'VP_HOME=' + str(scope),
             str(bin_dir / 'vp'), 'env', 'default', tools['node']['version']])
    for binary in ('vp', 'node', 'pnpm', 'npm', 'npx'):
        target = bin_dir / binary
        launcher = Path('/usr/local/bin') / binary
        content = ('#!/bin/sh\nexport VP_HOME="' + str(scope) + '"\nexec "' + str(target) + '" "$@"\n').encode()
        publish(launcher, content, 0o755)


def apply(payload):
    with SnapshotLookups() as snapshots:
        return apply_recipe(payload, snapshots)


def apply_recipe(payload, snapshots):
    stage = 'preflight'
    try:
        tools = payload['tools']
        configure_git(payload)
        policy = POLICY
        if policy.exists() or policy.is_symlink():
            raise RecipeFailure()
        stage = 'apt-source'
        repaired = repair_ubuntu_sources(payload['architecture'])
        repair_options = (['-o', 'Dir::Etc::sourcelist=' + str(APT_ETC / 'sources.list.d/ubuntu.sources'),
                           '-o', 'Dir::Etc::sourceparts=-', '-o', 'APT::Get::List-Cleanup=false'] if repaired else [])
        # Refresh the rolling pockets before assessing even an already-converged Node.
        package_command(['apt-get', 'update', *repair_options, '-o', 'APT::Update::Error-Mode=any'], [])
        stage = 'preflight'
        account = pwd.getpwnam(os.environ.get('SUDO_USER', 'orbit'))
        before = packages()
        versions = inspect(tools)
        check_git_siblings(payload)
        assessment = assess(tools, versions, snapshots=snapshots, git_names=git_packages(payload))
        supported(assessment)
        active = active_services()
        js = set(tools) & {'node', 'vp', 'vp-cli', 'pnpm', 'bun'}
        if js:
            vite_scope(account)
        with tempfile.TemporaryDirectory(prefix='orbit-recipe-') as directory:
            work = Path(directory)
            stage = 'download'
            downloads = {name: download_deb(name, pin, payload, work / (name + ('.deb' if pin['artifacts'][payload['architecture']]['url'].endswith('.deb') else '.archive')))
                         for name, pin in tools.items() if pin['source'] != 'ubuntu' and versions[name] != pin['version']}
            debs = [str(path) for name, path in downloads.items() if tools[name]['artifacts'][payload['architecture']]['url'].endswith('.deb')]
            # Validate Debian package identity before allowing maintainer scripts to run.
            for deb in debs:
                name = Path(deb).stem
                metadata = run(['dpkg-deb', '-f', deb, 'Package', 'Version', 'Architecture']).stdout.splitlines()
                expected = [name, tools[name]['version'], payload['architecture']]
                actual = [line.split(': ', 1)[-1] for line in metadata]
                if actual[:2] != expected[:2] or actual[2:] not in ([payload['architecture']], ['all']):
                    raise RecipeFailure()
            stage = 'apt-source'
            configure_git(payload, apply=True)
            apt_options = snapshot_options(payload, work)
            stage = 'packages'
            package_command(['apt-get', 'update', *apt_options], [])
            ubuntu = [pin['package'] + '=' + pin['version'] for name, pin in tools.items()
                      if pin['source'] == 'ubuntu' and assessment[name]['changed']]
            environment = dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_SUSPEND='1')
            # This is temporary restart suppression, not a package/configuration rollback.
            publish(policy, b'#!/bin/sh\nexit 101\n', 0o755)
            try:
                if ubuntu:
                    package_command(['apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove',
                                     '-o', 'Dpkg::Options::=--force-confold', *apt_options, '--', *ubuntu], ubuntu, env=environment)
                if debs:
                    install_exact_debs(debs, tools, packages(), apt_options, environment)
                if ubuntu or debs:
                    package_command(['apt-get', 'check'], ubuntu + [Path(deb).stem for deb in debs], env=environment)
                    audit_packages(environment)
            finally:
                policy.unlink()
            pin_git(payload)
            stage = 'tools'
            install_javascript(tools, downloads, work, account) if js else None
            stage = 'inventory'
            after_versions = inspect(tools)
            after_assessment = assess(tools, after_versions, apt_options, snapshots, git_packages(payload))
            supported(after_assessment)
            if any(tool['status'] not in ('current', 'newer_than_recipe') for tool in after_assessment.values()):
                raise RecipeFailure()
            after = packages()
            changed = sorted(name for name in before.keys() | after.keys() if before.get(name) != after.get(name))
            stage = 'restart'
            restarted = services_for(changed, active)
            for service in restarted:
                stage = 'restart:' + service
                run(['systemctl', 'restart', service])
            stage = 'health'
            # Check all services active before this operation, including unchanged services; restart none here.
            for service in sorted(active):
                stage = 'health:' + service
                run(['systemctl', 'is-active', '--quiet', service])
            record_git(payload, after_versions)
            return {'changed_packages': changed, 'restarted_services': restarted,
                    'changed_tools': sorted(name for name in tools if versions[name] != after_versions[name]),
                    'newer_than_recipe': newer_packages(after_assessment), 'healthy': True}
    except Exception as error:
        if isinstance(error, RecipeFailure):
            raise RecipeFailure(str(error) or stage, result=error.result, packages=error.packages) from error
        raise RecipeFailure(stage) from error


def interrupted(signum, frame):
    raise RecipeInterrupted('interrupted')


def main():
    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)
    mode, document = sys.argv[1:]
    payload = json.loads(document)
    stage = 'platform'
    try:
        platform_check(payload['architecture'])
        stage = 'inventory'
        if 'docker-ce' in packages():
            raise RecipeFailure('docker-provider:docker-ce')
        result = plan(payload) if mode == 'inspect' else apply(payload)
        print(json.dumps(result))
        return 0
    except Exception as error:
        if isinstance(error, RecipeFailure) and str(error):
            stage = str(error)
        if stage == 'packages' and isinstance(error, RecipeFailure) and error.result is not None:
            result = error.result
            print(json.dumps({'failure': {'stage': 'recipe-stage:packages', 'packages': error.packages,
                                         'command': result.args, 'exit_code': result.returncode,
                                         'stdout': failure_output(result.stdout), 'stderr': failure_output(result.stderr)}}, ensure_ascii=False))
        else:
            print('recipe-stage:' + stage)
        return 1
