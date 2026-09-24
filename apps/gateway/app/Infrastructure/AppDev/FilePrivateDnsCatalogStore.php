<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\PrivateDnsAnswerCache;
use JsonException;

final class FilePrivateDnsCatalogStore
{
    /**
     * The SHA-256 digest of the loaded catalog. A publication replaces the file atomically, and two
     * publications in the same second can keep the same inode, mtime, and size, so only the
     * content identifies a replacement.
     */
    private ?string $signature = null;

    private PrivateDnsAnswerCatalog $catalog;

    private WireGuardDnsRequesterResolver $requesters;

    /**
     * @param  ?string  $loadedPath  Receives the digest of each catalog the store loads, so a publication can
     *                               confirm that the running listener serves the catalog it published.
     */
    public function __construct(
        private readonly string $path,
        private readonly ?PrivateDnsAnswerCache $cache = null,
        private readonly ?string $loadedPath = null,
    ) {
        $this->catalog = new PrivateDnsAnswerCatalog(exact: [], suffixes: []);
        $this->requesters = WireGuardDnsRequesterResolver::fromPublished([]);
        $this->refresh();
    }

    public function refresh(): bool
    {
        clearstatcache(true, $this->path);
        if (! is_file($this->path)) {
            return false;
        }

        $contents = file_get_contents($this->path);
        if (! is_string($contents) || $contents === '') {
            return false;
        }

        $signature = hash('sha256', $contents);
        if ($signature === $this->signature) {
            return false;
        }

        try {
            $published = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($published)) {
            return false;
        }

        /** @var array<string, int> $requesters */
        $requesters = [];
        foreach ($published['requesters'] ?? [] as $address => $nodeId) {
            if (is_int($nodeId) || (is_string($nodeId) && ctype_digit($nodeId))) {
                $requesters[(string) $address] = (int) $nodeId;
            }
        }

        $this->catalog = PrivateDnsAnswerCatalog::fromPublished($published);
        $this->requesters = WireGuardDnsRequesterResolver::fromPublished($requesters);
        $this->signature = $signature;
        $this->cache?->flush();
        $this->confirm($signature);

        return true;
    }

    /**
     * The file that confirms which catalog the listener serves, next to the catalog itself.
     */
    public static function loadedPath(string $catalogPath): string
    {
        return $catalogPath.'.loaded';
    }

    /**
     * A failed write never stops the listener. The publication then cannot confirm the load and restarts it.
     */
    private function confirm(string $signature): void
    {
        if ($this->loadedPath === null || ! is_dir(dirname($this->loadedPath)) || ! is_writable(dirname($this->loadedPath))) {
            return;
        }

        $candidate = $this->loadedPath.'.'.getmypid().'.candidate';
        if (@file_put_contents($candidate, $signature.PHP_EOL) === false || ! @rename($candidate, $this->loadedPath)) {
            @unlink($candidate);
        }
    }

    public function catalog(): PrivateDnsAnswerCatalog
    {
        return $this->catalog;
    }

    public function requesters(): WireGuardDnsRequesterResolver
    {
        return $this->requesters;
    }
}
