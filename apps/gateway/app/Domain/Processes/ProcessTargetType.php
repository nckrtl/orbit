<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\AppInstance;

enum ProcessTargetType: string
{
    case AppInstance = 'instance';

    /** @return class-string<AppInstance> */
    public function modelClass(): string
    {
        return AppInstance::class;
    }

    public static function fromModelClass(string $modelClass): self
    {
        return match ($modelClass) {
            AppInstance::class => self::AppInstance,
            default => throw new \InvalidArgumentException('Unsupported process target model.'),
        };
    }
}
