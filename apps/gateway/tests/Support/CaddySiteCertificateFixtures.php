<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Node;

/** Records that every role certificate is on a Node, as each role's certificate step does before its build. */
final class CaddySiteCertificateFixtures
{
    public static function recordAll(Node ...$nodes): void
    {
        $certificates = new CaddySiteCertificates;

        foreach ($nodes as $node) {
            foreach ([CaddySiteCertificates::Websocket, CaddySiteCertificates::Analytics, CaddySiteCertificates::ProxyCli, CaddySiteCertificates::Metrics] as $site) {
                $certificates->record($node->id, $site);
            }
        }
    }
}
