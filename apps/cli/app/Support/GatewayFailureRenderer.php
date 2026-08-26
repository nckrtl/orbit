<?php

declare(strict_types=1);

namespace App\Support;

use LaravelZero\Framework\Commands\Command;

final class GatewayFailureRenderer
{
    public static function write(
        Command $command,
        string $code,
        string $message,
        ?string $requestId = null,
        ?string $humanMessage = null,
    ): void {
        $code = self::safeErrorCode($code);
        $message = self::safeErrorMessage($message);
        $requestId = self::safeRequestId($requestId);

        if ($command->option('json') === true) {
            $command->line(self::json($code, $message, $requestId));

            return;
        }

        $command->error(self::safeErrorMessage($humanMessage ?? $message));

        if ($requestId !== null) {
            $command->line("Request ID: {$requestId}");
        }
    }

    public static function json(string $code, string $message, ?string $requestId = null): string
    {
        return json_encode([
            'error' => [
                'code' => self::safeErrorCode($code),
                'message' => self::safeErrorMessage($message),
                'request_id' => self::safeRequestId($requestId),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function safeErrorCode(string $code): string
    {
        if (strlen($code) <= 128 && preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $code) === 1) {
            return $code;
        }

        return 'gateway.request_failed';
    }

    private static function safeErrorMessage(string $message): string
    {
        $message = preg_replace(pattern: '/[\x00-\x1F\x7F]+/', replacement: ' ', subject: $message);
        $message = is_string($message) ? trim($message) : '';

        if ($message === '' || strlen($message) > 512) {
            return 'Gateway request failed.';
        }

        return $message;
    }

    private static function safeRequestId(?string $requestId): ?string
    {
        if (
            ! is_string($requestId)
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di',
                $requestId,
            ) !== 1
        ) {
            return null;
        }

        return $requestId;
    }
}
