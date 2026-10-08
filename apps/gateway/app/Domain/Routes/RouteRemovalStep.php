<?php

declare(strict_types=1);

namespace App\Domain\Routes;

enum RouteRemovalStep: string
{
    case Dns = 'dns';
    case Certificates = 'certificates';
    case Caddy = 'caddy';
    case Firewall = 'firewall';
    case Php = 'php';
    case Record = 'record';

    /**
     * A targeted removal records its steps with this prefix, so a retry can tell its own failure
     * from an untargeted removal that gained targets after it failed.
     */
    public const string TargetedPrefix = 'targeted:';

    public static function fromFailedStep(?string $step): ?self
    {
        if (! is_string($step) || $step === '') {
            return null;
        }

        if (str_starts_with($step, self::TargetedPrefix)) {
            return self::tryFrom(substr($step, strlen(self::TargetedPrefix)));
        }

        return self::tryFrom($step);
    }

    public static function isUntargetedFailure(?string $step): bool
    {
        return self::tryFrom((string) $step) instanceof self;
    }

    public function failedStep(bool $targeted): string
    {
        return $targeted ? self::TargetedPrefix.$this->value : $this->value;
    }
}
