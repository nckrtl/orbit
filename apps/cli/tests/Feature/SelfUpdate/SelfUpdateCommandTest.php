<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\SelfUpdate\BinaryInstaller;
use App\Services\SelfUpdate\RunningBinaryKind;
use App\Services\SelfUpdate\SelfUpdateHost;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Gateway\ShowDesiredFleetStateRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\Support\FakeSelfUpdateHost;

const SELF_UPDATE_RELEASE_SHA = '79d7424eeafdc38c773b8f0a7d1b67b21e38e6abb6b458f3e42b89e2cde7a0ce';

/** @return array<string, mixed> */
function run_self_update(array $arguments = [], int $exitCode = 0): array
{
    expect(Artisan::call('self-update', [...$arguments, '--json' => true]))->toBe($exitCode);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The recorded desired state with one change made to its data.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>  $change
 */
function self_update_state(Closure $change, string $fixture = 'gateway/self-update/available'): MockResponse
{
    $body = json_decode(gateway_fixture($fixture)['body'], true, flags: JSON_THROW_ON_ERROR);
    $body['data'] = $change($body['data']);

    return MockResponse::make($body);
}

/** @return list<string> The names in the machine's bin directory, with link targets. */
function self_update_bin(object $test): array
{
    $entries = [];

    foreach (scandir($test->machine.'/bin') ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $test->machine.'/bin/'.$name;
        $entries[] = is_link($path) ? $name.' -> '.readlink($path) : $name;
    }

    sort($entries);

    return $entries;
}

beforeEach(function (): void {
    putenv('COLUMNS=120');
    Sleep::fake();
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-self-update-'.Str::uuid();
    $this->machine = sys_get_temp_dir().'/orbit-self-update-machine-'.Str::uuid();
    mkdir($this->machine.'/bin', 0755, true);
    file_put_contents($this->machine.'/bin/orbit', SELF_UPDATE_OLD_BINARY);
    chmod($this->machine.'/bin/orbit', 0755);
    config()->set('orbit.home', $this->orbitHome);
    config()->set('app.version', '0.4600.0');
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'default', url: 'https://10.44.0.1', caPath: '/root/.orbit/ca/root.pem'));
    $this->host = new FakeSelfUpdateHost($this->machine);
    app()->instance(SelfUpdateHost::class, $this->host);
});

afterEach(function (): void {
    putenv('COLUMNS');
    MockClient::destroyGlobal();
    $files = new Filesystem;
    $files->deleteDirectory($this->orbitHome);
    $files->deleteDirectory($this->machine);
});

