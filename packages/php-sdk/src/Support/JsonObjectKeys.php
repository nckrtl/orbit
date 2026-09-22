<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use SensitiveParameter;

/** @internal */
final class JsonObjectKeys
{
    /**
     * Requires a complete JSON document already validated by json_decode().
     * Returns false for duplicate object keys or a token-scanning failure.
     */
    public static function areUnique(#[SensitiveParameter] string $validJson): bool
    {
        $stack = [];
        $offset = 0;
        $length = strlen($validJson);
        while ($offset < $length) {
            $matched = preg_match('/"(?:[^"\\\\]++|\\\\.)*+"|[{}\[\]]/s', $validJson, $match, PREG_OFFSET_CAPTURE, $offset);
            if ($matched === false) {
                return false;
            }
            if ($matched === 0) {
                break;
            }
            [$token, $position] = $match[0];
            $offset = $position + strlen($token);
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (($validJson[$offset + strspn($validJson, " \t\r\n", $offset)] ?? null) === ':') {
                $key = json_decode($token, flags: JSON_THROW_ON_ERROR);
                $level = count($stack) - 1;
                if (isset($stack[$level][$key])) {
                    return false;
                }
                $stack[$level][$key] = true;
            }
        }

        return true;
    }
}
