<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

final readonly class FirewallInspectionShape
{
    public function __construct(
        public string $comment,
        public string $action,
        public string $direction,
        public string $source,
        public string $destination,
        public string $port,
        public string $protocol,
        public ?string $inInterface,
        public ?string $outInterface,
        public ?string $family,
    ) {}
}
