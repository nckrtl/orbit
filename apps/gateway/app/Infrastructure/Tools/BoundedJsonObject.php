<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use JsonException;
use stdClass;

/**
 * Decodes one JSON object with a byte cap, a nesting cap, and no trailing value.
 * A truncated or concatenated inventory must not decode as a shorter document.
 */
final readonly class BoundedJsonObject
{
    public function __construct(
        private int $maxBytes,
        private int $maxDepth,
    ) {}

    public function decode(string $json): stdClass
    {
        if ($this->maxBytes < 1 || $this->maxDepth < 1 || strlen($json) > $this->maxBytes) {
            throw new JsonException('The JSON document exceeds its bound.');
        }

        $this->assertSingleValue($json);

        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR, depth: $this->maxDepth);
        } catch (JsonException $exception) {
            throw new JsonException('The JSON document was malformed.', previous: $exception);
        }

        if (! $decoded instanceof stdClass) {
            throw new JsonException('The JSON document was not an object.');
        }

        return $decoded;
    }

    /**
     * @return list<mixed>
     */
    public function decodeList(string $json): array
    {
        if ($this->maxBytes < 1 || $this->maxDepth < 1 || strlen($json) > $this->maxBytes) {
            throw new JsonException('The JSON document exceeds its bound.');
        }

        $this->assertSingleValue($json);

        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR, depth: $this->maxDepth);
        } catch (JsonException $exception) {
            throw new JsonException('The JSON document was malformed.', previous: $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new JsonException('The JSON document was not an array.');
        }

        return $decoded;
    }

    private function assertSingleValue(string $json): void
    {
        $length = strlen($json);
        $index = 0;

        while ($index < $length && ctype_space($json[$index])) {
            $index++;
        }

        if ($index >= $length || ($json[$index] !== '{' && $json[$index] !== '[')) {
            throw new JsonException('The JSON document was malformed.');
        }

        $depth = 0;
        $inString = false;
        $escape = false;

        for ($cursor = $index; $cursor < $length; $cursor++) {
            $character = $json[$cursor];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }

                if ($character === '\\') {
                    $escape = true;

                    continue;
                }

                if ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;

                continue;
            }

            if ($character === '{' || $character === '[') {
                $depth++;

                if ($depth > $this->maxDepth) {
                    throw new JsonException('The JSON document exceeds its bound.');
                }

                continue;
            }

            if ($character !== '}' && $character !== ']') {
                continue;
            }

            $depth--;

            if ($depth < 0) {
                throw new JsonException('The JSON document was malformed.');
            }

            if ($depth === 0) {
                $rest = substr($json, $cursor + 1);

                if (preg_match('/\S/', $rest) === 1) {
                    throw new JsonException('The JSON document was malformed.');
                }

                return;
            }
        }

        throw new JsonException('The JSON document was malformed.');
    }
}
