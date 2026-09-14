<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

final readonly class DockerPublishedPort
{
    public function __construct(
        public ?string $bindAddress,
        public int $publishedPort,
        public int $containerPort,
    ) {}

    public static function parse(string $spec): ?self
    {
        $withoutProtocol = explode('/', $spec, 2)[0];
        $segments = explode(':', $withoutProtocol);

        if (count($segments) < 2) {
            return null;
        }

        $container = filter_var(array_last($segments), FILTER_VALIDATE_INT);
        $published = filter_var($segments[array_key_last($segments) - 1], FILTER_VALIDATE_INT);

        if (! is_int($container) || ! is_int($published) || $container < 1 || $container > 65535 || $published < 1 || $published > 65535) {
            return null;
        }

        $bindAddress = count($segments) > 2 ? implode(':', array_slice($segments, 0, -2)) : null;

        return new self(
            bindAddress: $bindAddress === '' ? null : $bindAddress,
            publishedPort: $published,
            containerPort: $container,
        );
    }

    public function matches(int $port): bool
    {
        return $this->publishedPort === $port || $this->containerPort === $port;
    }
}
