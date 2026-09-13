<?php

declare(strict_types=1);

namespace App\Support;

use LaravelZero\Framework\Commands\Command;

final class GatewayFailureRenderer
{
    private const int MAX_FIELD_MESSAGES = 50;

    private const int MAX_FIELD_LENGTH = 128;

    private const int MAX_MESSAGE_LENGTH = 512;

    /**
     * @param  array<string,mixed>  $details
     */
    public static function write(
        Command $command,
        string $code,
        string $message,
        ?string $requestId = null,
        ?string $humanMessage = null,
        array $details = [],
    ): void {
        $code = self::safeErrorCode($code);
        $message = self::safeErrorMessage($message);
        $requestId = self::safeRequestId($requestId);

        if ($command->option('json') === true) {
            $command->line(self::json($code, $message, $requestId, $details));

            return;
        }

        $command->error(self::safeErrorMessage($humanMessage ?? $message));

        foreach (self::fieldDetails($details) as $field => $messages) {
            foreach (is_string($messages) ? [$messages] : $messages as $fieldMessage) {
                $command->line("{$field}: {$fieldMessage}");
            }
        }

        if ($requestId !== null) {
            $command->line("Request ID: {$requestId}");
        }
    }

    /**
     * Keeps the details that render safely as field messages: a string field with one
     * message or a list of messages, each sanitized and bounded like the error message,
     * in the Gateway's order and capped at a total number of messages.
     *
     * @param  array<array-key,mixed>  $details
     * @return array<string,string|list<string>>
     */
    public static function fieldDetails(array $details): array
    {
        $fields = [];
        $remaining = self::MAX_FIELD_MESSAGES;

        foreach ($details as $field => $value) {
            if ($remaining === 0) {
                break;
            }

            $field = is_string($field) ? self::safeText($field, self::MAX_FIELD_LENGTH) : null;

            if ($field === null) {
                continue;
            }

            if (is_string($value)) {
                $fieldMessage = self::safeText($value, self::MAX_MESSAGE_LENGTH);

                if ($fieldMessage === null) {
                    continue;
                }

                $fields[$field] = $fieldMessage;
                $remaining--;

                continue;
            }

            if (! is_array($value) || ! array_is_list($value)) {
                continue;
            }

            $messages = [];

            foreach ($value as $item) {
                if ($remaining === 0) {
                    break;
                }

                $fieldMessage = is_string($item) ? self::safeText($item, self::MAX_MESSAGE_LENGTH) : null;

                if ($fieldMessage === null) {
                    continue;
                }

                $messages[] = $fieldMessage;
                $remaining--;
            }

            if ($messages !== []) {
                $fields[$field] = $messages;
            }
        }

        return $fields;
    }

    /** @param array<string,mixed> $details */
    public static function json(string $code, string $message, ?string $requestId = null, array $details = []): string
    {
        $error = [
            'code' => self::safeErrorCode($code),
            'message' => self::safeErrorMessage($message),
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        $error['request_id'] = self::safeRequestId($requestId);

        return json_encode(['error' => $error], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
        return self::safeText($message, self::MAX_MESSAGE_LENGTH) ?? 'Gateway request failed.';
    }

    /**
     * Replaces control characters with spaces and trims; an empty or oversized result is null.
     */
    private static function safeText(string $text, int $maxLength): ?string
    {
        $text = preg_replace(pattern: '/[\x00-\x1F\x7F]+/', replacement: ' ', subject: $text);
        $text = is_string($text) ? trim($text) : '';

        if ($text === '' || strlen($text) > $maxLength) {
            return null;
        }

        return $text;
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
