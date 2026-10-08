<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PhpPoolDirectoryInspector;
use App\Domain\Doctor\PhpPoolDirectoryObservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

/**
 * Reads the Node's live `orbit-scopes.conf` files through the app-dev PHP-FPM discovery and checks, as root,
 * every pool working directory they and stored state name.
 */
final readonly class NativePhpPoolDirectoryInspector implements PhpPoolDirectoryInspector
{
    public const float ReadTimeoutSeconds = 20.0;

    public function __construct(
        private RemoteAppDevPhpFpmManager $php,
    ) {}

    public function inspect(Node $node): array
    {
        if ($node->platform !== 'linux' || ! $this->servesSharedPhp($node)) {
            return [];
        }

        try {
            $pools = $this->php->poolsWithMissingDirectories($node, self::ReadTimeoutSeconds);
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        return array_map(
            static fn (array $pool): PhpPoolDirectoryObservation => new PhpPoolDirectoryObservation(
                pool: $pool['pool'],
                version: $pool['version'],
                directory: $pool['directory'],
                installed: $pool['installed'],
            ),
            $pools,
        );
    }

    private function servesSharedPhp(Node $node): bool
    {
        return NodeRole::query()
            ->where('node_id', $node->id)
            ->where('status', LifecycleStatus::Active->value)
            ->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])
            ->exists();
    }
}
