<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class DevelopmentDeployStep
{
    public const int DefaultTimeoutSeconds = 300;

    public const int MaxTimeoutSeconds = 900;

    public const int MaxTotalTimeoutSeconds = 3600;

    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $command,
        public int $timeoutSeconds = self::DefaultTimeoutSeconds,
        public bool $required = true,
    ) {
        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $name) !== 1) {
            throw new InvalidArgumentException('The development deploy step name is invalid.');
        }

        if ($command === '' || strlen($command) > 16 * 1024 || str_contains($command, "\0")) {
            throw new InvalidArgumentException('The development deploy step command is invalid.');
        }

        if (preg_match('//u', $command) !== 1) {
            throw new InvalidArgumentException('The development deploy step command is invalid.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MaxTimeoutSeconds) {
            throw new InvalidArgumentException('The development deploy step timeout is invalid.');
        }
    }

    /** @return array{name: string, command: string, timeout_seconds: int, required: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command,
            'timeout_seconds' => $this->timeoutSeconds,
            'required' => $this->required,
        ];
    }
}
