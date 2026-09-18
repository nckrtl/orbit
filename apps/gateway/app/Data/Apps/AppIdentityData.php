<?php

declare(strict_types=1);

namespace App\Data\Apps;

use App\Models\App;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The identity of a related App, so a client can name it without a second request. */
#[MapOutputName(SnakeCaseMapper::class)]
final class AppIdentityData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}

    public static function fromModel(App $app): self
    {
        return new self(id: $app->id, name: $app->name, slug: $app->slug);
    }
}
