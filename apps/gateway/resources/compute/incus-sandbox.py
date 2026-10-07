#!/usr/bin/env python3
"""Typed host control for task-owned Incus VMs. Input and output are JSON; secrets use stdin."""
import hashlib
import fcntl
import ipaddress
import json
import os
import re
import stat
import subprocess
import sys
import uuid

OWNER = 'orbit-task-sandbox'
ROLES = ('operator', 'gateway', 'app-dev', 'app-prod', 'app-prod-2')
PRIVATE = ('0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
           '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
           '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
           '224.0.0.0/4', '240.0.0.0/4')


class Refusal(RuntimeError):
    pass


def public_networks(blocked):
    allowed = [ipaddress.ip_network('0.0.0.0/0')]
    for value in (*PRIVATE, *blocked):
        denied = ipaddress.ip_network(value, strict=False)
        if denied.version != 4:
            raise Refusal('Only IPv4 networks are supported; sandbox IPv6 stays disabled.')
        remaining = []
        for network in allowed:
            if network.subnet_of(denied):
                continue
            remaining.extend(network.address_exclude(denied) if denied.subnet_of(network) else [network])
        allowed = remaining
    return ','.join(map(str, allowed))


def identity(value):
    if not isinstance(value, str) or str(uuid.UUID(value)) != value:
        raise Refusal('Invalid sandbox identity.')
    return 'ot-' + hashlib.sha256(value.encode()).hexdigest()[:10]


