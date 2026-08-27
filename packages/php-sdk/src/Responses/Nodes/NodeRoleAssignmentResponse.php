<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Assignment transport rejects each invalid field explicitly.
 * @mago-expect lint:excessive-parameter-list Stable assignment DTO fields are part of the public contract.
 */
final readonly class NodeRoleAssignmentResponse
{
    private const array STATUSES = ['active', 'failed', 'provisioning', 'removing'];

    private const int MAX_FAILED_STEP_LENGTH = 128;

    public function __construct(
        public int $id,
        public string $role,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        /** @mago-expect analysis:mixed-assignment Gateway assignment data is an untyped transport boundary. */
        $id = $data['id'] ?? null;

        if (! is_int($id) || $id < 1) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role assignment id.',
                requestId: $requestId,
            );
        }

        /** @mago-expect analysis:mixed-assignment Gateway assignment data is an untyped transport boundary. */
        $role = $data['role'] ?? null;

        if (! self::isSafeRole($role)) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role assignment role.',
                requestId: $requestId,
            );
        }

        /** @mago-expect analysis:mixed-assignment Gateway assignment data is an untyped transport boundary. */
        $status = $data['status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::STATUSES, strict: true)) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role assignment status.',
                requestId: $requestId,
            );
        }

        /** @mago-expect analysis:mixed-assignment Gateway assignment data is an untyped transport boundary. */
        $failedStep = $data['failed_step'] ?? null;

        if (! self::isSafeFailedStep($failedStep)) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role assignment failed_step.',
                requestId: $requestId,
            );
        }

        /** @var ?string $failedStep */

        /** @mago-expect analysis:mixed-assignment Gateway assignment data is an untyped transport boundary. */
        $rawErrorCode = $data['error_code'] ?? null;

        if (! is_string($rawErrorCode) && $rawErrorCode !== null) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role assignment error_code.',
                requestId: $requestId,
            );
        }

        /** @var string $role */
        /** @var string $status */
        return new self(
            id: $id,
            role: $role,
            status: $status,
            failedStep: $failedStep,
            errorCode: GatewayErrorCode::fromTransport($rawErrorCode),
            requestId: $requestId,
        );
    }

    /** @return array{id: int, role: string, status: string, failed_step: ?string, error_code: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
        ];
    }

    private static function isSafeRole(mixed $value): bool
    {
        return (
            is_string($value)
            && $value !== ''
            && strlen($value) <= 128
            && preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D', $value) === 1
        );
    }

    private static function isSafeFailedStep(mixed $value): bool
    {
        return (
            $value === null
            || is_string($value)
            && $value !== ''
            && strlen($value) <= self::MAX_FAILED_STEP_LENGTH
            && preg_match('/\A[a-z][a-z0-9]*(?::[a-z0-9]+(?:[._-][a-z0-9]+)*)?\z/D', $value) === 1
        );
    }
}
