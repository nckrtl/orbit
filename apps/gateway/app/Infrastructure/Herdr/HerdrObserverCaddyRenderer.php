<?php

declare(strict_types=1);

namespace App\Infrastructure\Herdr;

use App\Models\HerdrSession;

final readonly class HerdrObserverCaddyRenderer
{
    /** The live publisher binds every interface; the Node Caddy build passes its chosen listener. */
    public function render(HerdrSession $session, string $bind = '0.0.0.0'): string
    {
        $hostname = $session->observer_hostname;
        $port = $session->observer_port;
        $certificateDirectory = '/etc/orbit/herdr/'.$session->session;

        return <<<CADDY
            # Managed by Orbit: herdr-observer
            https://{$hostname} {
                bind {$bind}
                tls {$certificateDirectory}/cert.pem {$certificateDirectory}/key.pem
                reverse_proxy 127.0.0.1:{$port}
            }

            CADDY;
    }
}
