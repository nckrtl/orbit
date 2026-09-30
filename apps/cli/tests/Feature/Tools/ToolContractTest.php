<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

/**
 * Contract tests replay recorded Gateway inventory responses and compare the complete
 * command output with tests/Expected.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tool-contract-'.Str::uuid();
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

describe('tool scan contract', function (): void {
    it('renders a recorded inventory', function (string $fixture): void {
        run_contract($fixture, 'tool:scan', ['--node' => '2'], $fixture.'.human.txt', 0);
        run_contract($fixture, 'tool:scan', ['--node' => '2', '--json' => true], $fixture.'.json', 0);
    })->with([
        'discovered' => ['tools/tool-scan/discovered'],
        'empty' => ['tools/tool-scan/empty'],
        'linux' => ['tools/tool-scan/linux'],
        'partial' => ['tools/tool-scan/partial'],
    ]);

    it('renders a recorded scan refusal', function (string $fixture): void {
        run_contract($fixture, 'tool:scan', ['--node' => '2'], $fixture.'.human.txt', 1);
        run_contract($fixture, 'tool:scan', ['--node' => '2', '--json' => true], $fixture.'.json', 1);
    })->with([
        'access required' => ['tools/tool-scan/access-required'],
        'inactive node' => ['tools/tool-scan/node-inactive'],
        'unmanaged node' => ['tools/tool-scan/node-unmanaged'],
    ]);
});
