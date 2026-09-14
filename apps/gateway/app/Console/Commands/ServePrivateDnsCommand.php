<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\AppDev\PrivateDnsListenerFactory;
use App\Infrastructure\AppDev\PrivateDnsSocketBinder;
use Illuminate\Console\Command;
use Throwable;

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

    public function handle(PrivateDnsListenerFactory $factory, PrivateDnsSocketBinder $binder): int
    {
        $listen = $this->option('listen');
        $catalog = $this->option('catalog');
        $upstream = $this->option('upstream');
        $port = $this->option('port');

        if (! is_string($listen) || $listen === '' || ! is_string($catalog) || $catalog === '' || ! is_string($upstream) || $upstream === '' || ! is_numeric($port)) {
            $this->error('Private DNS listener arguments are invalid.');

            return self::FAILURE;
        }

        $server = $factory->make($catalog, $listen, (int) $port, $upstream);

        try {
            $binder->bind($server);
            while ($server->listening()) {
                $server->serveOnce(1.0);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $server->stop();
        }

        $this->error('The private DNS listener stopped unexpectedly.');

        return self::FAILURE;
    }
}
