"""Independent full-field assertion for the controlled ORB355 Node detail cases.

Call with final reconstructed frame lines and a separate node:show JSON payload.
Tested against trial8 24/80/160-column captures. Not a generic Unicode cell parser.
"""
import re


def assert_node_detail(lines, node):
    compact = lambda value: ''.join(str(value).split())
    display = lambda value: '—' if value is None or value == '' or value == [] else str(value)
    access = node.get('access') or {}

    def access_list(key):
        return ', '.join(f"{item['name']} (#{item['id']})" for item in access.get(key, [])) or '—'

    endpoint = (f"{node['user']}@{node['public_ssh_host']}:{node['public_ssh_port']}"
                if node.get('user') and node.get('public_ssh_host') and node.get('public_ssh_port', 0) > 0 else '—')
    tld = '.' + node['tld'].lstrip('.') if node.get('tld') else '—'
    platform = ' '.join(value for value in [node.get('platform'),
                        '(' + node['architecture'] + ')' if node.get('architecture') is not None else None] if value)
    expected = [
        ('ID', node['id']), ('Status', node['status']), ('Roles', ', '.join(node['roles'])),
        ('SSH', endpoint), ('Cluster', node.get('cluster_id')), ('WireGuard', node.get('wireguard_ip')),
        ('LAN', node.get('lan_ip')), ('WireGuard public key', node.get('wireguard_public_key')),
        ('WireGuard endpoint override', node.get('wireguard_endpoint_override')),
        ('DNS server override', node.get('dns_server_override')), ('TLD', tld), ('Platform', platform),
        ('Apps path', ((node.get('settings') or {}).get('apps') or {}).get('path')),
        ('Access to', access_list('can_access')), ('Accessible by', access_list('accessible_by')),
    ]
    if node.get('failed_step') is not None or node.get('error_code') is not None:
        expected.append(('Failure', ' / '.join(value for value in [node.get('failed_step'), node.get('error_code')] if isinstance(value, str))))

    title = 'Node: ' + node['name']
    start = next(i for i, line in enumerate(lines) if title in line)
    detail = lines[start + 1:]
    id_line = next(line for line in detail if re.match(r'^├\s+ID\s+', line))
    match = re.search(r'\bID\s+(\d+)', id_line)
    assert match and match.group(1) == str(node['id']), id_line
    value_column = match.start(1)
    rows = []
    for line in detail:
        if line.startswith('Request ID:'):
            break
        if line.startswith(('├', '└')):
            rows.append(['', ''])
        if rows:
            rows[-1][0] += compact(line[3:value_column])
            rows[-1][1] += compact(line[value_column:])
    assert rows == [[compact(label), compact(display(value))] for label, value in expected], (rows, expected)
    return expected
