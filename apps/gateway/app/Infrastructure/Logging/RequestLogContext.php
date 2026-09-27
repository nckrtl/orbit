<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RequestLogContext
{
    private static int $depth = 0;

    private static ?string $requestId = null;

    public static function enter(string $requestId): ?string
    {
        $previousRequestId = self::$depth > 0 ? self::$requestId : null;
        self::$depth++;
        self::set($requestId);

        return $previousRequestId;
    }

    public static function leave(string $requestId, ?string $previousRequestId): void
    {
        self::$depth--;

        if ($previousRequestId !== null) {
            self::set($previousRequestId);

            return;
        }

        if (self::$depth > 0) {
            return;
        }

        self::set($requestId);
        app()->terminating(static function () use ($requestId): void {
            if (self::$requestId === $requestId) {
                self::set(null);
            }
        });
    }

    public static function retainForStream(StreamedResponse $response, string $requestId): void
    {
        $callback = $response->getCallback();

        if ($callback === null) {
            return;
        }

        $response->setCallback(static function () use ($callback, $requestId): void {
            $previousRequestId = self::$requestId;
            self::$depth++;
            self::set($requestId);

            try {
                $callback();
            } finally {
                self::$depth--;
                self::set($previousRequestId);
            }
        });
    }

    private static function set(?string $requestId): void
    {
        if ($requestId === null) {
            Log::withoutContext(['request_id']);
        } else {
            Log::withContext(['request_id' => $requestId]);
        }

        self::$requestId = $requestId;
    }
}
