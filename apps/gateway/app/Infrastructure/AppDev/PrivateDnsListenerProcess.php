<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use Throwable;

/**
 * Runs the private DNS listener. It depends on no framework code, so the Gateway can install it on the vpn Node as a
 * self-contained release. See ADR 0149.
 */
final class PrivateDnsListenerProcess
{
    private bool $stopping = false;

    /**
     * @param  array<string, string|null>  $options  listen, port, catalog, and upstream
     * @param  resource  $errors
     */
    public function run(array $options, $errors): int
    {
        $listen = $options['listen'] ?? null;
        $catalog = $options['catalog'] ?? null;
        $upstream = $options['upstream'] ?? null;
        $port = $options['port'] ?? null;

        if (! is_string($listen) || $listen === '' || ! is_string($catalog) || $catalog === '' || ! is_string($upstream) || $upstream === '' || ! is_numeric($port)) {
            fwrite($errors, 'Private DNS listener arguments are invalid.'.PHP_EOL);

            return 1;
        }

        $server = new PrivateDnsListenerFactory()->make($catalog, $listen, (int) $port, $upstream);
        $this->handleStopSignals();

        try {
            $inherited = PrivateDnsInheritedSockets::fromEnvironment();
            if ($inherited !== null) {
                $server->adopt($inherited[0], $inherited[1]);
            } else {
                new PrivateDnsSocketBinder()->bind($server);
            }

            while ($server->listening() && ! $this->stopping) {
                $server->serveOnce(1.0);
            }
        } catch (Throwable $exception) {
            fwrite($errors, $exception->getMessage().PHP_EOL);

            return 1;
        } finally {
            $server->stop();
        }

        if ($this->stopping) {
            return 0;
        }

        fwrite($errors, 'The private DNS listener stopped unexpectedly.'.PHP_EOL);

        return 1;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, string|null>
     */
    public static function options(array $arguments): array
    {
        $options = ['listen' => null, 'port' => '53', 'catalog' => '/var/lib/orbit/private-dns/catalog.json', 'upstream' => '127.0.0.55:53'];

        foreach ($arguments as $argument) {
            if (preg_match('/\A--(listen|port|catalog|upstream)=(.*)\z/s', $argument, $match) === 1) {
                $options[$match[1]] = $match[2];
            }
        }

        return $options;
    }

    /**
     * A stop signal ends the loop after the query in hand, so a restart does not drop it. The sockets stay open in
     * systemd for the next listener.
     */
    private function handleStopSignals(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopping = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
