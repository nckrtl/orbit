<?php

declare(strict_types=1);

namespace App\Domain\Compute;

/** One step of the nightly base template build (ADR 0204). Each scheduler tick advances at most one step. */
enum SandboxImageStep: string
{
    case CreateBuild = 'create_build';
    case AwaitBuild = 'await_build';
    case Install = 'install';
    case Warm = 'warm';
    case Clean = 'clean';
    case StopBuild = 'stop_build';
    case Templatize = 'templatize';
    case DeleteBuild = 'delete_build';
    case CreateSmoke = 'create_smoke';
    case AwaitSmoke = 'await_smoke';
    case DeleteSmoke = 'delete_smoke';
    case Publish = 'publish';
    case Cleanup = 'cleanup';
    case Done = 'done';

    /** Minutes the step may take before the build fails. Cleanup retries until it succeeds. */
    public function deadlineMinutes(): ?int
    {
        return match ($this) {
            self::AwaitBuild, self::AwaitSmoke => 20,
            self::Install => 30,
            self::Warm => 90,
            self::Templatize => 60,
            self::Cleanup, self::Done => null,
            default => 15,
        };
    }
}
