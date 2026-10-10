<?php

declare(strict_types=1);

namespace App\Data\Conn;

use Spatie\LaravelData\Data;

final class CreateConnProfileData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
