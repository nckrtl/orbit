<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=280');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-doctor-contract-'.Str::uuid();
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

describe('doctor discovery contract', function (): void {
    it('exits 0 for a recorded informational report', function (string $fixture): void {
        run_contract($fixture, 'doctor', ['--family' => ['tool']], $fixture.'.human.txt', 0);
        run_contract($fixture, 'doctor', ['--family' => ['tool'], '--json' => true], $fixture.'.json', 0);
    })->with([
        'informational only' => ['doctor/doctor/informational'],
        'empty managers' => ['doctor/doctor/empty-managers'],
    ]);

    it('exits 1 for a recorded unhealthy report', function (string $fixture): void {
        run_contract($fixture, 'doctor', ['--family' => ['tool']], $fixture.'.human.txt', 1);
        run_contract($fixture, 'doctor', ['--family' => ['tool'], '--json' => true], $fixture.'.json', 1);
    })->with([
        'mixed drift' => ['doctor/doctor/mixed'],
        'unreachable' => ['doctor/doctor/unreachable'],
        'truncated inventory' => ['doctor/doctor/truncated'],
    ]);
});
