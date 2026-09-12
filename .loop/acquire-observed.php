<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

$root = dirname(__DIR__);
require $root.'/apps/e2e/vendor/autoload.php';
$app = require $root.'/apps/e2e/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$real = new Factory;
$observations = [];
$probe = <<<'PHP'
<?php
$role = $argv[1];
$result = ['role' => $role, 'files' => []];
$files = ['/etc/wireguard/orbit.conf', '/etc/resolv.conf', '/home/orbit/.orbit/wireguard/private.key', '/home/orbit/.orbit/wireguard/public.key', '/home/orbit/.orbit/ssh/id_ed25519', '/home/orbit/.orbit/ssh/id_ed25519.pub'];
foreach ($files as $file) {
    if (!is_file($file)) { $result['files'][$file] = null; continue; }
    $data = file_get_contents($file);
    if ($file === '/etc/wireguard/orbit.conf') {
        preg_match_all('/^Endpoint *= *(.*)$/m', $data, $matches);
        $result['saved_endpoints'] = $matches[1];
        if ($role !== 'gateway') $data = preg_replace('/^Endpoint *= *[^:\r\n]+:/m', 'Endpoint = <gateway>:', $data);
    }
    $result['files'][$file] = hash('sha256', $data);
}
if ($role === 'gateway') {
    $pdo = new PDO('sqlite:/home/orbit/.orbit/gateway.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA query_only = ON');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $rows = $pdo->query('SELECT * FROM "'.str_replace('"', '""', $table).'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if ($table === 'nodes' && in_array($row['name'], ['gateway', 'app-dev', 'app-prod'], true)) {
                $result['public_ssh_hosts'][$row['name']] = $row['public_ssh_host'];
                $row['public_ssh_host'] = '<clone>';
                if ($row['name'] !== 'gateway' && $row['wireguard_endpoint_override'] !== null) {
                    $row['wireguard_endpoint_override'] = preg_replace('/^[^:]+:/', '<gateway>:', $row['wireguard_endpoint_override']);
                }
            }
            if ($table === 'settings' && $row['scope_type'] === 'gateway' && (int)$row['scope_id'] === 0 && $row['key'] === 'vpn.endpoint' && $row['value'] !== null) {
                $result['gateway_endpoint'] = $row['value'];
                $row['value'] = preg_replace('/^[^:]+:/', '<gateway>:', $row['value']);
            }
        }
        unset($row);
        $result['tables'][$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
$capture = static function (string $phase) use ($root, $real, $probe, &$observations): void {
    $lease = json_decode(file_get_contents($root.'/.e2e/attempt.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($lease['issue'] !== 'ORB-246' || $lease['purpose'] !== 'discovery'
        || ! preg_match('/\A[0-9a-f]{32}\z/', $lease['attempt_id'])) {
        throw new RuntimeException('Observation lease mismatch.');
    }
    $observations['attempt_id'] = $lease['attempt_id'];
    foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
        $name = 'orbit-e2e-orb-246-'.substr($lease['attempt_id'], 0, 8).'-'.$role;
        $inventory = $real->run(['incus', '--project', 'default', 'list', 'local:'.$name, '--format=json']);
        if (! $inventory->successful()) throw new RuntimeException('Observation inventory failed.');
        $vms = array_values(array_filter(json_decode($inventory->output(), true, flags: JSON_THROW_ON_ERROR), static fn ($vm) => $vm['name'] === $name));
        if (count($vms) !== 1) throw new RuntimeException('Observation VM missing.');
        foreach (['owner' => 'orbit-e2e', 'issue' => 'ORB-246', 'attempt' => $lease['attempt_id'], 'operation' => $lease['operation_id']] as $field => $expected) {
            if (($vms[0]['config']['user.orbit.e2e.'.$field] ?? null) !== $expected) throw new RuntimeException('Observation ownership mismatch.');
        }
        $result = $real->timeout(30)->input($probe)->run(['incus', '--project', 'default', 'exec', 'local:'.$name, '--', 'php', '/dev/stdin', $role]);
        if (! $result->successful()) throw new RuntimeException('Observation probe failed on '.$role.'.');
        $observations[$phase][$role] = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }
    file_put_contents($root.'/.loop/clone-observations-'.$lease['attempt_id'].'.json', json_encode($observations, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    echo 'Captured '.$phase." fingerprints.\n";
};
Process::fake(static function (PendingProcess $pending) use ($real, $capture) {
    $labels = [];
    if (is_array($pending->command) && str_ends_with($pending->command[1] ?? '', '/resources/host/exec-all.py')) {
        $payload = json_decode($pending->input, true, flags: JSON_THROW_ON_ERROR);
        $labels = array_column($payload['requests'], 'label');
    }
    if ($labels === ['retarget-gateway']) $capture('before');
    $forward = new PendingProcess($real);
    foreach (['command', 'path', 'timeout', 'idleTimeout', 'environment', 'input', 'quietly', 'tty', 'options'] as $property) {
        $forward->$property = $pending->$property;
    }
    $result = $forward->run();
    if ($labels === ['retarget-vpn.app-dev', 'retarget-vpn.app-prod'] && $result->successful()) $capture('after');

    return $result;
});
$exit = $kernel->call('topology:acquire', ['issue' => 'ORB-246', 'worktree' => $root, '--no-interaction' => true]);
echo $kernel->output();
if ($exit !== 0) exit($exit);
if (! isset($observations['before'], $observations['after'])) throw new RuntimeException('Acquisition lacked observations.');
foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
    foreach (['files', 'tables'] as $field) {
        if (($observations['before'][$role][$field] ?? []) !== ($observations['after'][$role][$field] ?? [])) {
            throw new RuntimeException('Preservation comparison failed on '.$role.' '.$field.'.');
        }
    }
}
echo "All normalized file and database fingerprints preserved.\n";
