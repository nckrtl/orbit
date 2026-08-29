<?php

declare(strict_types=1);

use Laravel\Boost\Mcp\Tools\LastError;

return [
    'mcp' => [
        'tools' => [
            'exclude' => [
                LastError::class,
            ],
        ],
    ],
    'rules' => [
        'enabled' => true,
        'scoped_guidelines' => true,
    ],
    'guidelines' => [
        'exclude' => [
            'deployments',
            'foundation',
            'laravel/core',
            'pest/core',
            'spatie/guidelines-skills/core',
        ],
    ],
    'skills' => [
        'exclude' => [
            'infer-conventions',
            'spatie-javascript',
        ],
    ],
];
