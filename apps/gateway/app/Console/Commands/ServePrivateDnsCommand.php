<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\AppDev\PrivateDnsListenerProcess;
use Illuminate\Console\Command;

final class ServePrivateDnsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:private-dns-serve
        {--listen= : WireGuard address that receives VPN DNS queries}
        {--port=53 : UDP and TCP port to bind}
        {--catalog=/var/lib/orbit/private-dns/catalog.json : Published requester catalog}
        {--upstream=127.0.0.55:53 : Backend dnsmasq address for names outside the catalog}';

    #[\Override]
    protected $description = 'Serve requester-aware Orbit VPN DNS answers from the published catalog.';

    public function handle(): int
    {
        $errors = fopen('php://stderr', 'w');

        return new PrivateDnsListenerProcess()->run([
            'listen' => is_string($this->option('listen')) ? $this->option('listen') : null,
            'port' => is_scalar($this->option('port')) ? (string) $this->option('port') : null,
            'catalog' => is_string($this->option('catalog')) ? $this->option('catalog') : null,
            'upstream' => is_string($this->option('upstream')) ? $this->option('upstream') : null,
        ], $errors === false ? STDERR : $errors) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
