<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{root:string,state:string,script:string,log:string} */
function shared_cluster_fixture(): array
{
    $root = temporaryPath('orbit-shared-cluster-', 4);
    mkdir($root, 0700, true);
    $nodes = [
        ['id' => 1, 'name' => 'gateway', 'status' => 'active', 'cluster_id' => null, 'roles' => ['gateway', 'vpn']],
        ['id' => 2, 'name' => 'app-dev', 'status' => 'active', 'cluster_id' => 7, 'roles' => ['app-dev', 'metrics', 'router']],
        ['id' => 3, 'name' => 'app-prod', 'status' => 'active', 'cluster_id' => null, 'roles' => ['app-prod']],
    ];
    $cluster = ['id' => 7, 'name' => 'e2e-development', 'state' => 'active', 'tld' => null, 'nodes' => [$nodes[1]], 'router' => $nodes[1]];
    file_put_contents("{$root}/state.json", json_encode(['nodes' => $nodes, 'clusters' => [$cluster]], JSON_THROW_ON_ERROR));
    file_put_contents("{$root}/orbit", <<<'FAKE'
        #!/usr/bin/env php
        <?php
        $path = __DIR__.'/state.json';
        $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents(__DIR__.'/commands', implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
        $command = $argv[1];
        if ($command === 'node:list' || $command === 'cluster:list') {
            $key = $command === 'node:list' ? 'nodes' : 'clusters';
            echo json_encode([$key => $state[$key]], JSON_THROW_ON_ERROR);
            exit;
        }
        if (getenv('FAIL_COMMAND') === $command) {
            echo json_encode(['error' => ['code' => getenv('FAIL_CODE') ?: null], 'secret' => 'do-not-print'], JSON_THROW_ON_ERROR);
            exit(1);
        }
        $cluster = &$state['clusters'][0];
        if ($command === 'cluster:node:add') {
            $node = &$state['nodes'][(int) $argv[3] - 1];
            $node['cluster_id'] = $cluster['id'];
            $cluster['nodes'][] = $node;
        } elseif ($command === 'cluster:router:set') {
            foreach ($state['nodes'] as &$node) {
                $node['roles'] = array_values(array_diff($node['roles'], ['router']));
                if ($node['id'] === (int) $argv[3]) {
                    $node['roles'][] = 'router';
                    $cluster['router'] = $node;
                }
            }
        } elseif ($command === 'node:role:add') {
            $node = &$state['nodes'][(int) $argv[2] - 1];
            $node['roles'] = array_values(array_unique([...$node['roles'], $argv[3]]));
        } else {
            exit(64);
        }
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
        if ($command === 'cluster:router:set' && getenv('LOSE_ROUTER_RESPONSE') && !file_exists(__DIR__.'/lost-response')) {
            touch(__DIR__.'/lost-response');
            echo '{"error":{"code":"gateway.unreachable"}}';
            exit(1);
        }
        echo '{"request_id":"fixture"}';
        FAKE);
    chmod("{$root}/orbit", 0700);
    file_put_contents("{$root}/script.sh", str_replace(
        'orbit=/home/orbit/orbit/apps/cli/orbit',
        "orbit={$root}/orbit",
        file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh'),
    ));

    return ['root' => $root, 'state' => "{$root}/state.json", 'script' => "{$root}/script.sh", 'log' => "{$root}/commands"];
}

/**
 * @param  array{root:string,state:string,script:string,log:string}  $fixture
 * @param  array<string,string>  $environment
 */
function shared_cluster_process(array $fixture, string $mode = 'shared-cluster', ?string $failure = null, array $environment = []): Process
{
    return new Process(['bash', $fixture['script'], $mode, 'gateway', 'app-dev', 'app-prod'], env: ['FAIL_COMMAND' => $failure ?? '', ...$environment]);
}

it('expands the sample Cluster and repeats without extra members', function () {
    $fixture = shared_cluster_fixture();
    try {
        $process = shared_cluster_process($fixture);
        expect($process->run())->toBe(0, $process->getErrorOutput());
        $state = json_decode(file_get_contents($fixture['state']), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($state['nodes'], 'cluster_id'))->toBe([7, 7, 7]);
        expect($state['clusters'][0]['router']['name'])->toBe('gateway');
        expect(array_column($state['nodes'], 'roles'))->toBe([
            ['gateway', 'vpn', 'router', 'websocket'], ['app-dev', 'metrics', 'database'], ['app-prod', 'ingress'],
        ]);
        $repeat = shared_cluster_process($fixture);
        expect($repeat->run())->toBe(0, $repeat->getErrorOutput());
        expect(substr_count(file_get_contents($fixture['log']), 'cluster:node:add'))->toBe(2);
        $before = file_get_contents($fixture['state']);
        file_put_contents($fixture['log'], '');
        expect(shared_cluster_process($fixture, 'verify-cluster')->run())->toBe(0);
        expect(file_get_contents($fixture['state']))->toBe($before);
        expect(file_get_contents($fixture['log']))->toBe("node:list --json\ncluster:list --json\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('refuses conflicting topology state before any mutation', function (string $conflict) {
    $fixture = shared_cluster_fixture();
    try {
        $state = json_decode(file_get_contents($fixture['state']), true, flags: JSON_THROW_ON_ERROR);
        match ($conflict) {
            'membership' => $state['nodes'][0]['cluster_id'] = 99,
            'foreign member' => $state['clusters'][0]['nodes'][] = ['id' => 4, 'name' => 'other', 'status' => 'active'],
            'router' => $state['clusters'][0]['router'] = $state['nodes'][2],
            'ingress' => $state['nodes'][0]['roles'][] = 'ingress',
            'websocket' => $state['nodes'][1]['roles'][] = 'websocket',
            'inactive' => $state['nodes'][2]['status'] = 'failed',
            'duplicate' => $state['nodes'][] = $state['nodes'][0],
            'tld' => $state['clusters'][0]['tld'] = 'foreign.orbit',
        };
        file_put_contents($fixture['state'], json_encode($state, JSON_THROW_ON_ERROR));
        $before = file_get_contents($fixture['state']);
        expect(shared_cluster_process($fixture)->run())->toBe(65);
        expect(file_get_contents($fixture['state']))->toBe($before);
        expect(file_get_contents($fixture['log']))->toBe("node:list --json\ncluster:list --json\n");
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['membership', 'foreign member', 'router', 'ingress', 'websocket', 'inactive', 'duplicate', 'tld']);

it('reports an incomplete shared Cluster without changing it', function () {
    $fixture = shared_cluster_fixture();
    try {
        $before = file_get_contents($fixture['state']);
        expect(shared_cluster_process($fixture, 'verify-cluster')->run())->toBe(65);
        expect(file_get_contents($fixture['state']))->toBe($before);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('stops at a failed mutation without exposing its response and resumes from recorded membership', function () {
    $fixture = shared_cluster_fixture();
    try {
        $process = shared_cluster_process($fixture, failure: 'node:role:add');
        expect($process->run())->toBe(65);
        expect($process->getErrorOutput())->toContain('Orbit node:role:add failed.')->not->toContain('do-not-print');
        expect($process->getOutput())->not->toContain('do-not-print');
        $retry = shared_cluster_process($fixture);
        expect($retry->run())->toBe(0, $retry->getErrorOutput());
        expect(substr_count(file_get_contents($fixture['log']), 'cluster:node:add'))->toBe(2);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('retries the same Router assignment after its successful operation loses the API response', function () {
    $fixture = shared_cluster_fixture();
    try {
        $process = shared_cluster_process($fixture, environment: ['LOSE_ROUTER_RESPONSE' => '1']);
        expect($process->run())->toBe(0, $process->getErrorOutput());
        expect(substr_count(file_get_contents($fixture['log']), 'cluster:router:set'))->toBe(2);
        expect(shared_cluster_process($fixture, 'verify-cluster')->run())->toBe(0);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('bounds Router busy retries and stops before additional roles', function () {
    $fixture = shared_cluster_fixture();
    try {
        $process = shared_cluster_process($fixture, failure: 'cluster:router:set', environment: ['FAIL_CODE' => 'cluster.router_busy']);
        expect($process->run())->toBe(65);
        expect(substr_count(file_get_contents($fixture['log']), 'cluster:router:set'))->toBe(5);
        expect(file_get_contents($fixture['log']))->not->toContain('node:role:add');
        expect($process->getErrorOutput())->toContain('[cluster.router_busy]');
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});
