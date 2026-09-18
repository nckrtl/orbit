<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
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

/**
 * @param  string|list<string>  $fixtures
 * @param  array<string, mixed>  $arguments
 */
function run_realtime_contract(string|array $fixtures, string $command, array $arguments, string $expected, int $exitCode): void
{
    // A global mock keeps its first responses, so replace it for every replay.
    MockClient::destroyGlobal();
    MockClient::global(gateway_fixture_mock(...(array) $fixtures));

    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);
}

describe('realtime contract', function (): void {
    it('renders realtime:show when broadcasting is configured', function (): void {
        run_realtime_contract('realtime/realtime-show/configured', 'realtime:show', [], 'realtime/realtime-show/configured.human.txt', 0);
        run_realtime_contract('realtime/realtime-show/configured', 'realtime:show', ['--json' => true], 'realtime/realtime-show/configured.json', 0);
    });

    it('renders realtime:show when broadcasting is not configured', function (): void {
        run_realtime_contract('realtime/realtime-show/unconfigured', 'realtime:show', [], 'realtime/realtime-show/unconfigured.human.txt', 0);
        run_realtime_contract('realtime/realtime-show/unconfigured', 'realtime:show', ['--json' => true], 'realtime/realtime-show/unconfigured.json', 0);
    });
});
