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
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Gateway\ShowDesiredFleetStateRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\Support\FakeSelfUpdateHost;

/** @return array<string, mixed> */
function run_self_update(array $arguments = [], int $exitCode = 0): array
{
    expect(Artisan::call('self-update', [...$arguments, '--json' => true]))->toBe($exitCode);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    putenv('COLUMNS=120');
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
    it('replaces the binary with the verified release and leaves no candidate', function (): void {
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
                'after' => ['version' => '0.4681.0', 'sha256' => '79d7424eeafdc38c773b8f0a7d1b67b21e38e6abb6b458f3e42b89e2cde7a0ce'],
                'error' => null,
            ])
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(self_update_release_bytes('orbit-0.4681.0-linux-x86_64'))
            ->and(fileperms($this->machine.'/bin/orbit') & 0777)->toBe(0755)
            ->and(file_exists($this->machine.'/bin/orbit'.BinaryInstaller::CandidateSuffix))->toBeFalse()
            ->and(self_update_commands($this))->toBe(['curl SHA256SUMS', 'curl orbit-0.4681.0-linux-x86_64', 'version'])
            ->and($this->host->exitedWith)->toBe(0);

        $download = $this->processes[1];
        expect($download)->toContain('--fail', '--proto', '=https')
            ->and($download[array_search('--output', $download, true) + 1])->toBe($this->machine.'/bin/orbit'.BinaryInstaller::CandidateSuffix);
    });

    it('changes nothing when the download fails its checksum', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, ['orbit-0.4681.0-linux-x86_64' => "tampered\n"]);

        $result = run_self_update(exitCode: 1);

        expect($result['outcome'])->toBe('failed')
            ->and(self_update_step($result, 'cli')['outcome'])->toBe('failed')
            ->and(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.checksum_mismatch')
            ->and(self_update_step($result, 'cli')['after'])->toBe(self_update_step($result, 'cli')['before'])
            ->and(self_update_step($result, 'agent'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'not_managed_node'])
            ->and($this->host->exitedWith)->toBeNull()
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(file_exists($this->machine.'/bin/orbit'.BinaryInstaller::CandidateSuffix))->toBeFalse()
            ->and(self_update_commands($this))->toBe(['curl SHA256SUMS', 'curl orbit-0.4681.0-linux-x86_64']);
    });

    it('downloads nothing when the release SHA256SUMS does not confirm the Gateway checksum', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, ['SHA256SUMS' => str_replace('79d7424e', '00000000', self_update_release_bytes('SHA256SUMS'))]);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.checksum_mismatch')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(self_update_commands($this))->toBe(['curl SHA256SUMS']);
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

        expect(self_update_step(run_self_update(['--allow-downgrade' => true]), 'cli')['outcome'])->toBe('updated');
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

        expect(self_update_step(run_self_update(), 'cli'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'platform_unsupported']);
    });

    it('reports a pending release with its own outcome, changes nothing, and still updates the agent', function (): void {
        $this->host->managedNode = true;
        $this->host->root = true;
        file_put_contents($this->machine.'/bin/orbit-agent', "orbit-agent 0.2.0\n");
        $body = json_decode(gateway_fixture('gateway/self-update/pending')['body'], true, flags: JSON_THROW_ON_ERROR);
        $body['data']['agent']['assets'][0]['sha256'] = hash('sha256', SELF_UPDATE_AGENT_BYTES);
        MockClient::global([ShowDesiredFleetStateRequest::class => MockResponse::make($body)]);
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
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(self_update_commands($this))->toBe(['curl orbit-agent-0.3.0-linux-x86_64', 'systemctl restart orbit-agent.service']);
    });

    it('refuses to replace a binary in a directory it cannot write', function (): void {
        chmod($this->machine.'/bin', 0555);
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this);

        try {
            $result = run_self_update(exitCode: 1);
        } finally {
            chmod($this->machine.'/bin', 0755);
        }

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.not_writable')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY);
    })->skip(fn (): bool => getmyuid() === 0, 'root may write to any directory');
});

