<?php

declare(strict_types=1);

use App\Models\GatewayRelease;
use Tests\Support\GatewayReleasePipeline;

pest()->group('subprocess');

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

describe('gateway:release:run', function (): void {
    it('runs a queued deploy in the unit and records each step on the same record', function (): void {
        $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Requested');
        $record = $this->pipeline->recorder()->queue('deploy', substr($sha, 0, 9), null);
        $this->pipeline->bind();

        $this->artisan('gateway:release:run', ['record' => (string) $record->id])
            ->expectsOutputToContain('"outcome":"verified"')
            ->assertExitCode(0);

        expect(GatewayRelease::query()->sole())
            ->id->toBe($record->id)
            ->trigger->toBe('deploy')
            ->outcome->toBe('verified')
            ->sha->toBe($sha)
            ->release_id->toBe(substr($sha, 0, 12))
            ->requested->toBe(substr($sha, 0, 9))
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12));
    });

    it('ends a queued record when another release step holds the lock', function (): void {
        $this->pipeline->adopt();
        $sha = $this->pipeline->fixture->commit('Requested');
        $record = $this->pipeline->recorder()->queue('deploy', $sha, $sha);
        $this->pipeline->bind();

        $this->pipeline->lock()->run(function () use ($record): void {
            $this->artisan('gateway:release:run', ['record' => (string) $record->id])
                ->expectsOutputToContain('"error_code":"gateway.release_in_progress"')
                ->assertExitCode(1);
        });

        expect($record->refresh())
            ->outcome->toBe('failed')
            ->retryable->toBeTrue()
            ->error_code->toBe('gateway.release_in_progress')
            ->and($record->phases)->toBe(['lock' => ['outcome' => 'failed', 'error_code' => 'gateway.release_in_progress']]);
    });

    it('runs a queued rollback to a retained release', function (): void {
        $first = $this->pipeline->adopt();
        $second = $this->pipeline->fixture->commit('Second');
        $this->pipeline->deployer()->execute($second);
        $record = $this->pipeline->recorder()->queue('rollback', substr($first, 0, 12), $first);
        $this->pipeline->bind();

        $this->artisan('gateway:release:run', ['record' => (string) $record->id])->assertExitCode(0);

        expect($record->refresh())
            ->trigger->toBe('rollback')
            ->outcome->toBe('verified')
            ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($first, 0, 12));
    });

    it('refuses a record that is not queued', function (): void {
        $record = $this->pipeline->recorder()->queue('deploy', 'abcdef1', null);
        $record->forceFill(['outcome' => 'verified'])->save();
        $this->pipeline->bind();

        $this->artisan('gateway:release:run', ['record' => (string) $record->id])
            ->expectsOutputToContain('"error_code":"gateway.release_not_queued"')
            ->assertExitCode(2);
        $this->artisan('gateway:release:run', ['record' => '999'])
            ->expectsOutputToContain('"error_code":"gateway.release_not_found"')
            ->assertExitCode(2);
    });

    it('ends a record left running by a process that died when the next release starts', function (): void {
        $this->pipeline->adopt();
        $stale = GatewayRelease::query()->create(['requested' => 'abcdef1', 'trigger' => 'deploy', 'outcome' => 'running', 'phases' => [], 'duration_ms' => 0]);
        $sha = $this->pipeline->fixture->commit('Next');

        $this->pipeline->deployer()->execute($sha);

        expect($stale->refresh())
            ->outcome->toBe('interrupted')
            ->error_code->toBe('gateway.release_interrupted');
    });

    it('records a refused commit on its record without marking it failed for automatic releases', function (): void {
        $this->pipeline->adopt();

        expect(release_failure(fn () => $this->pipeline->deployer()->execute('abcdef1234567'))->errorCode)->toBe('gateway.release_commit_unknown')
            ->and(GatewayRelease::query()->sole())
            ->outcome->toBe('failed')
            ->retryable->toBeTrue()
            ->sha->toBeNull()
            ->and(GatewayRelease::query()->sole()->phases)->toBe(['prepare' => ['outcome' => 'failed', 'error_code' => 'gateway.release_commit_unknown']])
            ->and($this->pipeline->alerts)->toBe([]);
    });
});
