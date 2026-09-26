<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | The Gateway only broadcasts to an external Reverb server; it never
    | runs one. Broadcasting nowhere ("null") is the default here; the
    | `reverb` connection below is only pointed at a real Reverb server, and
    | the default flipped to `reverb`, by App\Domain\Broadcasting\RealtimeConnection
    | at broadcast time, from the active `websocket` role assignment. There
    | is no environment contract for this any more.
    |
    */

    'default' => 'null',

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => null,
            'secret' => null,
            'app_id' => null,
            'options' => [
                'host' => null,
                'port' => 443,
                'scheme' => 'https',
                'useTLS' => true,
                // Every broadcast runs inside the request that caused it, including an Activity notice for
                // almost every request (ADR 0159), so a slow or unreachable Reverb must not hold the request.
                'timeout' => 3,
            ],
            'client_options' => [
                // See available options: https://docs.guzzlephp.org/en/stable/request-options.html
                // 'verify' is set at broadcast time to the Orbit CA root certificate.
                'connect_timeout' => 2,
                'timeout' => 3,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