describe('self-update CLI step', function (): void {
    it('installs the verified release beside the old one and points orbit at it', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        $result = run_self_update();

        expect($result['outcome'])->toBe('updated')
            ->and(self_update_step($result, 'cli'))->toBe([
                'step' => 'cli',
                'outcome' => 'updated',
                'reason' => null,
                'path' => $this->machine.'/bin/orbit',
                'before' => ['version' => '0.4600.0', 'sha256' => hash('sha256', SELF_UPDATE_OLD_BINARY)],
                'after' => ['version' => '0.4681.0', 'sha256' => SELF_UPDATE_RELEASE_SHA],
                'error' => null,
            ])
            ->and(self_update_bin($this))->toBe(['orbit -> orbit-0.4681.0', 'orbit-0.4600.0', 'orbit-0.4681.0'])
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(self_update_release_bytes('orbit-0.4681.0-linux-x86_64'))
            ->and(file_get_contents($this->machine.'/bin/orbit-0.4600.0'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(fileperms($this->machine.'/bin/orbit-0.4681.0') & 0777)->toBe(0755)
            ->and(self_update_commands($this))->toBe(['curl cli-v0.4681.0/SHA256SUMS', 'curl cli-v0.4681.0/orbit-0.4681.0-linux-x86_64', 'version'])
            // The plain binary that ran this process became the link, so the process ends right after the result.
            ->and($this->host->exitedWith)->toBe(0);
    });

    it('downloads with a hardened curl, at most the release size, into a fresh candidate', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        run_self_update();

        [$sums, $binary] = $this->processes;
        $candidate = $binary[array_search('--output', $binary, true) + 1];

        expect($sums[1])->toBe('--disable')
            ->and($binary[1])->toBe('--disable')
            ->and($binary)->toContain('--fail', '--proto', '=https', '--proto-redir', '--max-redirs', '5', '--max-filesize', '268435456')
            ->and($sums[array_search('--max-filesize', $sums, true) + 1])->toBe('65536')
            ->and(dirname((string) $sums[array_search('--output', $sums, true) + 1]))->not->toBe(sys_get_temp_dir())
            ->and(dirname((string) $candidate))->toBe($this->machine.'/bin')
            ->and(basename((string) $candidate))->toMatch('/\A\.orbit-0\.4681\.0\.orbit-candidate-[0-9a-f]{12}\z/');
    });

    it('updates the managed layout by switching the link and keeps the running release', function (): void {
        rename($this->machine.'/bin/orbit', $this->machine.'/bin/orbit-0.4600.0');
        symlink('orbit-0.4600.0', $this->machine.'/bin/orbit');
        file_put_contents($this->machine.'/bin/orbit-0.4500.0', "older\n");
        $this->host->running = $this->machine.'/bin/orbit-0.4600.0';
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        $result = run_self_update();

        expect(self_update_step($result, 'cli'))->toMatchArray(['outcome' => 'updated', 'path' => $this->machine.'/bin/orbit'])
            ->and(self_update_bin($this))->toBe(['orbit -> orbit-0.4681.0', 'orbit-0.4600.0', 'orbit-0.4681.0'])
            ->and(file_get_contents($this->machine.'/bin/orbit-0.4600.0'))->toBe(SELF_UPDATE_OLD_BINARY)
            // The running file is untouched, so the process finishes normally.
            ->and($this->host->exitedWith)->toBeNull();
    });

    it('refuses an asset the Gateway names outside the Orbit release, and downloads nothing', function (Closure $change): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state($change)]);
        fake_self_update_processes($this);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.release_mismatch')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and($this->processes)->toBe([]);
    })->with([
        'another host' => [static function (array $data): array {
            $data['cli']['assets'][0]['url'] = 'https://evil.example/orbit-0.4681.0-linux-x86_64';

            return $data;
        }],
        'another repository' => [static function (array $data): array {
            $data['cli']['assets'][0]['url'] = 'https://github.com/evil/orbit/releases/download/cli-v0.4681.0/orbit-0.4681.0-linux-x86_64';

            return $data;
        }],
        'another asset name' => [static function (array $data): array {
            $data['cli']['assets'][0]['name'] = 'orbit-0.4681.0-macos-arm64';

            return $data;
        }],
    ]);

    it('reads the checksum from the release SHA256SUMS it builds itself, not the one the Gateway names', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state(static function (array $data): array {
            $data['cli']['checksums_url'] = 'https://evil.example/SHA256SUMS';

            return $data;
        })]);
        fake_self_update_processes($this);

        expect(self_update_step(run_self_update(), 'cli')['outcome'])->toBe('updated')
            ->and(self_update_commands($this)[0])->toBe('curl cli-v0.4681.0/SHA256SUMS')
            ->and(end($this->processes[0]))->toBe('https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/SHA256SUMS');
    });

    it('changes nothing when the Gateway checksum and the release SHA256SUMS disagree', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state(static function (array $data): array {
            $data['cli']['assets'][0]['sha256'] = str_repeat('0', 64);

            return $data;
        })]);
        fake_self_update_processes($this);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.checksum_mismatch')
            ->and(self_update_commands($this))->toBe(['curl cli-v0.4681.0/SHA256SUMS'])
            ->and(self_update_bin($this))->toBe(['orbit']);
    });

    it('changes nothing when the download fails its checksum', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, ['cli-v0.4681.0/orbit-0.4681.0-linux-x86_64' => "tampered\n"]);

        $result = run_self_update(exitCode: 1);

        expect($result['outcome'])->toBe('failed')
            ->and(self_update_step($result, 'cli')['outcome'])->toBe('failed')
            ->and(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.checksum_mismatch')
            ->and(self_update_step($result, 'cli')['after'])->toBe(self_update_step($result, 'cli')['before'])
            ->and(self_update_step($result, 'agent'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'not_managed_node'])
            ->and(self_update_bin($this))->toBe(['orbit'])
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and($this->host->exitedWith)->toBeNull();
    });

    it('downloads nothing when the release SHA256SUMS does not confirm the Gateway checksum', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, ['cli-v0.4681.0/SHA256SUMS' => str_replace('79d7424e', '00000000', self_update_release_bytes('SHA256SUMS'))]);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.checksum_mismatch')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(self_update_commands($this))->toBe(['curl cli-v0.4681.0/SHA256SUMS']);
    });

    it('refuses a downgrade unless it is allowed', function (): void {
        config()->set('app.version', '0.4700.0');
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        $refused = run_self_update(exitCode: 1);

        expect(self_update_step($refused, 'cli')['error']['code'])->toBe('self_update.downgrade_refused')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and($this->processes)->toBe([]);

        MockClient::destroyGlobal();
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        $allowed = run_self_update(['--allow-downgrade' => true]);

        expect(self_update_step($allowed, 'cli'))->toMatchArray(['outcome' => 'updated'])
            ->and(self_update_step($allowed, 'cli')['before']['version'])->toBe('0.4700.0')
            ->and(self_update_step($allowed, 'cli')['after']['version'])->toBe('0.4681.0')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(self_update_release_bytes('orbit-0.4681.0-linux-x86_64'));
    });

    it('treats a build that is not a release like a downgrade', function (): void {
        config()->set('app.version', 'cli-v0.4681.0-3-g1a2b3c4');
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        expect(self_update_step(run_self_update(exitCode: 1), 'cli')['error']['code'])->toBe('self_update.version_unknown');

        MockClient::destroyGlobal();
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));

        expect(self_update_step(run_self_update(['--allow-downgrade' => true]), 'cli')['outcome'])->toBe('updated')
            ->and(self_update_bin($this))->toBe(['orbit -> orbit-0.4681.0', 'orbit-0.4681.0']);
    });

    it('reports unchanged when the binary already is the release', function (): void {
        config()->set('app.version', '0.4681.0');
        file_put_contents($this->machine.'/bin/orbit', self_update_release_bytes('orbit-0.4681.0-linux-x86_64'));
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        $result = run_self_update();

        expect($result['outcome'])->toBe('unchanged')
            ->and(self_update_step($result, 'cli')['outcome'])->toBe('unchanged')
            ->and($this->processes)->toBe([]);
    });

    it('repairs a damaged binary of the same version', function (): void {
        config()->set('app.version', '0.4681.0');
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        expect(self_update_step(run_self_update(), 'cli')['outcome'])->toBe('updated')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(self_update_release_bytes('orbit-0.4681.0-linux-x86_64'));
    });

    it('leaves a source checkout and a PHAR alone', function (RunningBinaryKind $kind, string $reason): void {
        $this->host->kind = $kind;
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        $result = run_self_update();

        expect($result['outcome'])->toBe('unchanged')
            ->and(self_update_step($result, 'cli')['outcome'])->toBe('skipped')
            ->and(self_update_step($result, 'cli')['reason'])->toBe($reason)
            ->and($this->processes)->toBe([]);
    })->with([
        'source checkout' => [RunningBinaryKind::Source, 'source_checkout'],
        'PHAR run by php' => [RunningBinaryKind::Phar, 'not_a_release_binary'],
    ]);

    it('skips a platform without a release binary', function (): void {
        $this->host->platform = null;
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        $result = run_self_update();

        expect(self_update_step($result, 'cli'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'platform_unsupported'])
            ->and($result['outcome'])->toBe('unchanged');
    });

    it('reports a pending release with its own outcome, changes nothing, and still updates the agent', function (): void {
        $this->host->managedNode = true;
        $this->host->root = true;
        file_put_contents($this->machine.'/bin/orbit-agent', "orbit-agent 0.2.0\n");
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state(static function (array $data): array {
            $data['agent']['assets'][0]['sha256'] = hash('sha256', SELF_UPDATE_AGENT_BYTES);

            return $data;
        }, 'gateway/self-update/pending')]);
        fake_self_update_processes($this);

        $result = run_self_update();

        expect($result['outcome'])->toBe('pending')
            ->and(self_update_step($result, 'cli'))->toBe([
                'step' => 'cli',
                'outcome' => 'pending',
                'reason' => 'release_missing',
                'path' => $this->machine.'/bin/orbit',
                'before' => ['version' => '0.4600.0', 'sha256' => hash('sha256', SELF_UPDATE_OLD_BINARY)],
                'after' => ['version' => '0.4681.0', 'sha256' => null],
                'error' => null,
            ])
            ->and(self_update_step($result, 'agent')['outcome'])->toBe('updated')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY);
    });

    it('reports incomplete, not up to date, when the release is unavailable', function (string $reason): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state(
            static fn (array $data): array => [...$data, 'cli' => ['status' => 'unavailable', 'reason' => $reason, 'version' => null, 'tag' => null, 'checksums_url' => null, 'assets' => []]],
        )]);
        fake_self_update_processes($this);

        $result = run_self_update();

        expect($result['outcome'])->toBe('incomplete')
            ->and(self_update_step($result, 'cli'))->toMatchArray(['outcome' => 'skipped', 'reason' => $reason])
            ->and($this->processes)->toBe([]);

        expect(Artisan::call('self-update'))->toBe(0)
            ->and(Artisan::output())->toContain('Update incomplete.')->not->toContain('up to date.');
    })->with(['github_unavailable', 'release_incomplete', 'release_mismatch', 'history_unavailable', 'gateway_commit_unknown']);

    it('refuses to replace a binary in a directory it cannot write', function (): void {
        $this->host->running = null;
        mkdir($this->machine.'/locked', 0755);
        rename($this->machine.'/bin/orbit', $this->machine.'/locked/orbit');
        $this->host->running = $this->machine.'/locked/orbit';
        chmod($this->machine.'/locked', 0555);
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        try {
            $result = run_self_update(exitCode: 1);
        } finally {
            chmod($this->machine.'/locked', 0755);
        }

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.not_writable')
            ->and(file_get_contents($this->machine.'/locked/orbit'))->toBe(SELF_UPDATE_OLD_BINARY);
    })->skip(fn (): bool => getmyuid() === 0, 'root may write to any directory');

    it('waits for another self-update and refuses when it does not finish', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);
        $held = fopen($this->machine.'/self-update.lock', 'c');
        flock($held, LOCK_EX);

        try {
            $result = run_self_update(exitCode: 1);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        expect($result)->toBe(['error' => [
            'code' => 'self_update.busy',
            'message' => 'Another orbit self-update, or an agent converge, is running on this machine.',
            'request_id' => null,
        ]])->and($this->processes)->toBe([]);
        Sleep::assertSleptTimes(120);
    });
});

