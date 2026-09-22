<?php

declare(strict_types=1);

use Orbit\Sdk\Support\JsonObjectKeys;

describe('JSON object key uniqueness', function (): void {
    it('allows repeated names in distinct objects and ignores string contents', function (string $json): void {
        json_decode($json, flags: JSON_THROW_ON_ERROR);

        expect(JsonObjectKeys::areUnique($json))->toBeTrue();
    })->with([
        'scalar' => ['null'],
        'empty object' => ['{}'],
        'empty array' => ['[]'],
        'nested objects' => ['{"id":1,"nested":{"id":2}}'],
        'array siblings' => ['[{"id":1},{"id":2}]'],
        'objects inside nested arrays' => ['{"items":[[{"id":1}],{"id":2}],"id":3}'],
        'key and string value' => ['{"value":"id","id":1}'],
        'JSON inside a string' => ['{"text":"{\\"id\\":1,\\"id\\":2}","brackets":"[{}]:"}'],
        'escaped backslash and quote' => ['{"text":"\\\\\\"","id":1}'],
        'distinct numeric names' => ['{"1":1,"01":2,"-0":3,"0":4}'],
        'case-sensitive names' => ['{"id":1,"ID":2}'],
        'empty names in separate objects' => ['[{"":1},{"":2}]'],
    ]);

    it('rejects equivalent keys in the same object at any depth', function (string $json): void {
        json_decode($json, flags: JSON_THROW_ON_ERROR);

        expect(JsonObjectKeys::areUnique($json))->toBeFalse();
    })->with([
        'root' => ['{"id":1,"id":2}'],
        'escaped equivalent' => ['{"instance_id":1,"instance_\u0069d":2}'],
        'nested object' => ['{"nested":{"id":1,"id":2}}'],
        'array object' => ['{"items":[{"id":1,"id":2}]}'],
        'nested arrays' => ['[[{"id":1,"id":2}]]'],
        'parent after child' => ['{"id":1,"nested":{"id":2},"id":3}'],
        'second sibling' => ['[{"id":1},{"id":2,"id":3}]'],
        'empty name' => ['{"":1,"":2}'],
        'escaped quote' => ['{"a\\"b":1,"a\u0022b":2}'],
        'Unicode escape' => ['{"café":1,"caf\u00e9":2}'],
        'Unicode surrogate pair' => ['{"😀":1,"\ud83d\ude00":2}'],
        'whitespace before colon' => ["{\"id\" \t\r\n:1,\"id\":2}"],
    ]);
});
