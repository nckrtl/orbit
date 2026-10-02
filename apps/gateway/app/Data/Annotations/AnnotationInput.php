<?php

declare(strict_types=1);

namespace App\Data\Annotations;

use Spatie\LaravelData\Data;

final class AnnotationInput extends Data
{
    /** @param array<string, mixed> $context */
    public function __construct(public array $context) {}
}
