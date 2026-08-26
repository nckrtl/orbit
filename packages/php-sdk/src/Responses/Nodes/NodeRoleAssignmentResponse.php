<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway assignment fields are validated independently.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class NodeRoleAssignmentResponse
{
    /** @var list<string> */
    private const array STATUSES = ['provisioning', 'active', 'failed'];

    public function __construct(
        public string $role,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public bool $localActionRequired,
        public ?string $localCommand,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Gateway assignment values remain mixed until validated.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        $role = $data['role'] ?? null;
        $status = $data['status'] ?? null;
        $failedStep = $data['failed_step'] ?? null;
        $errorCode = $data['error_code'] ?? null;
        $localActionRequired = $data['local_action_required'] ?? null;
        $localCommand = $data['local_command'] ?? null;

        if (
            ! is_string($role)
            || ! self::isSafeRole($role)
            || ! is_string($status)
            || ! in_array(needle: $status, haystack: self::STATUSES, strict: true)
        ) {
            self::invalid();
        }

        if (
            $failedStep !== null
            && (! is_string($failedStep)
            || GatewayErrorCode::fromTransport($failedStep) === null)
        ) {
            self::invalid();
        }

        if ($errorCode !== null && ! is_string($errorCode)) {
            self::invalid();
        }

        if (! is_bool($localActionRequired) || $localCommand !== null && ! is_string($localCommand)) {
            self::invalid();
        }

        return new self(
            role: $role,
            status: $status,
            failedStep: $failedStep,
            errorCode: GatewayErrorCode::fromTransport($errorCode),
            localActionRequired: $localActionRequired,
            localCommand: $localCommand === 'orbit node:setup app-dev' ? $localCommand : null,
        );
    }

    /** @return array{role: string, status: string, failed_step: ?string, error_code: ?string, local_action_required: bool, local_command: ?string} */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'local_action_required' => $this->localActionRequired,
            'local_command' => $this->localCommand,
        ];
    }

    private static function isSafeRole(#[SensitiveParameter] string $role): bool
    {
        return $role !== '' && strlen($role) <= 64 && preg_match('/[\x00-\x1F\x7F]/', $role) !== 1;
    }

    private static function invalid(): never
    {
        throw new GatewayApiException('Gateway response contains an invalid node role assignment.');
    }
}
