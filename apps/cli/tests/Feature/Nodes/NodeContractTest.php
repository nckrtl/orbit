<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

/**
 * Contract tests replay recorded Gateway responses and compare the complete command
 * output with tests/Expected. A fixture change that reaches a command fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

$addArguments = [
    'name' => 'app-dev',
    'host' => '94.237.40.75',
    '--role' => ['app-dev'],
    '--tld' => 'app-dev.orbit',
    '--cluster' => '1',
    '--wireguard-ip' => '10.44.0.3',
    '--lan-ip' => '10.0.0.3',
    '--host-key-fingerprint' => 'SHA256:4dxvKOYfyTcqJHYoxamTSu9bYYI5KE3xYWQPCAmeUTo',
];

describe('node contract', function () use ($addArguments): void {
    it('renders node:list from the recorded response', function (): void {
        run_contract('nodes/node-list/default', 'node:list', [], 'nodes/node-list/default.human.txt', 0);
        run_contract('nodes/node-list/default', 'node:list', ['--json' => true], 'nodes/node-list/default.json', 0);
    });

    it('renders node:show from the recorded response', function (): void {
        run_contract('nodes/node-show/default', 'node:show', ['node' => '2'], 'nodes/node-show/default.human.txt', 0);
        run_contract('nodes/node-show/default', 'node:show', ['node' => '2', '--json' => true], 'nodes/node-show/default.json', 0);
    });

    it('renders a created node', function () use ($addArguments): void {
        run_contract('nodes/node-add/created', 'node:add', $addArguments, 'nodes/node-add/created.human.txt', 0);
        run_contract('nodes/node-add/created', 'node:add', [...$addArguments, '--json' => true], 'nodes/node-add/created.json', 0);
    });

    it('renders the node:add refusals', function () use ($addArguments): void {
        $withoutTld = array_diff_key($addArguments, ['--tld' => null]);
        run_contract('nodes/node-add/tld-required', 'node:add', $withoutTld, 'nodes/node-add/tld-required.human.txt', 1);
        run_contract('nodes/node-add/tld-required', 'node:add', [...$withoutTld, '--json' => true], 'nodes/node-add/tld-required.json', 1);

        $withoutFingerprint = array_diff_key($addArguments, ['--host-key-fingerprint' => null]);
        run_contract('nodes/node-add/fingerprint-required', 'node:add', $withoutFingerprint, 'nodes/node-add/fingerprint-required.human.txt', 1);
        run_contract('nodes/node-add/fingerprint-required', 'node:add', [...$withoutFingerprint, '--json' => true], 'nodes/node-add/fingerprint-required.json', 1);
    });
});
