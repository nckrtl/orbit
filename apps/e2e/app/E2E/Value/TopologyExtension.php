<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum TopologyExtension: string
{
    case AppProd = 'app-prod';

    public function recipe(string $image = TopologyRecipe::BASE_IMAGE): TopologyRecipe
    {
        return match ($this) { self::AppProd => TopologyRecipe::extendedAppProd($image) };
    }
}
