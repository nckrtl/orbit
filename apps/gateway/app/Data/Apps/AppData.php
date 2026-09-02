<?php

declare(strict_types=1);

namespace App\Data\Apps;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\App as OrbitApp;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppData extends Data
{
    /**
     * @param array<array-key, mixed>|null $defaults
     *
     * @mago-expect lint:excessive-parameter-list The response exposes the complete bounded App contract.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $repositoryUrl,
        public ?string $mainBranch,
        public ?string $root,
        public ?array $defaults,
    ) {}

    public static function fromModel(OrbitApp $app): self
    {
        return new self(
            id: $app->id,
            name: $app->name,
            slug: $app->slug,
            repositoryUrl: $app->repository_url,
            mainBranch: $app->main_branch,
            root: $app->root,
            defaults: self::publicDefaults($app->defaults),
        );
    }

    /**
     * @param array<string, mixed>|null $defaults
     *
     * @return array<array-key, mixed>|null
     */
    private static function publicDefaults(?array $defaults): ?array
    {
        if ($defaults === null) {
            return null;
        }

        return new CommandActivityInputSanitizer()->sanitizeProperties($defaults);
    }
}
