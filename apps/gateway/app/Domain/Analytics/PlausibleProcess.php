<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use SensitiveParameter;

/**
 * The one Process the analytics role owns: Plausible Community Edition as a Node-targeted Docker
 * Process. The image, the startup command, and the port are the ones the earlier Orbit analytics
 * role ran in production.
 */
final readonly class PlausibleProcess
{
    public const string NAME = 'plausible';

    public const string IMAGE = 'ghcr.io/plausible/community-edition';

    public const int PORT = 8000;

    /** Plausible creates and migrates its PostgreSQL database before it serves, so a fresh server needs no preparation. */
    private const string COMMAND = '/entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run';

    public static function data(
        Node $node,
        string $version,
        #[SensitiveParameter]
        AnalyticsStorageConnection $storage,
        #[SensitiveParameter]
        string $secretKeyBase,
    ): AddProcessData {
        if (preg_match('/\A\d+\.\d+\.\d+\z/', $version) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'analytics.version_invalid',
                message: 'A Plausible version has the form 3.2.1.',
                status: 422,
            );
        }

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'process.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
                status: 422,
            );
        }

        return new AddProcessData(
            targetType: ProcessTargetType::Node,
            targetId: $node->id,
            name: self::NAME,
            runtime: ProcessRuntime::Docker,
            command: ['sh', '-c', self::COMMAND],
            image: self::IMAGE.':v'.$version,
            workingDirectory: null,
            environment: [
                'BASE_URL' => 'https://'.AnalyticsHostname::Value,
                'DATABASE_URL' => $storage->databaseUrl,
                'CLICKHOUSE_DATABASE_URL' => $storage->clickhouseDatabaseUrl,
                'SECRET_KEY_BASE' => $secretKeyBase,
            ],
            // WireGuard only: the Node's own Caddy site serves the dashboard, and the Router
            // reaches the tracking paths over WireGuard.
            ports: ["{$node->wireguard_ip}:".self::PORT.':'.self::PORT.'/tcp'],
            volumes: [],
            restartPolicy: 'unless-stopped',
            start: true,
        );
    }
}
