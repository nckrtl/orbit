<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Models\GatewayRelease;

/** @param array<string, mixed> $attributes */
function retry_record(array $attributes): GatewayRelease
{
    return GatewayRelease::query()->create([
        'release_id' => str_repeat('a', 12),
        'sha' => str_repeat('a', 40),
        'trigger' => 'auto',
        'outcome' => 'failed',
        'phases' => [],
        'duration_ms' => 0,
        ...$attributes,
    ]);
}

describe(GatewayReleaseRetry::class, function (): void {
    it('counts only earlier finished failed attempts of a deploy, never the asking record, a rollback, or a verified record', function (): void {
        $sha = str_repeat('a', 40);
        $current = retry_record(['outcome' => 'running']);
        retry_record(['trigger' => 'rollback', 'outcome' => 'failed']);
        retry_record(['trigger' => 'rollback', 'outcome' => 'verified']);
        retry_record(['trigger' => 'deploy', 'outcome' => 'verified']);
        retry_record(['outcome' => 'queued']);
        $retry = new GatewayReleaseRetry;

        expect($retry->retryable('gateway.release_verify_failed', $sha, $current->id))->toBeTrue();

        retry_record(['outcome' => 'switched_back']);
        expect($retry->retryable('gateway.release_verify_failed', $sha, $current->id))->toBeTrue();

        retry_record(['trigger' => 'deploy', 'outcome' => 'interrupted']);
        expect($retry->retryable('gateway.release_verify_failed', $sha, $current->id))->toBeFalse()
            ->and($retry->retryable('gateway.release_verify_failed', str_repeat('b', 40), $current->id))->toBeTrue();
    });

    it('makes a failure the commit causes final at once', function (): void {
        expect(new GatewayReleaseRetry()->retryable('gateway.release_migration_crossed', str_repeat('c', 40)))->toBeFalse();
    });
});
