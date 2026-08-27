<?php

declare(strict_types=1);

namespace Orbit\Sdk;

use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayErrorCode;
use Orbit\Sdk\Support\GatewayRequestId;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class GatewayApiException extends RuntimeException
{
    private readonly ?string $errorCode;

    /** @var array<string, mixed> */
    private readonly array $details;

    private readonly ?string $requestId;

    /** @param array<string, mixed> $details */
    public function __construct(
        #[SensitiveParameter]
        string $message,
        #[SensitiveParameter]
        ?string $errorCode = null,
        #[SensitiveParameter]
        array $details = [],
        #[SensitiveParameter]
        ?Throwable $previous = null,
        #[SensitiveParameter]
        ?string $requestId = null,
    ) {
        $redactor = new CredentialRedactor;
        $this->errorCode = GatewayErrorCode::fromTransport($errorCode);
        $this->details = $redactor->redactArray($details);
        $this->requestId = GatewayRequestId::fromTransport($requestId);

        parent::__construct(
            message: $redactor->redactText($message),
            previous: $redactor->redactThrowable($previous),
        );
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function __toString(): string
    {
        $context = array_filter(
            [
                'error_code' => $this->errorCode,
                'request_id' => $this->requestId,
            ],
            static fn (?string $value): bool => $value !== null,
        );
        $suffix = $context === [] ? '' : ' '.(string) json_encode($context, JSON_UNESCAPED_SLASHES);

        return self::class.': '.$this->getMessage().$suffix;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'details' => $this->details,
            'request_id' => $this->requestId,
            'previous' => $this->getPrevious()?->getMessage(),
        ];
    }
}
