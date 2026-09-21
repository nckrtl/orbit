<?php

declare(strict_types=1);

return [
    /*
    | Gateway introduces Laravel AI Classification + the TypeSafe Jev provider
    | for task-session routing. Commander only stored TYPESAFE_API_KEY and
    | TOOLBAR_TYPESAFE_ENABLED. It had no laravel/ai package, no config/ai.php,
    | and no application code that read those keys.
    |
    | laravel/ai 1.x Classification cannot install beside laravel/boost's
    | laravel/mcp <1.0 pin. This config keeps the 1.x provider shape. Gateway
    | owns the fakeable Classification client and posts to TypeSafe.
    */

    'default_for_classification' => 'typesafe',

    'providers' => [
        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
            'url' => env('TYPESAFE_URL', 'https://api.typesafe.ai/v1/systemone'),
            'model' => env('TYPESAFE_MODEL', 'jev-latest'),
        ],
    ],
];
