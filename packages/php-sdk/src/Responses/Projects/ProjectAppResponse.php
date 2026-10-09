<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Projects;

/** One named app of a Project, or an Instance's effective copy of it. */
final readonly class ProjectAppResponse
{
    public function __construct(
        public string $name,
        public string $path,
        public ?string $webRoot,
        public string $type,
    ) {}

    /** @return list<self> */
    public static function listFromGatewayData(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $apps = [];

        foreach ($value as $app) {
            if (
                ! is_array($app)
                || ! is_string($app['name'] ?? null)
                || ! is_string($app['path'] ?? null)
                || ! (is_string($app['web_root'] ?? null) || ($app['web_root'] ?? null) === null)
                || ! is_string($app['type'] ?? null)
            ) {
                continue;
            }

            $apps[] = new self($app['name'], $app['path'], $app['web_root'] ?? null, $app['type']);
        }

        return $apps;
    }

    /** @return array{name: string, path: string, web_root: string|null, type: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'path' => $this->path, 'web_root' => $this->webRoot, 'type' => $this->type];
    }
}
