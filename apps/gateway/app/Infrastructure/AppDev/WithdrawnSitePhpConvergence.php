<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Models\Node;

/**
 * Converges PHP-FPM on a Node after a site left stored state. Stored state renders no pool for the
 * withdrawn site, so a convergence that skipped another site's pool for a missing directory has still
 * withdrawn this one. Doctor reports the skipped pool; it must not keep the withdrawal open.
 */
final readonly class WithdrawnSitePhpConvergence
{
    public function __construct(
        private AppDevPhpFpmManager $php,
    ) {}

    public function converge(Node $node): void
    {
        try {
            $this->php->converge($node);
        } catch (RuntimeConvergenceException $exception) {
            if ($exception->errorCode !== RemoteAppDevPhpFpmManager::PoolDirectoryMissing) {
                throw $exception;
            }
        }
    }
}
