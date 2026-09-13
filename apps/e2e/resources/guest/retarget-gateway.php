<?php

declare(strict_types=1);

if ($argc !== 5) {
    exit(64);
}

$database = $argv[1];
$addresses = array_combine(['gateway', 'app-dev', 'app-prod'], array_slice($argv, 2));
$pdo = null;

try {
    if (! is_file($database) || is_link($database) || count(array_unique($addresses)) !== 3) {
        throw new RuntimeException('Invalid clone inputs.');
    }
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Invalid clone address.');
        }
    }

    $pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT id, name, status, public_ssh_host, wireguard_endpoint_override FROM nodes WHERE name IN (?, ?, ?)');
    $query->execute(array_keys($addresses));
    $nodes = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $node) {
        if (isset($nodes[$node['name']]) || $node['status'] !== 'active'
            || filter_var($node['public_ssh_host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Invalid clone inventory.');
        }
        $nodes[$node['name']] = $node;
    }
    if (count($nodes) !== 3) {
        throw new RuntimeException('Incomplete clone inventory.');
    }
    $role = $pdo->prepare('SELECT COUNT(*) FROM node_roles WHERE node_id = ? AND role = ? AND status = ?');
    foreach (['gateway' => ['gateway', 'vpn'], 'app-dev' => ['app-dev'], 'app-prod' => ['app-prod']] as $name => $roles) {
        foreach ($roles as $required) {
            $role->execute([$nodes[$name]['id'], $required, 'active']);
            if ((int) $role->fetchColumn() !== 1) {
                throw new RuntimeException('Incomplete clone roles.');
            }
        }
    }

    $query = $pdo->prepare('SELECT id, key, value, is_secret FROM settings WHERE scope_type = ? AND scope_id = ? AND key IN (?, ?)');
    $query->execute(['gateway', 0, 'vpn.endpoint', 'vpn.port']);
    $settings = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $setting) {
        if (isset($settings[$setting['key']]) || (bool) $setting['is_secret']) {
            throw new RuntimeException('Invalid clone endpoint settings.');
        }
        $settings[$setting['key']] = $setting;
    }
    $port = filter_var($settings['vpn.port']['value'] ?? '51820', FILTER_VALIDATE_INT);
    if (! is_int($port) || $port < 1 || $port > 65_535) {
        throw new RuntimeException('Invalid clone VPN port.');
    }
    $retarget = static function (?string $endpoint) use ($nodes, $addresses): ?string {
        if ($endpoint === null) {
            return null;
        }
        if (preg_match('/\A([^:]+):([1-9][0-9]{0,4})\z/D', $endpoint, $parts) !== 1
            || (int) $parts[2] > 65_535
            || ! in_array($parts[1], [$nodes['gateway']['public_ssh_host'], $addresses['gateway']], true)) {
            throw new RuntimeException('Conflicting clone endpoint.');
        }

        return $addresses['gateway'].':'.$parts[2];
    };
    $endpoint = $retarget($settings['vpn.endpoint']['value'] ?? null);
    $overrides = [];
    $endpoints = [];
    foreach (['app-dev', 'app-prod'] as $name) {
        $overrides[$name] = $retarget($nodes[$name]['wireguard_endpoint_override']);
        $endpoints[$name] = $overrides[$name] ?? $endpoint ?? $addresses['gateway'].':'.$port;
    }

    $updateHost = $pdo->prepare('UPDATE nodes SET public_ssh_host = ? WHERE id = ?');
    foreach ($addresses as $name => $address) {
        $updateHost->execute([$address, $nodes[$name]['id']]);
    }
    $updateOverride = $pdo->prepare('UPDATE nodes SET wireguard_endpoint_override = ? WHERE id = ?');
    foreach ($overrides as $name => $override) {
        if ($override !== $nodes[$name]['wireguard_endpoint_override']) {
            $updateOverride->execute([$override, $nodes[$name]['id']]);
        }
    }
    if ($endpoint !== ($settings['vpn.endpoint']['value'] ?? null)) {
        $query = $pdo->prepare('UPDATE settings SET value = ? WHERE id = ?');
        $query->execute([$endpoint, $settings['vpn.endpoint']['id']]);
    }
    $output = json_encode($endpoints, JSON_THROW_ON_ERROR);
    $pdo->commit();
    echo $output, "\n";
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Clone Gateway identity preparation failed; inventory or endpoint configuration is invalid.\n");
    exit(65);
}
