<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

final readonly class GatewayCleanupStartupRenderer
{
    public const string HOOK_PATH = '/etc/orbit/project-document-cleanup-start';

    public function renderHook(string $checkoutPath, string $orbitHome): string
    {
        $artisan = escapeshellarg(rtrim($checkoutPath, '/').'/artisan');
        $home = escapeshellarg('ORBIT_HOME='.$orbitHome);

        return <<<BASH
            #!/bin/bash
            set -euo pipefail
            # Preserve the stable lock across service and consumer restarts. Never repair existing state implicitly.
            [ ! -L /run/orbit ]
            if [ ! -e /run/orbit ]; then
                install -d -o root -g root -m 0755 /run/orbit
            fi
            if [ ! -e /run/orbit/project-documents ] && [ ! -L /run/orbit/project-documents ]; then
                mkdir -m 0700 /run/orbit/project-documents
                chown orbit:orbit /run/orbit/project-documents
                install -o orbit -g orbit -m 0600 /dev/null /run/orbit/project-documents/execution.lock
            fi
            exec /usr/sbin/runuser -u orbit -- /usr/bin/env HOME=/home/orbit {$home} /usr/bin/php8.5 {$artisan} project-documents:cleanup:invalidate --no-interaction
            BASH.PHP_EOL;
    }

    public function renderDropIn(): string
    {
        return "# Managed by Orbit: document cleanup startup gate\n[Service]\nExecStartPre=+".self::HOOK_PATH."\n";
    }
}