class Host:
    def __init__(self, project, sandbox_id, budget):
        if not isinstance(project, str) or not re.fullmatch(r'orbit-(?:task-sandboxes|sandbox-proof-[a-z0-9]+)', project):
            raise Refusal('Invalid sandbox project.')
        if not isinstance(budget, int) or isinstance(budget, bool) or not 1 <= budget <= 64:
            raise Refusal('Invalid VM budget.')
        self.project, self.id, self.budget = project, sandbox_id, budget
        self.name = identity(sandbox_id)
        project_info = self.json('query', '/1.0/projects/' + project)
        if project_info.get('config', {}).get('user.orbit.compute.owner') != OWNER:
            raise Refusal('Sandbox project ownership does not match.')
        if project_info.get('config', {}).get('features.networks') != 'false':
            raise Refusal('Local sandbox projects must share the host bridge namespace.')

    def run(self, *args, data=None, timeout=180):
        scope = [] if args[0] == 'query' else ['--project', self.project]
        result = subprocess.run(['incus', '--force-local', *scope, *args],
                                input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=timeout, check=False)
        if result.returncode:
            # Incus errors may echo sensitive config or guest output. Report only the operation.
            raise Refusal('Incus ' + args[0] + ' operation failed.')
        return result.stdout

    def json(self, *args):
        return json.loads(self.run(*args))

    def metadata(self):
        return {'user.orbit.compute.owner': OWNER, 'user.orbit.compute.id': self.id}

    def own(self, resource):
        if any(resource.get('config', {}).get(key) != value for key, value in self.metadata().items()):
            raise Refusal('Resource ownership does not match; no mutation performed.')
        return resource

    def instances(self):
        all_instances = self.json('list', '--format=json')
        owned = []
        for row in all_instances:
            if row['name'].startswith(self.name + '-'):
                self.own(row)
                if row['name'][len(self.name) + 1:] not in ROLES or row.get('type') != 'virtual-machine':
                    raise Refusal('Unexpected sandbox resource.')
                owned.append(row)
        return owned

    def volumes(self):
        owned = []
        for pool in self.json('storage', 'list', '--format=json'):
            for volume in self.json('storage', 'volume', 'list', pool['name'], '--format=json'):
                if volume['name'] == self.name + '-worktree' and volume['type'] == 'custom':
                    self.own(volume)
                    if volume.get('content_type') != 'filesystem':
                        raise Refusal('The sandbox worktree is not a filesystem volume.')
                    expected = {'/1.0/instances/' + self.name + '-' + role + '?project=' + self.project for role in ROLES}
                    if any(user not in expected for user in volume.get('used_by', [])):
                        raise Refusal('The sandbox worktree is attached outside its group.')
                    owned.append((pool['name'], volume))
        if len(owned) > 1:
            raise Refusal('The sandbox has ambiguous worktree volume ownership.')
        return owned

    def capacity(self):
        rows = self.json('list', '--format=json')
        used = sum(1 for row in rows if row.get('status') != 'Stopped')
        return {'available': max(0, self.budget - used), 'used': used, 'budget': self.budget}

    def observe(self):
        rows = self.instances()
        return {'name': self.name, 'instances': [{'name': row['name'], 'state': row['status'].lower()} for row in rows],
                'power': 'destroyed' if not rows else ('stopped' if all(row['status'] == 'Stopped' for row in rows) else 'running')}

    def provision(self, spec):
        images = spec.get('images')
        if not isinstance(images, dict) or not images or any(role not in ROLES for role in images):
            raise Refusal('Invalid sandbox images.')
        if 'operator' not in images:
            raise Refusal('The sandbox needs an operator VM.')
        if any(not isinstance(value, str) or not re.fullmatch(r'[a-f0-9]{64}', value) for value in images.values()):
            raise Refusal('Sandbox images must be pinned fingerprints.')
        pool = spec.get('pool')
        if not isinstance(pool, str) or not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}', pool):
            raise Refusal('Invalid sandbox storage pool.')
        subnet = ipaddress.ip_network(spec.get('subnet', ''), strict=True)
        if subnet.version != 4 or subnet.prefixlen != 24 or not subnet.subnet_of(ipaddress.ip_network('10.233.0.0/16')):
            raise Refusal('Invalid sandbox subnet.')
        blocked = spec.get('blocked_networks')
        if not isinstance(blocked, list) or not blocked or not all(isinstance(value, str) for value in blocked):
            raise Refusal('Host and LAN exclusions are required.')
        addresses = subprocess.run(['ip', '-json', '-4', 'address', 'show'], capture_output=True, check=True, timeout=10)
        host_networks = [str(ipaddress.ip_network(address['local'] + '/' + str(address['prefixlen']), strict=False))
                         for interface in json.loads(addresses.stdout) for address in interface.get('addr_info', [])]
        public = public_networks([*blocked, *host_networks])
        current = {row['name']: row for row in self.instances()}
        for name, row in current.items():
            role = name[len(self.name) + 1:]
            if role not in images or row.get('config', {}).get('volatile.base_image') != images[role]:
                raise Refusal('Existing sandbox images cannot be changed or removed.')
        missing = [role for role in images if self.name + '-' + role not in current]
        stopped = [row for row in current.values() if row.get('status') == 'Stopped']
        if any(row.get('status') not in ('Stopped', 'Running') for row in current.values()):
            raise Refusal('The sandbox has an incomplete power transition; observe it before retrying.')
        if len(missing) + len(stopped) > self.capacity()['available']:
            raise Refusal('The host VM budget is full.')
        for row in stopped:
            snapshots = self.json('query', '/1.0/instances/' + row['name'] + '/snapshots?project=' + self.project)
            if any(value.split('?', 1)[0].endswith('/parked') for value in snapshots):
                raise Refusal('A parked sandbox must be resumed, not provisioned again.')
        for row in current.values():
            role = row['name'][len(self.name) + 1:]
            devices = row.get('devices', {})
            nic = devices.get('eth0', {})
            root = devices.get('root', {})
            if (row.get('profiles') != [] or set(devices) != {'root', 'eth0', 'worktree'}
                    or root != {'type': 'disk', 'path': '/', 'pool': pool, 'size': '20GiB'}
                    or devices.get('worktree') != {'type': 'disk', 'pool': pool, 'source': self.name + '-worktree', 'path': '/home/orbit/orbit'}
                    or nic != {'type': 'nic', 'network': self.name, 'name': 'eth0',
                               'ipv4.address': str(subnet.network_address + 10 + ROLES.index(role)),
                               'security.mac_filtering': 'true', 'security.ipv4_filtering': 'true'}):
                raise Refusal('Sandbox devices or profiles drifted; no mutation performed.')
        for fingerprint in images.values():
            if self.json('query', '/1.0/images/' + fingerprint + '?project=' + self.project).get('type') != 'virtual-machine':
                raise Refusal('The operator image must be a VM, never a container.')
        networks = self.json('network', 'list', '--format=json')
        for network in networks:
            if network['name'] == self.name:
                self.own(network)
                expected = {'ipv4.address': str(subnet.network_address + 1) + '/24',
                            'ipv4.nat': 'true', 'ipv6.address': 'none', 'dns.mode': 'none',
                            'security.acls': self.name, 'security.acls.default.egress.action': 'reject',
                            'security.acls.default.ingress.action': 'reject'}
                if network.get('type') != 'bridge' or any(network.get('config', {}).get(key) != value for key, value in expected.items()):
                    raise Refusal('Sandbox network policy drifted; no mutation performed.')
            else:
                address = network.get('config', {}).get('ipv4.address')
                if address not in (None, '', 'none', 'auto') and subnet.overlaps(ipaddress.ip_network(address, strict=False)):
                    raise Refusal('Sandbox subnet is already allocated.')
        volumes = self.volumes()
        if current and not volumes:
            raise Refusal('The sandbox worktree volume is missing; refusing to replace group data.')
        if volumes and volumes[0][0] != pool:
            raise Refusal('Sandbox worktree pool changed.')
        acls = self.json('network', 'acl', 'list', '--format=json')
        acl = next((row for row in acls if row['name'] == self.name), None)
        peers = ','.join(str(subnet.network_address + 10 + ROLES.index(role)) for role in images)
        acl_data = {'description': 'Task sandbox egress boundary', 'config': self.metadata(),
                        'ingress': [{'action': 'allow', 'source': peers, 'state': 'enabled'}],
                        'egress': [
                            {'action': 'allow', 'destination': peers, 'state': 'enabled'},
                            {'action': 'allow', 'destination': public, 'protocol': 'tcp', 'destination_port': '80,443', 'state': 'enabled'},
                            {'action': 'allow', 'destination': '1.1.1.1,9.9.9.9', 'protocol': 'udp', 'destination_port': '53', 'state': 'enabled'},
                            {'action': 'allow', 'destination': '1.1.1.1,9.9.9.9', 'protocol': 'tcp', 'destination_port': '53', 'state': 'enabled'},
                        ]}
        if acl is None:
            self.run('network', 'acl', 'create', self.name, data=json.dumps(acl_data).encode())
        else:
            self.own(acl)
            self.run('network', 'acl', 'edit', self.name, data=json.dumps(acl_data).encode())
        if not any(row['name'] == self.name for row in networks):
            self.run('network', 'create', self.name, '--type=bridge', 'ipv4.address=' + str(subnet.network_address + 1) + '/24',
                     'ipv4.nat=true', 'ipv6.address=none', 'dns.mode=none', 'security.acls=' + self.name,
                     'security.acls.default.egress.action=reject', 'security.acls.default.ingress.action=reject',
                     *[key + '=' + value for key, value in self.metadata().items()])
        if not volumes:
            self.run('storage', 'volume', 'create', pool, self.name + '-worktree', 'size=20GiB',
                     *[key + '=' + value for key, value in self.metadata().items()])
        for role in missing:
            name = self.name + '-' + role
            config = {**self.metadata(), 'limits.cpu': '2', 'limits.memory': '4GiB'}
            address = str(subnet.network_address + 10 + ROLES.index(role))
            payload = {'name': name, 'type': 'virtual-machine', 'profiles': [], 'config': config,
                       'source': {'type': 'image', 'fingerprint': images[role]},
                       'devices': {'worktree': {'type': 'disk', 'pool': pool, 'source': self.name + '-worktree', 'path': '/home/orbit/orbit'},
                                   'root': {'type': 'disk', 'path': '/', 'pool': pool, 'size': '20GiB'},
                                   'eth0': {'type': 'nic', 'network': self.name, 'name': 'eth0', 'ipv4.address': address,
                                            'security.mac_filtering': 'true', 'security.ipv4_filtering': 'true'}}}
            self.run('query', '-X', 'POST', '/1.0/instances?project=' + self.project,
                     '-d', json.dumps(payload), '--wait', timeout=300)
            self.run('start', name, timeout=180)
        for row in stopped:
            self.run('start', row['name'], timeout=180)
        return self.observe()

    def park(self):
        rows = self.instances()
        volumes = self.volumes()
        for row in rows:
            if row['status'] != 'Stopped':
                self.run('stop', row['name'], '--timeout=60', timeout=90)
        for pool, volume in volumes:
            self.run('storage', 'volume', 'snapshot', 'create', pool, volume['name'], 'parked', '--reuse')
        for row in rows:
            snapshots = self.json('query', '/1.0/instances/' + row['name'] + '/snapshots?project=' + self.project)
            if any(value.split('?', 1)[0].endswith('/parked') for value in snapshots):
                self.run('snapshot', 'delete', row['name'], 'parked')
            self.run('snapshot', 'create', row['name'], 'parked')
        return self.observe()

    def resume(self):
        rows = self.instances()
        volumes = self.volumes()
        stopped = [row for row in rows if row['status'] == 'Stopped']
        if len(stopped) > self.capacity()['available']:
            raise Refusal('The host VM budget is full.')
        if stopped and len(stopped) != len(rows):
            raise Refusal('Stop every sandbox VM before restoring a shared worktree snapshot.')
        if stopped:
            for pool, volume in volumes:
                self.run('storage', 'volume', 'snapshot', 'restore', pool, volume['name'], 'parked')
        for row in stopped:
            self.run('snapshot', 'restore', row['name'], 'parked')
        for row in stopped:
            self.run('start', row['name'])
        return self.observe()

    def destroy(self):
        rows = self.instances()
        volumes = self.volumes()
        networks = self.json('network', 'list', '--format=json')
        acls = self.json('network', 'acl', 'list', '--format=json')
        network = next((row for row in networks if row['name'] == self.name), None)
        acl = next((row for row in acls if row['name'] == self.name), None)
        if network:
            self.own(network)
        if acl:
            self.own(acl)
        for row in rows:
            self.run('delete', row['name'], '--force', timeout=180)
        for pool, volume in volumes:
            self.run('storage', 'volume', 'delete', pool, volume['name'])
        if network:
            self.run('network', 'delete', self.name)
        if acl:
            self.run('network', 'acl', 'delete', self.name)
        result = self.observe()
        if result['power'] != 'destroyed' or self.volumes():
            raise Refusal('Sandbox cleanup left instances behind.')
        return result


def main():
    payload = sys.stdin.buffer.read(1024 * 1024 + 1)
    if len(payload) > 1024 * 1024:
        raise Refusal('Sandbox request is too large.')
    request = json.loads(payload)
    host = Host(request['project'], request['sandbox_id'], request['budget'])
    # Capacity and partial provisioning share a host lock across every task sandbox.
    descriptor = os.open('/run/lock/orbit-task-sandboxes.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+') as lock:
        details = os.fstat(lock.fileno())
        if not stat.S_ISREG(details.st_mode) or details.st_uid != os.geteuid() or details.st_nlink != 1:
            raise Refusal('Unsafe sandbox lock.')
        fcntl.flock(lock, fcntl.LOCK_EX)
        operation = request['operation']
        if operation == 'provision':
            result = host.provision(request['spec'])
        elif operation in ('observe', 'capacity', 'park', 'resume', 'destroy'):
            result = getattr(host, operation)()
        else:
            raise Refusal('Unknown sandbox operation.')
    print(json.dumps(result))


if __name__ == '__main__':
    try:
        main()
    except Refusal as error:
        print(json.dumps({'error': 'sandbox_operation_refused', 'message': str(error)}))
        sys.exit(1)
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_operation_refused'}))
        sys.exit(1)
