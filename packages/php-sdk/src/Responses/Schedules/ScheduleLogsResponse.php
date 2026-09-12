<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Schedules;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ScheduleLogsResponse
{
    private const int MAX_NAME_LENGTH = 63;

    private const int MAX_OUTPUT_LENGTH = 1_048_576;

    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD';

    private function __construct(
        public string $id,
        public string $name,
        public int $lines,
        public string $output,
        public bool $truncated,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $id = $data['id'] ?? null;
        $name = self::boundedString($data['name'] ?? null, self::MAX_NAME_LENGTH);
        $lines = $data['lines'] ?? null;
        $output = self::boundedString($data['output'] ?? null, self::MAX_OUTPUT_LENGTH);
        $truncated = $data['truncated'] ?? null;

        if (
            ! is_string($id)
            || preg_match(self::UUID_PATTERN, $id) !== 1
            || $name === null
            || $name === ''
            || ! is_int($lines)
            || $lines < 1
            || $lines > 1_000
            || $output === null
            || ! is_bool($truncated)
        ) {
            throw new InvalidArgumentException('Invalid Schedule logs response.');
        }

        return new self(
            id: $id,
            name: $name,
            lines: $lines,
            output: $output,
            truncated: $truncated,
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array{id: string, name: string, lines: int, output: string, truncated: bool, request_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lines' => $this->lines,
            'output' => $this->output,
            'truncated' => $this->truncated,
            'request_id' => $this->requestId,
        ];
    }

    private static function boundedString(#[SensitiveParameter] mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $redacted = new CredentialRedactor()->redactText($value);

        return strlen($redacted) <= $maximum ? $redacted : null;
    }
}