describe('an interrupted self-update', function (): void {
    it('keeps the working binary when the candidate does not run', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, reported: 'Illegal instruction');

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.candidate_invalid')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(is_executable($this->machine.'/bin/orbit'))->toBeTrue()
            ->and(self_update_bin($this))->toBe(['orbit']);
    });

    it('keeps the working binary when the download stops part way', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, onDownload: static function (string $key, string $output) {
            if ($key !== 'cli-v0.4681.0/orbit-0.4681.0-linux-x86_64') {
                return null;
            }

            file_put_contents($output, substr(self_update_release_bytes('orbit-0.4681.0-linux-x86_64'), 0, 10));

            return Process::result(exitCode: 18, errorOutput: 'curl: (18) transfer closed with outstanding read data remaining');
        });

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.download_failed')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(is_executable($this->machine.'/bin/orbit'))->toBeTrue()
            ->and(self_update_bin($this))->toBe(['orbit']);
    });

    it('removes the candidates a killed run left behind and finishes the update', function (): void {
        file_put_contents($this->machine.'/bin/.orbit-0.4681.0'.BinaryInstaller::CandidateSuffix.'-0123456789ab', 'half a binary');
        file_put_contents($this->machine.'/bin/.orbit-agent'.BinaryInstaller::CandidateSuffix.'-0123456789ab', 'half an agent');
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        expect(self_update_step(run_self_update(), 'cli')['outcome'])->toBe('updated')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(self_update_release_bytes('orbit-0.4681.0-linux-x86_64'))
            ->and(self_update_bin($this))->toBe(['orbit -> orbit-0.4681.0', 'orbit-0.4600.0', 'orbit-0.4681.0']);
    });
});

