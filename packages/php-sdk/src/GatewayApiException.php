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
    /** @var list<string> */
    private const array LOCAL_ACTION_CHECKS = [
        'remote-login',
        'pf-anchor',
        'resolver',
        'dnsmasq',
        'root-ca-trust',
    ];

    /** @var list<string> */
    private const array VERIFICATION_CHECKS = [
        'ssh-host-key',
        'identity',
        'architecture',
        'restricted-key',
        'homebrew',
        'toolchain',
        'caddy',
        'php-fpm',
    ];

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
        $this->details = $redactor->redactArray($this->normalizeDetails($this->errorCode, $details));
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

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function normalizeDetails(#[SensitiveParameter] ?string $errorCode, array $details): array
    {
        return match ($errorCode) {
            'macos.setup_failed' => $this->singleAllowedDetail(
                $details,
                'failed_step',
                ['local-setup'],
            ),
            'node.role_setup_not_ready' => $this->singleAllowedDetail(
                $details,
                'failed_step',
                ['wireguard-projection', 'private-dns'],
            ),
            'macos.verification_failed' => $this->singleAllowedDetail(
                $details,
                'check',
                self::VERIFICATION_CHECKS,
            ),
            'macos.local_action_required' => $this->localActionDetails($details),
            'macos.user_session_unavailable' => $this->singleAllowedDetail(
                $details,
                'runtime',
                ['launchd'],
            ),
            'node.unreachable' => [],
            default => $details,
        };
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway detail values remain mixed until validated.
     *
     * @param array<string, mixed> $details
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private function singleAllowedDetail(
        #[SensitiveParameter]
        array $details,
        string $key,
        array $allowed,
    ): array {
        $value = $details[$key] ?? null;

        if (! is_string($value) || ! in_array(needle: $value, haystack: $allowed, strict: true)) {
            return [];
        }

        return [$key => $value];
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway local-action details remain mixed until validated.
     *
     * @param array<string, mixed> $details
     * @return array{check: string, local_command: ?string}|array<never, never>
     */
    private function localActionDetails(#[SensitiveParameter] array $details): array
    {
        $check = $details['check'] ?? null;

        if (! is_string($check) || ! in_array(needle: $check, haystack: self::LOCAL_ACTION_CHECKS, strict: true)) {
            return [];
        }

        $command = $details['local_command'] ?? null;
        $localCommand =
            $check === 'root-ca-trust' && $command === 'orbit gateway:trust'
                ? $command
                : null;

        return [
            'check' => $check,
            'local_command' => $localCommand,
        ];
    }
}
