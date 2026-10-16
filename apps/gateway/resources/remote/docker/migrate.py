# Program: docker.migrate
# Uses: lib/program.py lib/artifacts.py lib/recipe.py
# Outcomes: planned prepared reported failed
# Timeout: 3600
# Input: {"mode": "plan"|"prepare"|"report", "recipe": identity, "snapshot": timestamp,
#   "architecture": amd64|arm64, "docker_version": version, "containerd_version": version,
#   "process_ids": [...], "process_states": {...}, "process_specs": {...}, "fallback": bool}
# Result: plan or per-container final states. failed adds a fixed stage and resumable=true.
# Inspect documents, environment secrets and command diagnostics never leave the root-only journal.
import fcntl
import http.client
import socket
import time
import shutil
from urllib.parse import quote

JOURNAL = Path('/var/lib/orbit/docker-migration')
DAEMON = Path('/etc/docker/daemon.json')
DROPIN = Path('/etc/systemd/system/docker.service.d/orbit-docker-migration.conf')
CE = ['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin',
      'docker-compose-plugin', 'docker-ce-rootless-extras', 'docker-model-plugin']
STEPS = ['inventory and storage driver', 'save local images and commit changed non-Orbit layers',
         'disable restart policies', 'stop all containers', 'save shutdown layer changes',
         'back up offline Docker and containerd metadata databases', 'retire Docker apt sources with backups in /etc/apt',
         'swap CE packages for recipe docker.io, containerd, docker-buildx, docker-compose-v2',
         'record swapping; repair interrupted dpkg and dependencies; install with recipe snapshot sources',
         'verify every image, container layer and volume; start and stop one container before restoring policies',
         'if unreadable: refuse unless --fallback; isolate fresh engine, load archives, preserve volume paths and recreate',
         'reconcile Orbit Processes from stored specs', 'report every container state']


