<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\SelfUpdate\SelfUpdateHost;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Tests\Support\FakeSelfUpdateHost;

/**
 * Replays the recorded desired state through `self-update` and `gateway:status`. The machine directory is
 * random, so the output names it `/usr/local` before it is compared.
 */
function run_self_update_contract(object $test, string $fixture, string $command, array $arguments, string $expected, int $exitCode): void
{
    MockClient::destroyGlobal();
    MockClient::global(gateway_fixture_mock($fixture));

    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(str_replace($test->machine, '/usr/local', Artisan::output()), $expected);
}

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-self-update-contract-'.Str::uuid();
    $this->machine = sys_get_temp_dir().'/orbit-self-update-contract-'.Str::uuid();
    mkdir($this->machine.'/bin', 0755, true);
    file_put_contents($this->machine.'/bin/orbit', "#!/bin/sh\necho 'Orbit 0.4600.0'\n");
    chmod($this->machine.'/bin/orbit', 0755);
    config()->set('orbit.home', $this->orbitHome);
    config()->set('app.version', '0.4600.0');
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'default', url: 'https://10.44.0.1', caPath: '/root/.orbit/ca/root.pem'));
    app()->instance(SelfUpdateHost::class, new FakeSelfUpdateHost($this->machine));
    fake_self_update_processes($this);
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    new Filesystem()->deleteDirectory($this->machine);
});

describe('self-update contract', function (): void {
    it('updates the CLI to the published release', function (): void {
        run_self_update_contract($this, 'gateway/self-update/available', 'self-update', [], 'gateway/self-update/updated.human.txt', 0);
        new Filesystem()->cleanDirectory($this->machine.'/bin');
        file_put_contents($this->machine.'/bin/orbit', "#!/bin/sh\necho 'Orbit 0.4600.0'\n");
        chmod($this->machine.'/bin/orbit', 0755);
        run_self_update_contract($this, 'gateway/self-update/available', 'self-update', ['--json' => true], 'gateway/self-update/updated.json', 0);
    });

    it('reports a checksum mismatch and changes nothing', function (): void {
        fake_self_update_processes($this, ['cli-v0.4681.0/orbit-0.4681.0-linux-x86_64' => "tampered\n"]);

        run_self_update_contract($this, 'gateway/self-update/available', 'self-update', [], 'gateway/self-update/checksum-mismatch.human.txt', 1);
        run_self_update_contract($this, 'gateway/self-update/available', 'self-update', ['--json' => true], 'gateway/self-update/checksum-mismatch.json', 1);
    });

    it('waits for a pending CLI release', function (): void {
        run_self_update_contract($this, 'gateway/self-update/pending', 'self-update', [], 'gateway/self-update/pending.human.txt', 0);
        run_self_update_contract($this, 'gateway/self-update/pending', 'self-update', ['--json' => true], 'gateway/self-update/pending.json', 0);
    });
});

describe('gateway:status contract', function (): void {
    it('shows the desired state an active peer receives', function (): void {
        run_self_update_contract($this, 'gateway/gateway-status/peer', 'gateway:status', [], 'gateway/gateway-status/peer.human.txt', 0);
        run_self_update_contract($this, 'gateway/gateway-status/peer', 'gateway:status', ['--json' => true], 'gateway/gateway-status/peer.json', 0);
    });
});
