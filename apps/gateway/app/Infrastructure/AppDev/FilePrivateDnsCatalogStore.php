<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\PrivateDnsAnswerCache;
use JsonException;

final class FilePrivateDnsCatalogStore
{
    private ?int $mtime = null;

    private PrivateDnsAnswerCatalog $catalog;

    private WireGuardDnsRequesterResolver $requesters;

    public function __construct(
        private readonly string $path,
        private readonly ?PrivateDnsAnswerCache $cache = null,
    ) {
        $this->catalog = new PrivateDnsAnswerCatalog(exact: [], suffixes: []);
        $this->requesters = WireGuardDnsRequesterResolver::fromPublished([]);
        $this->refresh();
    }

    public function refresh(): bool
    {
        if (! is_file($this->path)) {
            return false;
        }

        $mtime = filemtime($this->path);
        if ($mtime === false || $mtime === $this->mtime) {
            return false;
        }

        $contents = file_get_contents($this->path);
        if (! is_string($contents) || $contents === '') {
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
        $this->mtime = $mtime;
        $this->cache?->flush();

        return true;
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
