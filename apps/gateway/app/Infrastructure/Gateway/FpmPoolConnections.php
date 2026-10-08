<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

/**
 * Requests in flight in a PHP-FPM master: the accepted connections on the `listen` socket of every enabled pool
 * (`*.conf` in the pool directory). The pools share one OPcache. Caddy opens one connection for each request it
 * passes on, over a unix socket or TCP.
 */
final readonly class FpmPoolConnections
{
    public function __construct(
        private string $poolDirectory = '/etc/php/8.5/fpm/pool.d',
        private string $procNet = '/proc/net',
    ) {}

    public function count(): int
    {
        $connections = 0;

        foreach (glob($this->poolDirectory.'/*.conf') ?: [] as $pool) {
            if (preg_match_all('/^\s*listen\s*=\s*(\S+)\s*$/m', (string) @file_get_contents($pool), $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $listen) {
                $connections += str_starts_with($listen, '/') ? $this->unix($listen) : $this->tcp($listen);
            }
        }

        return $connections;
    }

    private function unix(string $path): int
    {
        $count = 0;

        foreach ($this->rows('unix') as $fields) {
            // Num RefCount Protocol Flags Type St Inode Path; St 03 is connected.
            if (($fields[5] ?? '') === '03' && ($fields[7] ?? '') === $path) {
                $count++;
            }
        }

        return $count;
    }

    private function tcp(string $listen): int
    {
        $separator = strrpos($listen, ':');
        $port = sprintf('%04X', (int) ($separator === false ? $listen : substr($listen, $separator + 1)));
        $count = 0;

        foreach ([...$this->rows('tcp'), ...$this->rows('tcp6')] as $fields) {
            // sl local_address rem_address st; st 01 is established.
            if (str_ends_with($fields[1] ?? '', ':'.$port) && ($fields[3] ?? '') === '01') {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<list<string>> the table's rows after the header, split on whitespace */
    private function rows(string $table): array
    {
        $rows = [];

        foreach (array_slice(@file($this->procNet.'/'.$table, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 1) as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if (is_array($fields)) {
                $rows[] = $fields;
            }
        }

        return $rows;
    }
}
