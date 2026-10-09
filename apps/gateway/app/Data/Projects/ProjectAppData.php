<?php

declare(strict_types=1);

namespace App\Data\Projects;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One named app of a Project, or an Instance's effective copy of it. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ProjectAppData extends Data
{
    public function __construct(
        public string $name,
        public string $path,
        public ?string $webRoot,
        public string $type,
    ) {}

    /** @param array{name: string, path: string, web_root: ?string, type: string} $app */
    public static function fromArray(array $app): self
    {
        return new self(name: $app['name'], path: $app['path'], webRoot: $app['web_root'], type: $app['type']);
    }
}