class DockerSocket(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(120)
        self.sock.connect('/var/run/docker.sock')


def api(method, path, body=None, *, missing=False):
    connection = DockerSocket('localhost', timeout=120)
    try:
        # Latest inspect exposes settings that the downgraded API might otherwise hide.
        connection.request(method, ('' if method == 'GET' else '/v1.52') + path,
                           None if body is None else json.dumps(body), {'Content-Type': 'application/json'})
        response = connection.getresponse()
        data = response.read()
        if missing and response.status == 404:
            return None
        if response.status >= 300:
            raise RecipeFailure('docker-api')
        return json.loads(data) if data else {}
    finally:
        connection.close()


def inspect_container(name, *, missing=False):
    return api('GET', '/containers/' + quote(name, safe='') + '/json', missing=missing)


def docker_sources():
    changes = []
    paths = [APT_ETC / 'sources.list', *sorted((APT_ETC / 'sources.list.d').glob('*.list')),
             *sorted((APT_ETC / 'sources.list.d').glob('*.sources'))]
    for path in paths:
        if not path.is_file():
            continue
        content = path.read_text()
        if 'download.docker.com' not in content:
            continue
        if path.is_symlink():
            raise RecipeFailure('apt-source')
        blocks = re.split(r'(\n\s*\n)', content) if path.suffix == '.sources' else content.splitlines(keepends=True)
        keep = []
        for block in blocks:
            active = '\n'.join(line for line in block.splitlines() if not line.lstrip().startswith('#'))
            if 'download.docker.com' not in active:
                keep.append(block)
                continue
            if path.suffix == '.sources':
                fields = deb822_fields(block)
                uris = fields.get('uris', '').split()
                valid = (uris == ['https://download.docker.com/linux/ubuntu']
                         and set(fields.get('types', '').split()) <= {'deb', 'deb-src'}
                         and re.fullmatch(r'[a-z][a-z0-9-]*', fields.get('suites', ''))
                         and fields.get('components') == 'stable')
            else:
                valid = re.fullmatch(r'\s*deb(?:-src)?\s+(?:\[[^]]+\]\s+)?https://download\.docker\.com/linux/ubuntu/?\s+[a-z][a-z0-9-]*\s+stable\s*(?:#.*)?\n?', block)
            if not valid:
                raise RecipeFailure('apt-source')
        after = ''.join(keep).encode()
        if after != content.encode():
            backup_apt_source(path, content.encode(), 'docker', check_only=True)
            changes.append((path, content.encode(), after))
    return changes


def record(name, value):
    path = JOURNAL / name
    publish(path, json.dumps(value, sort_keys=True).encode(), 0o600)
    with path.open('rb') as stream:
        os.fsync(stream.fileno())
    descriptor = os.open(JOURNAL, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def daemon_bytes():
    if DAEMON.is_symlink():
        raise RecipeFailure('daemon-config')
    return DAEMON.read_bytes() if DAEMON.exists() else None


# Supported fields: moby/moby docker-v29.1.3/api/swagger.yaml (Ubuntu engine API 1.52).
CONFIG_FIELDS = set(('Hostname Domainname User AttachStdin AttachStdout AttachStderr ExposedPorts Tty OpenStdin '
                     'StdinOnce Env Cmd Healthcheck ArgsEscaped Image Volumes WorkingDir Entrypoint NetworkDisabled '
                     'OnBuild Labels StopSignal StopTimeout Shell').split())
HOST_FIELDS = set(('CpuShares Memory CgroupParent BlkioWeight BlkioWeightDevice BlkioDeviceReadBps '
                   'BlkioDeviceWriteBps BlkioDeviceReadIOps BlkioDeviceWriteIOps CpuPeriod CpuQuota CpuRealtimePeriod '
                   'CpuRealtimeRuntime CpusetCpus CpusetMems Devices DeviceCgroupRules DeviceRequests MemoryReservation '
                   'MemorySwap MemorySwappiness NanoCpus OomKillDisable Init PidsLimit Ulimits CpuCount CpuPercent '
                   'IOMaximumIOps IOMaximumBandwidth Binds ContainerIDFile LogConfig NetworkMode PortBindings RestartPolicy '
                   'AutoRemove VolumeDriver VolumesFrom Mounts ConsoleSize Annotations CapAdd CapDrop CgroupnsMode Dns '
                   'DnsOptions DnsSearch ExtraHosts GroupAdd IpcMode Cgroup Links OomScoreAdj PidMode Privileged '
                   'PublishAllPorts ReadonlyRootfs SecurityOpt StorageOpt Tmpfs UTSMode UsernsMode ShmSize Sysctls Runtime '
                   'Isolation MaskedPaths ReadonlyPaths').split())
ENDPOINT_FIELDS = set(('IPAMConfig Links MacAddress Aliases DriverOpts GwPriority NetworkID EndpointID Gateway IPAddress '
                       'IPPrefixLen IPv6Gateway GlobalIPv6Address GlobalIPv6PrefixLen DNSNames').split())


def supported_fields(value, allowed):
    if any(key not in allowed and item for key, item in (value or {}).items()):
        raise RecipeFailure('newer-engine-configuration')


def validate_process(container, payload):
    labels = container['Config'].get('Labels') or {}
    if labels.get('orbit.managed') != 'true':
        return
    spec = payload['process_specs'].get(labels.get('orbit.process.id'))
    if (not spec or labels.get('orbit.process.spec') != spec['hash']
            or container['Name'].lstrip('/') != spec['name']):
        raise RecipeFailure('orbit-spec-changed')
    if (container['HostConfig'].get('NetworkMode', 'default') not in ('default', 'bridge', '')
            or set(container['NetworkSettings']['Networks']) - {'bridge'}):
        raise RecipeFailure('orbit-network-drift')
    for mount in container.get('Mounts', []):
        source = mount['Name'] if mount['Type'] == 'volume' else mount.get('Source')
        if not any(v.get('source') == source and v.get('target') == mount['Destination'] for v in spec['volumes']):
            raise RecipeFailure('orbit-unrecorded-mount')


def validate_container(container, process_ids):
    config, host = container['Config'], container['HostConfig']
    image = config.get('Image', '')
    if not image:
        raise RecipeFailure('missing-image')
    labels = config.get('Labels') or {}
    supported_fields(config, CONFIG_FIELDS)
    supported_fields(host, HOST_FIELDS)
    supported_fields(config.get('Healthcheck'), set('Test Interval Timeout Retries StartPeriod StartInterval'.split()))
    for endpoint in container['NetworkSettings']['Networks'].values():
        supported_fields(endpoint, ENDPOINT_FIELDS)
        supported_fields(endpoint.get('IPAMConfig'), set('IPv4Address IPv6Address LinkLocalIPs'.split()))
        if endpoint.get('Links'):
            raise RecipeFailure('unsupported-container-links')
    for mount in host.get('Mounts') or []:
        supported_fields(mount, set('Target Source Type ReadOnly Consistency BindOptions VolumeOptions ImageOptions TmpfsOptions'.split()))
        supported_fields(mount.get('BindOptions'), set('Propagation NonRecursive CreateMountpoint ReadOnlyNonRecursive ReadOnlyForceRecursive'.split()))
        supported_fields(mount.get('VolumeOptions'), set('NoCopy Labels DriverConfig Subpath'.split()))
        supported_fields((mount.get('VolumeOptions') or {}).get('DriverConfig'), {'Name', 'Options'})
        supported_fields(mount.get('TmpfsOptions'), set('SizeBytes Mode Options'.split()))
    logging = host.get('LogConfig') or {}
    if logging.get('Type') not in (None, '', 'json-file', 'local', 'none', 'journald', 'syslog', 'gelf', 'fluentd', 'awslogs', 'splunk', 'gcplogs'):
        raise RecipeFailure('unsupported-logging-plugin')
    if labels.get('com.docker.swarm.service.id'):
        raise RecipeFailure('swarm')
    if labels.get('orbit.managed') == 'true':
        if labels.get('orbit.container.kind') != 'process' or labels.get('orbit.process.id') not in process_ids:
            raise RecipeFailure('orbit-spec-missing')
    if (container.get('State', {}).get('Paused') or host.get('AutoRemove') or host.get('VolumesFrom') or host.get('Links')
            or any(str(host.get(key, '')).startswith('container:') for key in ('NetworkMode', 'PidMode', 'IpcMode'))):
        raise RecipeFailure('unsupported-container')
    for mount in container.get('Mounts', []):
        if mount['Type'] not in ('bind', 'volume', 'tmpfs'):
            raise RecipeFailure('unsupported-mount')
        if mount['Type'] in ('bind', 'volume') and not Path(mount['Source']).exists():
            raise RecipeFailure('missing-mount')
    # Orbit layers are disposable: only their stored specs and data mounts are authoritative.


def inventory(payload):
    info = api('GET', '/info')
    raw = daemon_bytes()
    config = json.loads(raw) if raw else {}
    if (info['DockerRootDir'] != '/var/lib/docker' or any(key in config for key in ('data-root', 'hosts', 'containerd'))
            or DROPIN.exists()):
        raise RecipeFailure('custom-daemon')
    containers = [inspect_container(item['Id']) for item in api('GET', '/containers/json?all=1')]
    images = [api('GET', '/images/' + item['Id'] + '/json') for item in api('GET', '/images/json?all=1')]
    image_refs = {}
    for container in containers:
        validate_container(container, payload['process_ids'])
        validate_process(container, payload)
        if ((container['Config'].get('Labels') or {}).get('orbit.managed') == 'true'
                and container['HostConfig'].get('Runtime', 'runc') != info.get('DefaultRuntime', 'runc')):
            raise RecipeFailure('orbit-runtime-drift')
        image = next((image for image in images if image['Id'] == container['Image']), None)
        if not image or image.get('Architecture') != payload['architecture'] or image.get('Os') != 'linux':
            raise RecipeFailure('foreign-image-platform')
        references = image.get('RepoDigests') or []
        reference = container['Config']['Image']
        if '@sha256:' in reference:
            references = [reference]
        pinned = next((ref for ref in references if not run(['docker', 'manifest', 'inspect', ref], required=False).returncode), None)
        image_refs[container['Id']] = pinned
    volumes = api('GET', '/volumes')['Volumes'] or []
    for volume in volumes:
        if volume['Driver'] != 'local' or volume.get('Options') or not Path(volume['Mountpoint']).is_dir():
            raise RecipeFailure('unsupported-volume')
    networks = [api('GET', '/networks/' + item['Id']) for item in api('GET', '/networks')]
    for network in networks:
        if network['Name'] not in ('bridge', 'host', 'none') and (
                network['Driver'] != 'bridge' or network.get('Ingress') or network.get('Scope') != 'local'
                or network.get('ConfigOnly') or (network.get('ConfigFrom') or {}).get('Network')
                or network.get('IPAM', {}).get('Driver', 'default') != 'default'):
            raise RecipeFailure('unsupported-network')
        if network['Name'] not in ('bridge', 'host', 'none'):
            network_request(network)
    for container in containers:
        if (container['Config'].get('Labels') or {}).get('orbit.managed') == 'true':
            container['Config'].pop('Env', None)
    return {'recipe': payload['recipe'], 'containers': containers, 'volumes': volumes, 'networks': networks,
            'images': images, 'image_refs': image_refs, 'driver': info.get('Driver'),
            'defaults': {key: info.get(key) for key in ('DefaultRuntime', 'LoggingDriver', 'CgroupDriver')},
            'archives': {}, 'committed': {}, 'stage': 'inventory',
            'daemon': None if raw is None else raw.hex(),
            'fallback': False, 'swapped': False, 'prepared': False, 'stopped': False}


def daemon_defaults(info):
    return {key: info.get(key) for key in ('DefaultRuntime', 'LoggingDriver', 'CgroupDriver')}


def stage(state, name):
    state['stage'] = name
    record('inventory.json', state)


def guard_packages(simulation):
    for line in simulation.splitlines():
        if line.startswith('Remv ') and line.split()[1].split(':')[0] not in CE:
            raise RecipeFailure('unrelated-package-removal')
        if line.startswith('Inst '):
            entry = re.match(r'Inst (\S+)(?: \[([^]]+)\])? \((\S+)', line)
            if not entry:
                raise RecipeFailure('apt-plan')
            name, old, new = entry.groups()
            if old and name.split(':')[0] not in CE + ['docker.io', 'containerd']:
                if run(['dpkg', '--compare-versions', new, 'lt', old], required=False).returncode != 1:
                    raise RecipeFailure('unrelated-package-downgrade')


def stop_loaded(units):
    loaded = [unit for unit in units if run(['systemctl', 'show', '--property=LoadState', '--value', unit],
                                           required=False).stdout.strip() == 'loaded']
    if loaded:
        run(['systemctl', 'stop', *loaded])


def start_daemon():
    for attempt in range(3):
        try:
            run(['systemctl', 'start', '--no-block', 'docker'], timeout=15)
            for _ in range(10):
                try:
                    api('GET', '/info')
                    return
                except (RecipeFailure, OSError, http.client.HTTPException):
                    time.sleep(2)
        except RecipeFailure:
            pass
        if attempt < 2:
            run(['systemctl', 'reset-failed', 'docker'], required=False)
    raise RecipeFailure('daemon-start')


def archive_image(state, image):
    destination = JOURNAL / ('image-' + image.replace(':', '-') + '.tar')
    if image not in state['archives']:
        temporary = destination.with_suffix('.partial')
        run(['docker', 'save', '--output', str(temporary), image], timeout=600)
        temporary.chmod(0o600)
        with temporary.open('rb') as stream:
            os.fsync(stream.fileno())
        temporary.replace(destination)
        state['archives'][image] = str(destination)
        record('inventory.json', state)


def preserve_images(state, *, shutdown=False):
    for image in state['images']:
        if not image.get('RepoDigests') or any(c['Image'] == image['Id'] and not state['image_refs'][c['Id']]
                                              for c in state['containers']):
            archive_image(state, image['Id'])
    for container in state['containers']:
        if (container['Config'].get('Labels') or {}).get('orbit.managed') == 'true':
            continue
        if api('GET', '/containers/' + container['Id'] + '/changes'):
            key = container['Id'] + (':shutdown' if shutdown else ':running')
            image = run(['docker', 'commit', container['Id']], timeout=600).stdout.strip()
            if not re.fullmatch(r'sha256:[a-f0-9]{64}', image):
                raise RecipeFailure('commit-image')
            archive_image(state, image)
            state['committed'][key] = image
            record('inventory.json', state)


METADATA_ROOTS = [Path('/var/lib/docker'), Path('/var/lib/containerd')]


def backup_metadata(state):
    if state.get('metadata_backed_up'):
        return
    stage(state, 'metadata-backup')
    target = JOURNAL / 'metadata'
    target.mkdir(mode=0o700, exist_ok=True)
    for root in METADATA_ROOTS:
        if not root.exists():
            continue
        candidates = [*root.glob('volumes/metadata.db'), *root.glob('network/files/local-kv.db'),
                      *root.glob('containers/*/config.v2.json'), *root.glob('containers/*/hostconfig.json'),
                      *root.glob('io.containerd.metadata.v1.bolt/meta.db'),
                      *root.glob('containerd/daemon/io.containerd.metadata.v1.bolt/meta.db')]
        for directory in [root / 'image']:
            if directory.is_dir() and not directory.is_symlink():
                candidates.extend(directory.rglob('*'))
        for source in candidates:
            relative = source.relative_to(root)
            if source.is_symlink() or not source.is_file():
                continue
            parts = relative.parts
            selected = (source.name in ('metadata.db', 'meta.db', 'local-kv.db')
                        or (parts[0] == 'containers' and source.name in ('config.v2.json', 'hostconfig.json'))
                        or (parts[0] == 'image' and ('imagedb' in parts or 'layerdb' in parts)))
            if not selected or '_data' in parts:
                continue
            destination = target / root.name / relative
            destination.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
            with source.open('rb') as incoming, destination.open('wb') as outgoing:
                shutil.copyfileobj(incoming, outgoing)
                outgoing.flush()
                os.fsync(outgoing.fileno())
            destination.chmod(0o600)
    state['metadata_backed_up'] = True
    record('inventory.json', state)


def policies(state, restore=False):
    for container in state['containers']:
        if state['fallback'] and (container['Config'].get('Labels') or {}).get('orbit.managed') == 'true':
            continue
        reference = container['Name'].lstrip('/') if state['fallback'] else container['Id']
        api('POST', '/containers/' + quote(reference, safe='') + '/update',
            {'RestartPolicy': container['HostConfig'].get('RestartPolicy', {'Name': 'no'}) if restore else {'Name': 'no'}})


def restart_original(state):
    state['metadata_backed_up'] = False
    state['committed'] = {}
    record('inventory.json', state)
    start_daemon()
    policies(state, restore=True)
    for container in state['containers']:
        if container['State']['Running']:
            run(['docker', 'start', container['Id']], required=False)


def package_arguments(payload, installed):
    return ['docker.io=' + payload['docker_version'], 'containerd=' + payload['containerd_version'],
            'docker-buildx', 'docker-compose-v2', *[name + '-' for name in CE if name in installed]]


def swap(payload, state):
    # Suppress package-triggered service startup; preserve a pre-existing policy rather than replacing it.
    marker = JOURNAL / 'restart-policy.json'
    policy_content = b'#!/bin/sh\nexit 101\n'
    if marker.exists():
        if POLICY.is_symlink() or (POLICY.exists() and POLICY.read_bytes() != policy_content):
            raise RecipeFailure('restart-policy')
        POLICY.unlink(missing_ok=True)
        marker.unlink()
    if POLICY.exists() or POLICY.is_symlink():
        raise RecipeFailure('restart-policy')
    with tempfile.TemporaryDirectory(prefix='orbit-docker-apt-') as directory:
        options = snapshot_options(payload, Path(directory))
        environment = dict(os.environ, LC_ALL='C', DEBIAN_FRONTEND='noninteractive', NEEDRESTART_SUSPEND='1')
        stage(state, 'apt-indexes')
        run(['apt-get', 'update', *options, '-o', 'APT::Update::Error-Mode=any'], env=environment)
        arguments = package_arguments(payload, packages())
        base = ['apt-get', 'install', '--no-install-recommends', '--allow-downgrades',
                '-o', 'Dpkg::Options::=--force-confold', *options]
        record('restart-policy.json', {'owned': True})
        publish(POLICY, policy_content, 0o755)
        previous_term = signal.signal(signal.SIGTERM, signal.SIG_IGN)
        previous_int = signal.signal(signal.SIGINT, signal.SIG_IGN)
        try:
            if state.get('swapping'):
                stage(state, 'repair-dpkg')
                run(['dpkg', '--configure', '-a'], required=False, env=environment, timeout=None)
                repair = ['apt-get', '-f', 'install', *options]
                guard_packages(run([*repair, '--simulate'], env=environment).stdout)
                stage(state, 'repair-dependencies')
                run([*repair, '--yes'], env=environment, timeout=None)
                run(['dpkg', '--configure', '-a'], env=environment, timeout=None)
            stage(state, 'apt-simulation')
            guard_packages(run([*base, '--simulate', '--', *arguments], env=environment).stdout)
            state['swapping'] = True
            stage(state, 'swapping')
            run([*base, '--yes', '--', *arguments], env=environment, timeout=None)
            stage(state, 'apt-check')
            run(['apt-get', 'check', *options], env=environment)
        finally:
            signal.signal(signal.SIGTERM, previous_term)
            signal.signal(signal.SIGINT, previous_int)
            POLICY.unlink()
            marker.unlink()
    installed = packages()
    if (installed.get('docker.io') != payload['docker_version'] or installed.get('containerd') != payload['containerd_version']
            or any(name in installed for name in CE) or not all(name in installed for name in ('docker-buildx', 'docker-compose-v2'))):
        raise RecipeFailure('package-verification')
    state['swapped'] = True
    state['swapping'] = False
    stage(state, 'swapped')


def readable(state):
    try:
        start_daemon()
        info = api('GET', '/info')
        if info.get('Driver') != state.get('driver') or daemon_defaults(info) != state.get('defaults', {}):
            return False
        if any(inspect_container(c['Id'], missing=True) is None for c in state['containers']):
            return False
        if any(api('GET', '/images/' + image['Id'] + '/json', missing=True) is None for image in state['images']):
            return False
        if any(api('GET', '/volumes/' + quote(v['Name'], safe=''), missing=True) is None for v in state['volumes']):
            return False
        durable = [c for c in state['containers'] if (c['Config'].get('Labels') or {}).get('orbit.managed') != 'true']
        for container in durable:
            api('GET', '/containers/' + container['Id'] + '/changes')
        probe = next((c for c in durable if c['State']['Running']), None)
        if probe:
            run(['docker', 'start', probe['Id']], timeout=120)
            run(['docker', 'stop', '--time', '60', probe['Id']], timeout=90)
        return True
    except (RecipeFailure, OSError, http.client.HTTPException):
        return False


def network_request(network):
    body = {key: network[key] for key in
            ('Name', 'Driver', 'Internal', 'Attachable', 'EnableIPv4', 'EnableIPv6', 'IPAM', 'Options', 'Labels') if key in network}
    options = dict(network.get('Options') or {})
    bridge = options.get('com.docker.network.bridge.name')
    if not bridge:
        if not re.fullmatch('[a-f0-9]{64}', network['Id']):
            raise RecipeFailure('unsupported-network')
        bridge = 'br-' + network['Id'][:12]
    if not re.fullmatch('[A-Za-z0-9_.-]{1,15}', bridge) or bridge in ('.', '..'):
        raise RecipeFailure('unsupported-network')
    # Stopping dockerd leaves this interface and its connected routes behind. Let Docker
    # reuse/validate it rather than creating a competing bridge with the same subnet.
    body['Options'] = dict(options, **{'com.docker.network.bridge.name': bridge})
    return body


def fresh_engine(state):
    stop_loaded(['docker.service', 'docker.socket'])
    content = ('[Unit]\nRequires=\nRequires=docker.socket\nWants=\nWants=network-online.target\n'
               '[Service]\nExecStart=\nExecStart=/usr/bin/dockerd --host=fd:// --data-root='
               + str(JOURNAL / 'engine') + '\n').encode()
    if DROPIN.exists() and DROPIN.read_bytes() != content:
        raise RecipeFailure('custom-daemon')
    DROPIN.parent.mkdir(parents=True, exist_ok=True)
    publish(DROPIN, content)
    run(['systemctl', 'daemon-reload'])
    start_daemon()
    api('GET', '/info')
    for volume in state['volumes']:
        expected = {'type': 'none', 'o': 'bind', 'device': volume['Mountpoint']}
        existing = api('GET', '/volumes/' + quote(volume['Name'], safe=''), missing=True)
        if existing is None:
            api('POST', '/volumes/create', {'Name': volume['Name'], 'Driver': 'local',
                                          'DriverOpts': expected, 'Labels': volume.get('Labels')})
        elif existing.get('Options') != expected:
            raise RecipeFailure('volume-conflict')
    for network in state['networks']:
        if network['Name'] in ('bridge', 'host', 'none'):
            continue
        body = network_request(network)
        existing = api('GET', '/networks/' + quote(network['Name'], safe=''), missing=True)
        if existing is None:
            api('POST', '/networks/create', body)
        elif any(existing.get(key) != value for key, value in body.items()):
            raise RecipeFailure('network-conflict')


def effective_host(host, info):
    result = {key: value for key, value in host.items() if value not in (None, '', [], {}, False, 0)}
    result['Runtime'] = host.get('Runtime') or info.get('DefaultRuntime') or 'runc'
    logging = host.get('LogConfig') or {}
    result['LogConfig'] = {'Type': logging.get('Type') or info.get('LoggingDriver') or 'json-file',
                           'Config': logging.get('Config') or {}}
    result['RestartPolicy'] = dict({'Name': 'no', 'MaximumRetryCount': 0}, **(host.get('RestartPolicy') or {}))
    result['NetworkMode'] = host.get('NetworkMode') or 'default'
    return result


def recreate(container, image_ref):
    name = container['Name'].lstrip('/')
    body = dict(container['Config'], HostConfig=dict(container['HostConfig']))
    body['HostConfig']['RestartPolicy'] = {'Name': 'no'}
    body['Labels'] = dict(body.get('Labels') or {}, **{'orbit.docker.migration': container['Id']})
    # The old engine's anonymous volume names must not silently become new empty volumes.
    binds = list(body['HostConfig'].get('Binds') or [])
    mounts = [dict(mount) for mount in body['HostConfig'].get('Mounts') or []]
    for mount in mounts:
        if mount['Type'] == 'volume' and not mount.get('Source'):
            mount['Source'] = next(m['Name'] for m in container['Mounts'] if m['Destination'] == mount['Target'])
    if mounts:
        body['HostConfig']['Mounts'] = mounts
    destinations = {item.split(':')[1] for item in binds} | {m['Target'] for m in mounts}
    for mount in container.get('Mounts', []):
        if mount['Type'] == 'volume' and mount['Destination'] not in destinations:
            binds.append(mount['Name'] + ':' + mount['Destination'] + (':rw' if mount['RW'] else ':ro'))
    body['HostConfig']['Binds'] = binds
    endpoints = {}
    for network_name, endpoint in container['NetworkSettings']['Networks'].items():
        endpoints[network_name] = {key: endpoint[key] for key in ('IPAMConfig', 'Aliases', 'DriverOpts', 'MacAddress', 'GwPriority') if endpoint.get(key) is not None}
    body['NetworkingConfig'] = {'EndpointsConfig': endpoints}
    if not image_ref:
        body['Image'] = container.get('RecoveryImage', container['Image'])
    existing = inspect_container(name, missing=True)
    if existing is not None:
        if (existing['Image'] != container.get('RecoveryImage', container['Image'])
                or any(existing['Config'].get(key) != value for key, value in body.items()
                       if key not in ('HostConfig', 'NetworkingConfig') and value not in (None, '', [], {}))
                or effective_host(existing['HostConfig'], api('GET', '/info')) != effective_host(body['HostConfig'], api('GET', '/info'))):
            raise RecipeFailure('container-conflict')
        return
    if image_ref:
        run(['docker', 'pull', image_ref], timeout=600)
        if api('GET', '/images/' + quote(image_ref, safe='') + '/json')['Id'] != container['Image']:
            raise RecipeFailure('image-version')
        if '@' not in container['Config']['Image']:
            run(['docker', 'tag', image_ref, container['Config']['Image']])
    api('POST', '/containers/create?name=' + quote(name, safe=''), body)


def public_report(state):
    containers = []
    for old in state['containers']:
        try:
            current = inspect_container(old['Name'], missing=True)
            status = current['State']['Status'] if current else 'missing'
        except (RecipeFailure, OSError, http.client.HTTPException):
            current, status = None, 'unreadable'
        health = current['State'].get('Health', {}).get('Status') if current else None
        containers.append({'name': old['Name'].lstrip('/'), 'state': status, 'health': health})
    return {'fallback': state['fallback'], 'containers': containers,
            'process_ids': sorted({c['Config']['Labels']['orbit.process.id'] for c in state['containers']
                                   if (c['Config'].get('Labels') or {}).get('orbit.managed') == 'true'})}


def migrate(payload, mode):
    path = JOURNAL / 'inventory.json'
    state = json_file(path) if path.exists() else None
    if state and state['recipe'] != payload['recipe']:
        raise RecipeFailure('recipe-mismatch')
    if state is None:
        if 'docker-ce' not in packages():
            raise RecipeFailure('not-docker-ce')
        state = inventory(payload)
    for container in state['containers']:
        validate_process(container, payload)
    sources = docker_sources()
    if mode == 'plan':
        return {'steps': STEPS, 'containers': [c['Name'].lstrip('/') for c in state['containers']],
                'recreate': [{'name': c['Name'].lstrip('/'), 'image': c['Config']['Image'], 'pull': state['image_refs'][c['Id']],
                              'runtime': c['HostConfig'].get('Runtime', 'runc'),
                              'source': 'stored Process spec' if (c['Config'].get('Labels') or {}).get('orbit.managed') == 'true' else 'inventory',
                              'restart': c['State']['Running']} for c in state['containers']],
                'fallback_data_root': str(JOURNAL / 'engine'), 'fallback_systemd_dropin': str(DROPIN),
                'volume_backings': [{'name': v['Name'], 'path': v['Mountpoint']} for v in state['volumes']],
                'packages': package_arguments(payload, packages()),
                'source_backups': [str(APT_ETC / (p.name + '.orbit-docker-backup')) for p, _, _ in sources],
                'resuming': path.exists(), 'daemon_json': 'unchanged', 'volume_data': 'never deleted',
                'storage_driver': state.get('driver'), 'fallback_authorized': payload.get('fallback', False),
                'local_images': [image['Id'] for image in state['images'] if not image.get('RepoDigests')],
                'changed_layers': [c['Name'].lstrip('/') for c in state['containers']
                                   if (c['Config'].get('Labels') or {}).get('orbit.managed') != 'true'
                                   and api('GET', '/containers/' + c['Id'] + '/changes')]}
    if daemon_bytes() != (None if state['daemon'] is None else bytes.fromhex(state['daemon'])):
        raise RecipeFailure('daemon-changed')
    if not path.exists():
        record('inventory.json', state)
    if mode == 'report':
        report = public_report(state)
        deadline = time.monotonic() + 120
        while any(c['health'] == 'starting' for c in report['containers']) and time.monotonic() < deadline:
            time.sleep(2)
            report = public_report(state)
        report['healthy'] = True
        for old, current in zip(state['containers'], report['containers'], strict=True):
            process_id = (old['Config'].get('Labels') or {}).get('orbit.process.id')
            expected_running = payload['process_states'].get(process_id) == 'running' if process_id else old['State']['Running']
            if (current['state'] in ('missing', 'unreadable') or expected_running != (current['state'] == 'running')
                    or (expected_running and current['health'] in ('starting', 'unhealthy'))):
                report['healthy'] = False
        return report
    if not state['prepared']:
        # Before every resumed transaction stop anything that may have auto-started.
        try:
            current = api('GET', '/containers/json?all=1')
        except (RecipeFailure, OSError, http.client.HTTPException):
            # A previous attempt may have stopped the service or failed inside apt.
            if not state['stopped']:
                run(['systemctl', 'start', 'docker'], timeout=120)
                current = api('GET', '/containers/json?all=1')
            else:
                current = []
        known = {c['Id'] for c in state['containers']}
        if not state['fallback'] and any(c['Id'] not in known for c in current):
            raise RecipeFailure('inventory-drift')
        if state['stopped']:
            # A failed restart or verification may have left a container running. Stop it before opening metadata.
            for container in current:
                api('POST', '/containers/' + container['Id'] + '/update', {'RestartPolicy': {'Name': 'no'}})
                run(['docker', 'stop', '--time', '60', container['Id']], timeout=90)
        if not state['stopped']:
            stage(state, 'preserve-images')
            preserve_images(state)
            state['policies_disabled'] = True
            stage(state, 'disable-policies')
            try:
                policies(state)
                stage(state, 'stop-containers')
                for container in current:
                    run(['docker', 'stop', '--time', '60', container['Id']], timeout=90)
                stage(state, 'preserve-shutdown-layers')
                preserve_images(state, shutdown=True)
            except Exception:
                restart_original(state)
                state['policies_disabled'] = False
                stage(state, 'post-stop-refusal')
                raise
            state['stopped'] = True
            stage(state, 'stopped')
        stage(state, 'stop-services')
        stop_loaded(['docker.service', 'docker.socket', 'containerd.service'])
        backup_metadata(state)
        stage(state, 'retire-sources')
        retire_sources(sources, 'docker')
        if not state['swapped']:
            swap(payload, state)
        stage(state, 'verify-engine')
        if not state['fallback'] and not readable(state):
            if not payload.get('fallback'):
                raise RecipeFailure('fallback-required')
            state['fallback'] = True
            stage(state, 'fallback')
        if state['fallback']:
            stage(state, 'fresh-engine')
            fresh_engine(state)
            stage(state, 'load-images')
            for archive in state['archives'].values():
                run(['docker', 'load', '--input', archive], timeout=600)
            # ID-based saves omit repository names. Restore inventory tags before
            # the Gateway reconciles stored Process specs, including local-only images.
            stage(state, 'restore-image-tags')
            for image in state['images']:
                if image['Id'] in state['archives']:
                    for tag in image.get('RepoTags') or []:
                        run(['docker', 'tag', image['Id'], tag])
            stage(state, 'recreate')
            for container in state['containers']:
                if (container['Config'].get('Labels') or {}).get('orbit.managed') != 'true':
                    committed = state['committed'].get(container['Id'] + ':shutdown', state['committed'].get(container['Id'] + ':running'))
                    if committed:
                        container['RecoveryImage'] = committed
                    recreate(container, None if committed else state['image_refs'][container['Id']])
        stage(state, 'restore-policies')
        policies(state, restore=True)
        stage(state, 'restart-containers')
        for container in state['containers']:
            if (container['Config'].get('Labels') or {}).get('orbit.managed') != 'true' and container['State']['Running']:
                run(['docker', 'start', container['Name'].lstrip('/')])
        state['prepared'] = True
        stage(state, 'prepared')
    return public_report(state)


def transient_prepare(payload):
    # The worker owns the journal lock and survives an SSH disconnect. Input and result are root-only files.
    with tempfile.TemporaryDirectory(prefix='prepare-', dir=JOURNAL) as directory:
        work = Path(directory)
        source = globals()['__orbit_source__']
        publish(work / 'program.py', source, 0o600)
        publish(work / 'input.json', json.dumps(dict(payload, worker=True)).encode(), 0o600)
        result = work / 'result.json'
        publish(result, b'', 0o600)
        unit = 'orbit-docker-migration-' + work.name
        run(['systemd-run', '--quiet', '--wait', '--collect', '--unit=' + unit,
             '--property=RuntimeMaxSec=2400', '--property=TimeoutStopSec=900', '--property=SendSIGKILL=no',
             '--property=StandardInput=file:' + str(work / 'input.json'),
             '--property=StandardOutput=file:' + str(result), '--property=StandardError=null',
             'python3', str(work / 'program.py')], timeout=3360)
        document = json.loads(result.read_text())
        return document['outcome'], document['data']


def journal_permissions():
    if not JOURNAL.exists():
        return
    for path in (JOURNAL, JOURNAL.parent):
        if path.is_symlink() or path.stat().st_uid != 0 or path.stat().st_mode & 0o022:
            raise RecipeFailure('journal-permissions')
    if JOURNAL.stat().st_mode & 0o077:
        raise RecipeFailure('journal-permissions')
    inventory_path = JOURNAL / 'inventory.json'
    if inventory_path.exists() and (inventory_path.is_symlink() or not inventory_path.is_file()
            or inventory_path.stat().st_uid != 0 or inventory_path.stat().st_mode & 0o077):
        raise RecipeFailure('journal-permissions')


def migration_main(payload):
    mode = payload['mode']
    trusted_journal = False
    try:
        platform_check(payload['architecture'])
        if mode != 'plan':
            JOURNAL.mkdir(parents=True, exist_ok=True, mode=0o700)
        journal_permissions()
        trusted_journal = True
        if mode == 'prepare' and not payload.get('worker'):
            return transient_prepare(payload)
        if mode == 'plan':
            report = migrate(payload, mode)
        else:
            with open(JOURNAL / 'lock', 'a') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                try:
                    report = migrate(payload, mode)
                except Exception:
                    path = JOURNAL / 'inventory.json'
                    if mode == 'prepare' and path.exists():
                        state = json.loads(path.read_text())
                        if state.get('stopped') and not state.get('swapping') and not state.get('swapped'):
                            restart_original(state)
                            state['stopped'] = False
                            stage(state, 'post-stop-refusal')
                    raise
        return {'plan': 'planned', 'prepare': 'prepared', 'report': 'reported'}[mode], report
    except Exception as error:
        # Fixed stage names only. Docker and apt errors can include credentials.
        failure_stage = str(error) if isinstance(error, RecipeFailure) and re.fullmatch('[a-z-]+', str(error)) else 'remote'
        if isinstance(error, BlockingIOError):
            failure_stage = 'locked'
        report = {'containers': []}
        path = JOURNAL / 'inventory.json'
        if trusted_journal and path.exists():
            state = json.loads(path.read_text())
            report = public_report(state)
            if failure_stage == 'remote':
                failure_stage = state.get('stage', failure_stage)
        report.update({'failure': 'docker-migration:' + failure_stage, 'resumable': True, 'healthy': False})
        return 'failed', report


if __name__ == '__main__':
    run_program(migration_main)
