<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\SelfUpdate\CliReleaseNotice;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\StringInput;
use Tests\Support\CapturedConsoleOutput;

const CLI_NOTICE_TEXT = 'Orbit 0.4681.0 is available (this is 0.4600.0). Run orbit self-update.';

function cli_release_notice(string $home, string $current = '0.4600.0', ?Closure $clock = null): CliReleaseNotice
{
    return new CliReleaseNotice($home.'/'.CliReleaseNotice::StateFile, $current, CliReleaseNotice::IntervalSeconds, $clock);
}

function gateway_status_with_cli_version(?string $version): MockResponse
{
    return MockResponse::make([
        'data' => ['name' => 'orbit-gateway', 'status' => 'ok', 'version' => str_repeat('a', 40), 'php_version' => '8.5.0', 'laravel_version' => '13.0.0', 'desired_fleet_state' => null],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 200, $version === null ? [] : ['X-Orbit-Cli-Version' => $version]);
}

/** Runs one command as the binary does, with standard output and standard error kept apart. */
function run_with_captured_streams(string $command): CapturedConsoleOutput
{
    $output = new CapturedConsoleOutput;
    app(Kernel::class)->handle(new StringInput($command), $output);

    return $output;
}

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-notice-'.Str::uuid();
    mkdir($this->orbitHome, 0700, true);
    $this->now = 1_791_000_000;
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    app()->forgetInstance(CliReleaseNotice::class);
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe(CliReleaseNotice::class, function (): void {
    it('announces a newer release once per day', function (): void {
        $notice = cli_release_notice($this->orbitHome, clock: fn (): int => $this->now);
        $notice->observe('0.4681.0');

        expect($notice->due())->toBe(CLI_NOTICE_TEXT)
            ->and($notice->due())->toBeNull();

        $this->now += CliReleaseNotice::IntervalSeconds - 1;
        expect($notice->due())->toBeNull();

        $this->now += 1;
        expect($notice->due())->toBe(CLI_NOTICE_TEXT);
    });

    it('keeps the throttle across runs in a private file under ORBIT_HOME', function (): void {
        $first = cli_release_notice($this->orbitHome, clock: fn (): int => $this->now);
        $first->observe('0.4681.0');
        $first->due();

        $second = cli_release_notice($this->orbitHome, clock: fn (): int => $this->now + 3600);
        $second->observe('0.4690.0');

        expect($second->due())->toBeNull()
            ->and(json_decode((string) file_get_contents($this->orbitHome.'/self-update-notice.json'), true))->toBe(['notified_at' => $this->now, 'version' => '0.4681.0'])
            ->and(fileperms($this->orbitHome.'/self-update-notice.json') & 0777)->toBe(0600);
    });

    it('says nothing when this orbit is current, newer, or not a release', function (string $current, ?string $desired): void {
        $notice = cli_release_notice($this->orbitHome, $current);
        $notice->observe($desired);

        expect($notice->due())->toBeNull()
            ->and(file_exists($this->orbitHome.'/self-update-notice.json'))->toBeFalse();
    })->with([
        'same release' => ['0.4681.0', '0.4681.0'],
        'newer than the Gateway' => ['0.4700.0', '0.4681.0'],
        'source checkout' => ['cli-v0.4600.0', '0.4681.0'],
        'pull-request build' => ['cli-v0.4600.0-3-g1a2b3c4', '0.4681.0'],
        'no header' => ['0.4600.0', null],
        'malformed header' => ['0.4600.0', '0.4681'],
    ]);

    it('says nothing when it cannot record the notice, so it never repeats on every command', function (): void {
        $notice = new CliReleaseNotice('/proc/orbit-unwritable/'.CliReleaseNotice::StateFile, '0.4600.0');
        $notice->observe('0.4681.0');

        expect($notice->due())->toBeNull();
    });
});

describe('the newer-release notice on Gateway commands', function (): void {
    beforeEach(function (): void {
        config()->set('orbit.home', $this->orbitHome);
        config()->set('app.version', '0.4600.0');
        app()->forgetInstance(CliReleaseNotice::class);
        app()->forgetInstance(GatewayConfigRepository::class);
        app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'default', url: 'https://10.44.0.1', caPath: '/home/orbit/.orbit/ca/root.pem'));
    });

    it('prints the notice on standard error after the command, then waits a day', function (): void {
        $mock = MockClient::global([ShowGatewayStatusRequest::class => gateway_status_with_cli_version('0.4681.0')]);

        $first = run_with_captured_streams('gateway:status');

        expect($first->stderr())->toBe(CLI_NOTICE_TEXT."\n")
            ->and($first->stdout())->not->toContain('self-update')
            ->and($mock->getLastPendingRequest()?->headers()->get('X-Orbit-Client-Version'))->toBe('0.4600.0');

        app()->forgetInstance(CliReleaseNotice::class);
        MockClient::destroyGlobal();
        MockClient::global([ShowGatewayStatusRequest::class => gateway_status_with_cli_version('0.4681.0')]);

        expect(run_with_captured_streams('gateway:status')->stderr())->toBe('');
    });

    it('never prints the notice in JSON mode', function (): void {
        MockClient::global([ShowGatewayStatusRequest::class => gateway_status_with_cli_version('0.4681.0')]);

        $output = run_with_captured_streams('gateway:status --json');

        expect($output->stderr())->toBe('')
            ->and(json_decode($output->stdout(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('ok')
            ->and(file_exists($this->orbitHome.'/self-update-notice.json'))->toBeFalse();
    });

    it('prints nothing when the Gateway names no newer release', function (): void {
        MockClient::global([ShowGatewayStatusRequest::class => gateway_status_with_cli_version(null)]);

        expect(run_with_captured_streams('gateway:status')->stderr())->toBe('');
    });
});
