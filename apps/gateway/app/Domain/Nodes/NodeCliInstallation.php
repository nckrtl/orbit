<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/** What the CLI install step did on one Node. */
final readonly class NodeCliInstallation
{
    public const string Installed = 'cli_installed';

    public const string Present = 'cli_present';

    /** The Node state recorded while `/usr/local/bin/orbit` is a CLI Orbit did not install. */
    public const string Foreign = 'foreign';

    public function __construct(
        /** `cli_installed` or `cli_present`. */
        public string $outcome,
        /** Whether the Node profile or its root certificate was written. */
        public bool $configured,
        /** The release version installed, or null when a present CLI stayed. */
        public ?string $version = null,
    ) {}

    public function changed(): bool
    {
        return $this->outcome === self::Installed || $this->configured;
    }

    /** @return array{outcome: string, configured: bool, version: ?string} */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'configured' => $this->configured, 'version' => $this->version];
    }
}
