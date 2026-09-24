<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @param array<string, mixed>|null $properties */
function redact_activity_row(?array $properties): int
{
    return (int) DB::table('activity_log')->insertGetId([
        'log_name' => 'commands',
        'description' => 'proxycli:enable',
        'event' => 'command',
        'properties' => $properties === null ? null : json_encode($properties, JSON_THROW_ON_ERROR),
        'request_id' => (string) Str::uuid(),
        'command' => 'proxycli:enable',
        'status' => 'succeeded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string, mixed>|null */
function redact_activity_properties(int $id): ?array
{
    $stored = DB::table('activity_log')->where('id', $id)->value('properties');

    return is_string($stored) ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
}

it('redacts secrets in stored activity and keeps every other field', function (): void {
    $key = 'management-'.Str::random(32);
    $leaked = redact_activity_row([
        'method' => 'POST',
        'path' => 'api/v1/proxycli',
        'input' => [
            'node_id' => 4,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => $key,
        ],
    ]);
    $handRedacted = redact_activity_row([
        'input' => ['node_id' => 4, 'cliproxy_management_key' => '[REDACTED]'],
    ]);
    $keyRemoved = redact_activity_row(['input' => ['node_id' => 4]]);
    $empty = redact_activity_row(null);
    $untouched = DB::table('activity_log')->where('id', $handRedacted)->value('updated_at');

    $this->artisan('orbit:activity-redact')
        ->expectsOutput('Scanned 4 Activity records. Redacted 1.')
        ->assertSuccessful();

    expect(redact_activity_properties($leaked))->toBe([
        'method' => 'POST',
        'path' => 'api/v1/proxycli',
        'input' => [
            'node_id' => 4,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => '[REDACTED]',
        ],
    ])
        ->and((string) DB::table('activity_log')->where('id', $leaked)->value('properties'))->not->toContain($key)
        ->and(redact_activity_properties($handRedacted))->toBe(['input' => ['node_id' => 4, 'cliproxy_management_key' => '[REDACTED]']])
        ->and(DB::table('activity_log')->where('id', $handRedacted)->value('updated_at'))->toBe($untouched)
        ->and(redact_activity_properties($keyRemoved))->toBe(['input' => ['node_id' => 4]])
        ->and(redact_activity_properties($empty))->toBeNull();

    $this->artisan('orbit:activity-redact')
        ->expectsOutput('Scanned 4 Activity records. Redacted 0.')
        ->assertSuccessful();
});

it('reports what it would redact without writing on a dry run', function (): void {
    $key = 'management-'.Str::random(32);
    $leaked = redact_activity_row(['input' => ['cliproxy_management_key' => $key]]);

    $this->artisan('orbit:activity-redact', ['--dry-run' => true])
        ->expectsOutput('Scanned 1 Activity records. Would redact 1.')
        ->assertSuccessful();

    expect(redact_activity_properties($leaked))->toBe(['input' => ['cliproxy_management_key' => $key]]);
});
