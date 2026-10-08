<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

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

describe('fleet contract', function (): void {
    it('renders fleet:rollout:status before the first rollout, halted, and completed', function (string $case): void {
        run_contract("fleet/fleet-rollout-status/{$case}", 'fleet:rollout:status', [], "fleet/fleet-rollout-status/{$case}.human.txt", 0);
        run_contract("fleet/fleet-rollout-status/{$case}", 'fleet:rollout:status', ['--json' => true], "fleet/fleet-rollout-status/{$case}.json", 0);
    })->with(['none', 'halted', 'completed']);

    it('renders fleet:rollout:resume past a skipped Node', function (): void {
        run_contract('fleet/fleet-rollout-resume/skipped', 'fleet:rollout:resume', ['--skip' => 'app-dev'], 'fleet/fleet-rollout-resume/skipped.human.txt', 0);
        run_contract('fleet/fleet-rollout-resume/skipped', 'fleet:rollout:resume', ['--skip' => 'app-dev', '--json' => true], 'fleet/fleet-rollout-resume/skipped.json', 0);
    });

    it('renders a refused fleet:rollout:resume', function (): void {
        run_contract('fleet/fleet-rollout-resume/not-halted', 'fleet:rollout:resume', [], 'fleet/fleet-rollout-resume/not-halted.human.txt', 1);
        run_contract('fleet/fleet-rollout-resume/not-halted', 'fleet:rollout:resume', ['--json' => true], 'fleet/fleet-rollout-resume/not-halted.json', 1);
    });

    it('renders node:converge applied, unchanged, and refused', function (string $case, int $exit): void {
        run_contract("nodes/node-converge/{$case}", 'node:converge', ['node' => '2'], "nodes/node-converge/{$case}.human.txt", $exit);
        run_contract("nodes/node-converge/{$case}", 'node:converge', ['node' => '2', '--json' => true], "nodes/node-converge/{$case}.json", $exit);
    })->with([['applied', 0], ['unchanged', 0], ['unsupported', 1]]);
});
