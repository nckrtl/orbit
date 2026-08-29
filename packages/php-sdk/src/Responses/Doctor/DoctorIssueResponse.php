<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Doctor;

use Orbit\Sdk\Support\CredentialRedactor;
use SensitiveParameter;

/** @mago-expect lint:cyclomatic-complexity The immutable wire DTO validates eight independent fields. */
final readonly class DoctorIssueResponse
{
    private const int MAX_STRING_LENGTH = 255;

    private const array FAMILIES = [
        'node',
        'role',
        'app',
        'instance',
        'workspace',
        'tool',
        'process',
        'firewall',
    ];

    private const array KINDS = ['drift', 'unverifiable'];

    /** @mago-expect lint:excessive-parameter-list The constructor mirrors the exact Gateway issue shape. */
    private function __construct(
        public string $code,
        public string $kind,
        public string $resourceType,
        public int|string|null $resourceId,
        public ?string $resourceName,
        public string $summary,
        public bool|string|null $expected,
        public bool|string|null $observed,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Gateway issue values remain mixed until validated.
     * @param array<array-key, mixed> $data
     */
    public static function fromGatewayData(#[SensitiveParameter] array $data): ?self
    {
        $code = self::requiredNonemptyString($data, 'code');
        $summary = self::requiredString($data, 'summary');
        $kind = $data['kind'] ?? null;
        $resourceType = $data['resource_type'] ?? null;

        if (
            $code === null
            || $summary === null
            || ! is_string($kind)
            || ! in_array($kind, self::KINDS, strict: true)
            || ! is_string($resourceType)
            || ! in_array($resourceType, self::FAMILIES, strict: true)
        ) {
            return null;
        }

        foreach (['resource_id', 'resource_name', 'expected', 'observed'] as $nullableKey) {
            if (! array_key_exists($nullableKey, $data)) {
                return null;
            }
        }

        $resourceId = self::nullableIntOrString($data, 'resource_id');
        $resourceName = self::nullableString($data, 'resource_name');
        $expectedResult = self::nullableBoolOrString($data, 'expected');
        $observedResult = self::nullableBoolOrString($data, 'observed');

        if (
            $resourceId === false
            || $resourceName === false
            || ! $expectedResult['valid']
            || ! $observedResult['valid']
        ) {
            return null;
        }

        return new self(
            code: $code,
            kind: $kind,
            resourceType: $resourceType,
            resourceId: $resourceId,
            resourceName: $resourceName,
            summary: $summary,
            expected: $expectedResult['value'],
            observed: $observedResult['value'],
        );
    }

    /** @return array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'kind' => $this->kind,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'resource_name' => $this->resourceName,
            'summary' => $this->summary,
            'expected' => $this->expected,
            'observed' => $this->observed,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private static function requiredString(array $data, string $key): ?string
    {
        if (! is_string($data[$key] ?? null)) {
            return null;
        }

        $value = new CredentialRedactor()->redactText($data[$key]);

        if (strlen($value) > self::MAX_STRING_LENGTH) {
            return null;
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function requiredNonemptyString(array $data, string $key): ?string
    {
        $value = self::requiredString($data, $key);

        return $value === '' ? null : $value;
    }

    /**
     * @mago-expect analysis:mixed-assignment A nullable issue value remains mixed until type validation.
     * @param array<array-key, mixed> $data
     */
    private static function nullableString(array $data, string $key): string|false|null
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        $redacted = new CredentialRedactor()->redactText($value);

        return strlen($redacted) <= self::MAX_STRING_LENGTH ? $redacted : false;
    }

    /**
     * @mago-expect analysis:mixed-assignment A nullable issue value remains mixed until type validation.
     * @param array<array-key, mixed> $data
     */
    private static function nullableIntOrString(array $data, string $key): int|string|false|null
    {
        $value = $data[$key] ?? null;
        if ($value === null || is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return false;
        }

        $redacted = new CredentialRedactor()->redactText($value);

        return strlen($redacted) <= self::MAX_STRING_LENGTH ? $redacted : false;
    }

    /**
     * @mago-expect analysis:mixed-assignment A nullable issue value remains mixed until type validation.
     * @param array<array-key, mixed> $data
     * @return array{valid:bool,value:bool|string|null}
     */
    private static function nullableBoolOrString(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if ($value === null || is_bool($value)) {
            return ['valid' => true, 'value' => $value];
        }

        if (! is_string($value)) {
            return ['valid' => false, 'value' => null];
        }

        $redacted = new CredentialRedactor()->redactText($value);

        return (
            strlen($redacted) <= self::MAX_STRING_LENGTH
                ? ['valid' => true, 'value' => $redacted]
                : ['valid' => false, 'value' => null]
        );
    }
}
