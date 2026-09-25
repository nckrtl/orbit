<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class CaddyBuildObservation
{
    /**
     * @param  ?string  $expectedVersion  The version a fresh build renders, or null when stored state renders no buildable file.
     * @param  ?string  $liveVersion  The digest version of the live file, or null when no Node Caddy build wrote it.
     * @param  list<string>  $sources  The site sources a build renders on the Node, such as `app-dev` or `websocket`.
     */
    public function __construct(
        public ?string $expectedVersion,
        public ?string $liveVersion,
        public bool $matches,
        public array $sources,
    ) {}
}
