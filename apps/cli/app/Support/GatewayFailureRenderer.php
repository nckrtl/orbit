<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Console\ConsoleMode;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\HumanRenderer;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Input\ArrayInput;

final class GatewayFailureRenderer
{
    private const int MAX_FIELD_MESSAGES = 50;

    private const int MAX_FIELD_LENGTH = 128;

    private const int MAX_MESSAGE_LENGTH = 512;

    /** Caddy's message in a Node Caddy build failure, which the Gateway bounds at 2,000 bytes. A longer one is cut, not dropped. */
    private const int MAX_BUILD_MESSAGE_LENGTH = 2000;

    /** The fields a failed Node Caddy build adds to any error it causes: the Node, the failed stage, and Caddy's message. */
    private const array BUILD_FIELDS = ['node', 'stage', 'message'];

    /** A step name, such as `remove:host-firewall`, `converge:caddy`, or `tool-manager-vp`. */
    private const string STEP_PATTERN = '/\A[a-z0-9](?:[a-z0-9:._-]{0,126}[a-z0-9])?\z/D';

    /**
     * The bounded operation fields any error may carry, each with the pattern its value must match.
     * They name what failed and why in closed tokens, never remote output or a raw value.
     */
    private const array OPERATION_FIELDS = [
        'step' => self::STEP_PATTERN,
        'teardown_step' => self::STEP_PATTERN,
        'outcome' => '/\A[a-z][a-z0-9_]{0,63}\z/D',
        'reason' => '/\A[a-z][a-z0-9_]{0,63}\z/D',
        'cleanup' => '/\A[a-z][a-z0-9_]{0,63}\z/D',
        'role' => '/\A[a-z][a-z0-9-]{0,63}\z/D',
        'field' => '/\A[a-z][a-z0-9_.-]{0,63}\z/D',
    ];

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    public static function safeDetails(string $code, array $details): array
    {
        if ($code === 'validation.failed') {
            return self::fieldDetails($details);
        }

        if ($code === 'env.configuration_invalid') {
            return self::environmentConfigurationDetails($details);
        }

        if (in_array($code, ['instance.setup_step_failed', 'instance.teardown_step_failed'], true)) {
            $safe = [];
            foreach (['step', 'teardown_step'] as $field) {
                $value = $details[$field] ?? null;
                if (is_string($value) && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $value) === 1) {
                    $safe[$field] = $value;
                }
            }
            foreach (['outcome' => ['failed', 'unconfirmed'], 'cleanup' => ['incomplete', 'unconfirmed']] as $field => $allowed) {
                if (in_array($details[$field] ?? null, $allowed, true)) {
                    $safe[$field] = $details[$field];
                }
            }

            return $safe;
        }

        $safe = [];
        $id = $details['id'] ?? null;

        if (is_int($id) && $id > 0) {
            $safe['id'] = $id;
        }

        return [...$safe, ...self::operationDetails($details), ...self::buildDetails($details)];
    }

    /**
     * Keeps the operation fields whose values match their closed patterns, such as the failed `step` of a role operation
     * or the `outcome` of a tool operation. A malformed value drops.
     *
     * @param  array<string,mixed>  $details
     * @return array<string,string>
     */
    public static function operationDetails(array $details): array
    {
        $safe = [];

        foreach (self::OPERATION_FIELDS as $field => $pattern) {
            $value = $details[$field] ?? null;

            if (is_string($value) && preg_match($pattern, $value) === 1) {
                $safe[$field] = $value;
            }
        }

        return $safe;
    }

    /**
     * Keeps the Node, stage, and Caddy message of a failed Node Caddy build, and the step that requested it.
     * They come as a set; a partial or malformed set drops.
     *
     * @param  array<string,mixed>  $details
     * @return array<string,string>
     */
    public static function buildDetails(array $details): array
    {
        $node = $details['node'] ?? null;
        $stage = $details['stage'] ?? null;
        $message = is_string($details['message'] ?? null) ? self::truncatedText($details['message'], self::MAX_BUILD_MESSAGE_LENGTH) : null;

        if (
            ! is_string($node)
            || preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62})\z/D', $node) !== 1
            || ! is_string($stage)
            || preg_match('/\A[a-z][a-z-]{0,31}\z/D', $stage) !== 1
            || $message === null
        ) {
            return [];
        }

        $safe = [];
        $step = $details['step'] ?? null;

        if (is_string($step) && preg_match(self::STEP_PATTERN, $step) === 1) {
            $safe['step'] = $step;
        }

        return [...$safe, 'node' => $node, 'stage' => $stage, 'message' => $message];
    }

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
            ConsoleWriter::write($command->getOutput(), self::json($code, $message, $requestId, $details)."\n");

            return;
        }

        $humanDetails = $details;

        // The error message already names the Node, stage, and Caddy message of a failed build.
        if (self::buildDetails($details) !== []) {
            foreach ([...self::BUILD_FIELDS, 'step'] as $field) {
                unset($humanDetails[$field]);
            }
        }

        $id = $humanDetails['id'] ?? null;

        if (is_int($id) && $id > 0) {
            $humanDetails['id'] = (string) $id;
        }

        $output = $command->getOutput();
        $renderer = new HumanRenderer(ConsoleMode::detect(new ArrayInput([]), $output));
        ConsoleWriter::write($output, $renderer->failure(
            self::safeErrorMessage($humanMessage ?? $message),
            self::fieldDetails($humanDetails),
            $requestId,
        ));
    }

    /**
     * Keeps only redacted environment-configuration fields: the invalid key, a closed
     * rule token, and a well-formed leftover placeholder. Values and other members drop.
     *
     * @param  array<array-key,mixed>  $details
     * @return array<string,string|list<string>>
     */
    public static function environmentConfigurationDetails(array $details): array
    {
        $safe = [];

        if (isset($details['key']) && is_string($details['key'])) {
            $safe['key'] = $details['key'];
        }

        if (
            isset($details['rule'])
            && is_string($details['rule'])
            && preg_match('/\A[a-z][a-z0-9_]*\z/D', $details['rule']) === 1
        ) {
            $safe['rule'] = $details['rule'];
        }

        if (
            isset($details['placeholder'])
            && is_string($details['placeholder'])
            && preg_match('/\A\{\{[A-Za-z0-9_.]+\}\}\z/D', $details['placeholder']) === 1
        ) {
            $safe['placeholder'] = $details['placeholder'];
        }

        return self::fieldDetails($safe);
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

    /**
     * Like safeText, but cuts an oversized text on a UTF-8 character boundary and marks the cut, instead of dropping it.
     */
    private static function truncatedText(string $text, int $maxLength): ?string
    {
        $text = preg_replace(pattern: '/[\x00-\x1F\x7F]+/', replacement: ' ', subject: mb_scrub($text, 'UTF-8'));
        $text = is_string($text) ? trim($text) : '';

        if ($text === '') {
            return null;
        }

        if (strlen($text) > $maxLength) {
            $text = mb_strcut($text, 0, $maxLength - strlen('…'), 'UTF-8').'…';
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