describe('self-update agent step', function (): void {
    beforeEach(function (): void {
        $this->host->managedNode = true;
        $this->host->root = true;
        config()->set('app.version', '0.4681.0');
        file_put_contents($this->machine.'/bin/orbit', self_update_release_bytes('orbit-0.4681.0-linux-x86_64'));
        file_put_contents($this->machine.'/bin/orbit-agent', "orbit-agent 0.2.0\n");
        chmod($this->machine.'/bin/orbit-agent', 0755);
    });

    it('replaces the agent that differs from the pin, keeps the previous one, and checks that it stays up', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this);

        $result = run_self_update();

        expect($result['outcome'])->toBe('updated')
            ->and(self_update_step($result, 'agent'))->toBe([
                'step' => 'agent',
                'outcome' => 'updated',
                'reason' => null,
                'path' => $this->machine.'/bin/orbit-agent',
                'before' => ['version' => null, 'sha256' => hash('sha256', "orbit-agent 0.2.0\n")],
                'after' => ['version' => '0.3.0', 'sha256' => hash('sha256', SELF_UPDATE_AGENT_BYTES)],
                'error' => null,
            ])
            ->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe(SELF_UPDATE_AGENT_BYTES)
            ->and(file_get_contents($this->machine.'/bin/orbit-agent.orbit-previous'))->toBe("orbit-agent 0.2.0\n")
            ->and(fileperms($this->machine.'/bin/orbit-agent') & 0777)->toBe(0755)
            ->and(self_update_commands($this))->toBe([
                'curl agent-v0.3.0/SHA256SUMS',
                'curl agent-v0.3.0/orbit-agent-0.3.0-linux-x86_64',
                'systemctl restart orbit-agent.service',
                ...array_fill(0, 6, 'systemctl show'),
            ]);
        Sleep::assertSleptTimes(5);
    });

    it('restores the previous agent when the new one does not stay running', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this, restarts: ['0', '0', '1']);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'agent'))->toMatchArray(['outcome' => 'failed', 'error' => [
            'code' => 'agent.unhealthy',
            'message' => 'The new orbit-agent did not stay running. The previous orbit-agent is restored and running.',
        ]])
            ->and(self_update_step($result, 'agent')['after'])->toBe(self_update_step($result, 'agent')['before'])
            ->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe("orbit-agent 0.2.0\n")
            ->and(array_count_values(self_update_commands($this))['systemctl restart orbit-agent.service'])->toBe(2)
            ->and(self_update_step($result, 'cli'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'previous_step_failed']);
    });

    it('restores the previous agent when systemctl cannot restart the new one', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this, restartExit: 1);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'agent')['error'])->toBe([
            'code' => 'agent.restart_failed',
            'message' => 'systemctl could not restart orbit-agent.service. The previous orbit-agent is restored, but systemctl could not restart it.',
        ])->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe("orbit-agent 0.2.0\n");
    });

    it('leaves the agent that matches the pin and does not restart it', function (): void {
        file_put_contents($this->machine.'/bin/orbit-agent', SELF_UPDATE_AGENT_BYTES);
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this);

        $result = run_self_update();

        expect($result['outcome'])->toBe('unchanged')
            ->and(self_update_step($result, 'agent'))->toMatchArray(['outcome' => 'unchanged', 'before' => ['version' => '0.3.0', 'sha256' => hash('sha256', SELF_UPDATE_AGENT_BYTES)]])
            ->and($this->processes)->toBe([]);
    });

    it('installs a missing agent', function (): void {
        unlink($this->machine.'/bin/orbit-agent');
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this);

        expect(self_update_step(run_self_update(), 'agent'))->toMatchArray(['outcome' => 'updated', 'before' => ['version' => null, 'sha256' => null]])
            ->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe(SELF_UPDATE_AGENT_BYTES)
            ->and(file_exists($this->machine.'/bin/orbit-agent.orbit-previous'))->toBeFalse();
    });

    it('changes nothing and restarts nothing when the agent download fails its checksum', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this, ['agent-v0.3.0/orbit-agent-0.3.0-linux-x86_64' => "tampered\n"]);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'agent')['error']['code'])->toBe('agent.checksum_mismatch')
            ->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe("orbit-agent 0.2.0\n")
            ->and(self_update_commands($this))->toBe(['curl agent-v0.3.0/SHA256SUMS', 'curl agent-v0.3.0/orbit-agent-0.3.0-linux-x86_64']);
    });

    it('changes nothing when the agent release SHA256SUMS does not confirm the pin', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this, ['agent-v0.3.0/SHA256SUMS' => str_repeat('0', 64)."  orbit-agent-0.3.0-linux-x86_64\n"]);

        expect(self_update_step(run_self_update(exitCode: 1), 'agent')['error']['code'])->toBe('agent.checksum_mismatch')
            ->and(self_update_commands($this))->toBe(['curl agent-v0.3.0/SHA256SUMS']);
    });

    it('refuses an agent the Gateway names outside the Orbit release', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state(static function (array $data): array {
            $data['agent']['assets'][0]['url'] = 'https://evil.example/orbit-agent-0.3.0-linux-x86_64';

            return $data;
        })]);
        fake_self_update_processes($this);

        expect(self_update_step(run_self_update(exitCode: 1), 'agent')['error']['code'])->toBe('agent.release_mismatch')
            ->and($this->processes)->toBe([]);
    });

    it('updates the agent before the CLI and leaves the CLI alone when the agent fails', function (): void {
        config()->set('app.version', '0.4600.0');
        file_put_contents($this->machine.'/bin/orbit', SELF_UPDATE_OLD_BINARY);
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this, ['agent-v0.3.0/orbit-agent-0.3.0-linux-x86_64' => "tampered\n"]);

        $result = run_self_update(exitCode: 1);

        expect(array_column($result['steps'], 'step'))->toBe(['agent', 'cli'])
            ->and(self_update_step($result, 'cli'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'previous_step_failed'])
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY);
    });

    it('leaves the agent to root and reports the run incomplete', function (): void {
        $this->host->root = false;
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this);

        $result = run_self_update();

        expect(self_update_step($result, 'agent'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'root_required'])
            ->and($result['outcome'])->toBe('incomplete')
            ->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe("orbit-agent 0.2.0\n")
            ->and($this->processes)->toBe([]);
    });

    it('touches no agent on a machine that is not a managed Node', function (): void {
        $this->host->managedNode = false;
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this);

        expect(self_update_step(run_self_update(), 'agent'))->toBe([
            'step' => 'agent', 'outcome' => 'skipped', 'reason' => 'not_managed_node', 'path' => null, 'before' => null, 'after' => null, 'error' => null,
        ])->and($this->processes)->toBe([]);
    });

    it('labels an agent that already matches as up to date, not as updated', function (): void {
        file_put_contents($this->machine.'/bin/orbit-agent', SELF_UPDATE_AGENT_BYTES);
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this);

        expect(Artisan::call('self-update'))->toBe(0)
            ->and(Artisan::output())->toContain('Agent up to date')->not->toContain('Updated agent');
    });
});

describe('self-update failures before any step', function (): void {
    it('returns the error envelope when the Gateway cannot be reached', function (): void {
        MockClient::global([
            ShowDesiredFleetStateRequest::class => static fn (PendingRequest $request) => throw new FatalRequestException(new RuntimeException('Connection refused.'), $request),
        ]);
        fake_self_update_processes($this);

        expect(run_self_update(exitCode: 1))->toBe(['error' => ['code' => 'gateway.unreachable', 'message' => 'Could not reach the gateway.', 'request_id' => null]])
            ->and($this->processes)->toBe([]);
    });

    it('returns the error envelope when no profile is active', function (): void {
        config()->set('orbit.home', $this->orbitHome.'-empty');
        app()->forgetInstance(GatewayConfigRepository::class);
        fake_self_update_processes($this);

        expect(run_self_update(exitCode: 1)['error']['code'])->toBe('gateway.profile_missing');
    });

    it('sends the client version with the request', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('gateway/self-update/pending'));
        fake_self_update_processes($this);

        run_self_update();

        expect($mock->getLastPendingRequest()?->headers()->get('X-Orbit-Client-Version'))->toBe('0.4600.0');
    });
});
