<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use LogicException;
use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class AppDevSetupScriptResponse
{
    private const int MAX_SCRIPT_BYTES = 262_144;

    private const int MAX_SUMMARY_BYTES = 4_096;

    public function __construct(
        public string $role,
        public string $summary,
        #[SensitiveParameter]
        private string $script,
        public string $requestId,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Gateway script values remain mixed until validated.
     *
     * @param array<string, mixed> $data
     */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $role = $data['role'] ?? null;
        $summary = $data['summary'] ?? null;
        $script = $data['script'] ?? null;

        if (
            $role !== 'app-dev'
            || ! is_string($summary)
            || ! self::isSafeSummary($summary)
            || ! is_string($script)
            || $script === ''
            || strlen($script) > self::MAX_SCRIPT_BYTES
        ) {
            throw new GatewayApiException('Gateway response contains an invalid app-dev setup script.');
        }

        return new self($role, $summary, $script, $requestId);
    }

    public function script(): string
    {
        return $this->script;
    }

    /** @return array{type: class-string<self>} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Orbit app-dev setup script responses cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit app-dev setup script responses cannot be unserialized.');
    }

    private static function isSafeSummary(#[SensitiveParameter] string $summary): bool
    {
        return (
            strlen($summary) <= self::MAX_SUMMARY_BYTES
            && preg_match('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', $summary) === 0
        );
    }
}