describe('an interrupted self-update', function (): void {
    it('keeps the working binary when the candidate does not run', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, reported: 'Illegal instruction');

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.candidate_invalid')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(is_executable($this->machine.'/bin/orbit'))->toBeTrue()
            ->and(file_exists($this->machine.'/bin/orbit'.BinaryInstaller::CandidateSuffix))->toBeFalse();
    });

    it('keeps the working binary when the download stops part way', function (): void {
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, onDownload: static function (string $name, string $output) {
            if ($name !== 'orbit-0.4681.0-linux-x86_64') {
                return null;
            }

            file_put_contents($output, substr(self_update_release_bytes($name), 0, 10));

            return Process::result(exitCode: 18, errorOutput: 'curl: (18) transfer closed with outstanding read data remaining');
        });

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'cli')['error']['code'])->toBe('self_update.download_failed')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(is_executable($this->machine.'/bin/orbit'))->toBeTrue()
            ->and(file_exists($this->machine.'/bin/orbit'.BinaryInstaller::CandidateSuffix))->toBeFalse();
    });

    it('removes the candidate a killed run left behind and finishes the update', function (): void {
        file_put_contents($this->machine.'/bin/orbit'.BinaryInstaller::CandidateSuffix, 'half a binary');
        MockClient::global(gateway_fixture_mock('gateway/self-update/available'));
        fake_self_update_processes($this, onDownload: function (string $name, string $output): null {
            if ($name === 'orbit-0.4681.0-linux-x86_64') {
                // curl writes into a fresh candidate, never into what the killed run left.
                expect(file_exists($output))->toBeFalse();
            }

            return null;
        });

        expect(self_update_step(run_self_update(), 'cli')['outcome'])->toBe('updated')
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(self_update_release_bytes('orbit-0.4681.0-linux-x86_64'))
            ->and(file_exists($this->machine.'/bin/orbit'.BinaryInstaller::CandidateSuffix))->toBeFalse();
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

    it('replaces the agent that differs from the pin and restarts it', function (): void {
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
            ->and(fileperms($this->machine.'/bin/orbit-agent') & 0777)->toBe(0755)
            ->and(self_update_commands($this))->toBe(['curl orbit-agent-0.3.0-linux-x86_64', 'systemctl restart orbit-agent.service']);
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
            ->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe(SELF_UPDATE_AGENT_BYTES);
    });

    it('changes nothing and restarts nothing when the agent download fails its checksum', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this, ['orbit-agent-0.3.0-linux-x86_64' => "tampered\n"]);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'agent')['error']['code'])->toBe('agent.checksum_mismatch')
            ->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe("orbit-agent 0.2.0\n")
            ->and(file_exists($this->machine.'/bin/orbit-agent'.BinaryInstaller::CandidateSuffix))->toBeFalse()
            ->and(self_update_commands($this))->toBe(['curl orbit-agent-0.3.0-linux-x86_64']);
    });

    it('updates the agent before the CLI and leaves the CLI alone when the agent fails', function (): void {
        config()->set('app.version', '0.4600.0');
        file_put_contents($this->machine.'/bin/orbit', SELF_UPDATE_OLD_BINARY);
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this, ['orbit-agent-0.3.0-linux-x86_64' => "tampered\n"]);

        $result = run_self_update(exitCode: 1);

        expect(array_column($result['steps'], 'step'))->toBe(['agent', 'cli'])
            ->and(self_update_step($result, 'cli'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'previous_step_failed'])
            ->and(file_get_contents($this->machine.'/bin/orbit'))->toBe(SELF_UPDATE_OLD_BINARY)
            ->and(self_update_commands($this))->toBe(['curl orbit-agent-0.3.0-linux-x86_64']);
    });

    it('reports a failed restart after the agent is in place', function (): void {
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this, restartExit: 1);

        $result = run_self_update(exitCode: 1);

        expect(self_update_step($result, 'agent'))->toMatchArray(['outcome' => 'failed', 'error' => [
            'code' => 'agent.restart_failed',
            'message' => 'The new orbit-agent is in place, but systemctl could not restart orbit-agent.service.',
        ]])->and(file_get_contents($this->machine.'/bin/orbit-agent'))->toBe(SELF_UPDATE_AGENT_BYTES);
    });

    it('leaves the agent to root', function (): void {
        $this->host->root = false;
        MockClient::global([ShowDesiredFleetStateRequest::class => self_update_state_with_agent()]);
        fake_self_update_processes($this);

        expect(self_update_step(run_self_update(), 'agent'))->toMatchArray(['outcome' => 'skipped', 'reason' => 'root_required'])
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
