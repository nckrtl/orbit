<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Models\GatewayRelease;
use App\Models\Node;
use Tests\Support\GatewayReleasePipeline;

/*
 * These tests take every action, the recorder, the promoter, and the supersession check from the
 * service provider. Only leaf services are faked, so a dependency the provider does not wire fails here.
 */
beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
    $this->pipeline->adopt();
    $this->pipeline->bindLeaves();
    $this->pipeline->automation()->enable();
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

describe('Gateway release wiring', function (): void {
    it('records a superseded manual deploy through the container-built deploy action, and the container-built runner pauses with one alert', function (): void {
        $older = $this->pipeline->fixture->commit('Older');
        $newest = $this->pipeline->green = $this->pipeline->fixture->commit('Newest');

        app(DeployGatewayReleaseAction::class)->execute($older);
        $tick = app(RunAutomaticGatewayReleaseAction::class)->execute();

        expect(GatewayRelease::query()->latest('id')->first()->phases['newest_green'])->toBe(['outcome' => 'superseded', 'sha' => $newest])
            ->and($tick['result'])->toBe('paused')
            ->and($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('manual_deploy')
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->kind)->toBe(ReleaseAlertKind::ReleasePaused);
    });

    it('records a manual deploy that the API queued and gateway:release:run ran', function (): void {
        $caller = $this->markAsGateway(Node::query()->create([
            'name' => 'wiring-gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.40',
            'wireguard_ip' => '10.44.0.40',
        ]));
        $older = $this->pipeline->fixture->commit('Older');
        $newest = $this->pipeline->green = $this->pipeline->fixture->commit('Newest');

        $id = $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->postJson('/api/v1/gateway/releases', ['commit' => $older])
            ->assertStatus(202)
            ->json('data.id');
        $this->artisan('gateway:release:run', ['record' => (string) $id])->assertExitCode(0);

        expect(GatewayRelease::query()->findOrFail($id))
            ->outcome->toBe('verified')
            ->and(GatewayRelease::query()->findOrFail($id)->phases['newest_green'])->toBe(['outcome' => 'superseded', 'sha' => $newest]);
    });

    it('fails closed when GitHub cannot say whether a newer green commit exists', function (): void {
        $older = $this->pipeline->fixture->commit('Older');
        $this->pipeline->githubFailure = new GitHubApiException('GitHub could not be reached.');

        app(DeployGatewayReleaseAction::class)->execute($older);
        $this->pipeline->githubFailure = null;
        $this->pipeline->green = $this->pipeline->fixture->commit('Newest');

        expect(GatewayRelease::query()->latest('id')->first()->phases['newest_green']['outcome'] ?? null)->toBe('unknown')
            ->and(app(RunAutomaticGatewayReleaseAction::class)->execute()['result'])->toBe('paused')
            ->and($this->pipeline->automation()->pause()['reason'] ?? null)->toBe('manual_deploy')
            ->and($this->pipeline->alerts)->toHaveCount(1);
    });

    it('runs the unit entry points from the container', function (): void {
        $sha = $this->pipeline->green = $this->pipeline->fixture->commit('Green');
        $dead = GatewayRelease::query()->create(['requested' => 'abcdef1', 'trigger' => 'deploy', 'outcome' => 'running', 'phases' => [], 'duration_ms' => 0]);

        $this->artisan('gateway:release:settle', ['record' => (string) $dead->id])
            ->expectsOutputToContain('"settled":['.$dead->id.']')
            ->assertExitCode(0);
        $this->artisan('gateway:release:settle')->assertExitCode(0);
        $this->artisan('gateway:release:auto')
            ->expectsOutputToContain('"result":"released"')
            ->assertExitCode(0);

        expect($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and(app(GatewayReleaseRecorder::class))->toBeInstanceOf(GatewayReleaseRecorder::class);
    });

    it('spends the retry budget on attempts that died before prepare named the commit', function (): void {
        $sha = str_repeat('e', 40);

        foreach ([true, true, false] as $retryable) {
            $dead = GatewayRelease::query()->create(['requested' => $sha, 'trigger' => 'auto', 'outcome' => 'running', 'phases' => [], 'duration_ms' => 0]);
            app(RunAutomaticGatewayReleaseAction::class)->execute();

            expect($dead->refresh())
                ->outcome->toBe('interrupted')
                ->sha->toBeNull()
                ->retryable->toBe($retryable);
        }

        expect(GatewayRelease::failedShas())->toBe([$sha])
            ->and($this->pipeline->alerts)->toHaveCount(1)
            ->and($this->pipeline->alerts[0]->subject->sha)->toBe($sha);
    });
});
