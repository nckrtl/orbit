<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Analytics\NativeAnalyticsSecretManager;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->storage = AnalyticsStorageConnection::from(
        analytics_connection_process($this->node, 'analytics-postgres', 'postgres:16-alpine', ['POSTGRES_PASSWORD' => 'pg-secret'], ['10.44.0.3:5432:5432/tcp']),
        analytics_connection_process($this->node, 'analytics-clickhouse', 'clickhouse/clickhouse-server:24.12-alpine', ['CLICKHOUSE_USER' => 'plausible', 'CLICKHOUSE_PASSWORD' => 'ch-secret', 'CLICKHOUSE_DB' => 'plausible_events_db'], ['10.44.0.3:8123:8123/tcp']),
    );
});

describe('PlausibleProcess', function (): void {
    it('describes Plausible as a Node-targeted Docker Process bound to WireGuard only', function (): void {
        $data = PlausibleProcess::data($this->node, '3.2.1', $this->storage, str_repeat('k', 64));

        expect($data->targetType)->toBe(ProcessTargetType::Node)
            ->and($data->targetId)->toBe($this->node->id)
            ->and($data->name)->toBe('plausible')
            ->and($data->runtime)->toBe(ProcessRuntime::Docker)
            ->and($data->image)->toBe('ghcr.io/plausible/community-edition:v3.2.1')
            ->and($data->command)->toBe(['sh', '-c', '/entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run'])
            ->and($data->ports)->toBe(['10.44.0.3:8000:8000/tcp'])
            ->and($data->volumes)->toBe([])
            ->and($data->restartPolicy)->toBe('unless-stopped')
            ->and($data->start)->toBeTrue()
            ->and($data->environment)->toBe([
                'BASE_URL' => 'https://analytics.orbit',
                'DATABASE_URL' => 'postgres://postgres:pg-secret@10.44.0.3:5432/plausible_db',
                'CLICKHOUSE_DATABASE_URL' => 'http://plausible:ch-secret@10.44.0.3:8123/plausible_events_db',
                'SECRET_KEY_BASE' => str_repeat('k', 64),
            ]);
    });

    it('refuses a version that is not three numbers, so no caller text reaches the image tag', function (string $version): void {
        expect(fn () => PlausibleProcess::data($this->node, $version, $this->storage, str_repeat('k', 64)))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('analytics.version_invalid'));
    })->with(['v3.2.1', '3.2', 'latest', '3.2.1; rm -rf /', '']);

    it('refuses a Node without a WireGuard address', function (): void {
        $this->node->update(['wireguard_ip' => null]);

        expect(fn () => PlausibleProcess::data($this->node->refresh(), '3.2.1', $this->storage, str_repeat('k', 64)))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('process.wireguard_ip_missing'));
    });
});

describe('AnalyticsSecretManager', function (): void {
    it('generates the secret key base once, stores it protected, and returns the same value afterwards', function (): void {
        $secrets = app(NativeAnalyticsSecretManager::class);

        $first = $secrets->secretKeyBase($this->node);

        expect($secrets)->toBeInstanceOf(AnalyticsSecretManager::class)
            ->and(strlen($first))->toBe(64)
            ->and($secrets->secretKeyBase($this->node))->toBe($first)
            ->and(json_encode(DB::table('nodes')->where('id', $this->node->id)->value('settings')))->not->toContain($first);
    });

    it('generates a new one after a purge', function (): void {
        $secrets = app(NativeAnalyticsSecretManager::class);
        $first = $secrets->secretKeyBase($this->node);

        $secrets->purge($this->node);

        expect($secrets->secretKeyBase($this->node))->not->toBe($first);
    });
});
