<?php

declare(strict_types=1);

return [
    /* TypeSafe Jev is the official Laravel AI classification provider. */

    'default_for_classification' => 'typesafe',

    'providers' => [
        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
            'url' => env('TYPESAFE_URL', 'https://api.typesafe.ai/v1'),
            'models' => [
                'classification' => [
                    'default' => env('TYPESAFE_MODEL', 'jev-latest'),
                ],
            ],
        ],
    ],
];
