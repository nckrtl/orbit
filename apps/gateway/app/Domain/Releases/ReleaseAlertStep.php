<?php

declare(strict_types=1);

namespace App\Domain\Releases;

/**
 * The outcome of one part of an alert: the problem or the webhook. Reasons are fixed codes, never remote text.
 */
final readonly class ReleaseAlertStep
{
    public function __construct(
        public ReleaseAlertOutcome $outcome,
        public ?string $reason = null,
        public ?string $fingerprint = null,
        public ?int $httpStatus = null,
    ) {}

    public static function done(?string $fingerprint = null): self
    {
        return new self(ReleaseAlertOutcome::Done, fingerprint: $fingerprint);
    }

    public static function skipped(string $reason, ?string $fingerprint = null): self
    {
        return new self(ReleaseAlertOutcome::Skipped, $reason, $fingerprint);
    }

    public static function failed(string $reason, ?int $httpStatus = null): self
    {
        return new self(ReleaseAlertOutcome::Failed, $reason, httpStatus: $httpStatus);
    }

    public function failedStep(): bool
    {
        return $this->outcome === ReleaseAlertOutcome::Failed;
    }

    /** @return array{outcome: string, reason?: string, fingerprint?: string, http_status?: int} */
    public function toArray(): array
    {
        $step = ['outcome' => $this->outcome->value];

        if ($this->reason !== null) {
            $step['reason'] = $this->reason;
        }

        if ($this->fingerprint !== null) {
            $step['fingerprint'] = $this->fingerprint;
        }

        if ($this->httpStatus !== null) {
            $step['http_status'] = $this->httpStatus;
        }

        return $step;
    }
}
