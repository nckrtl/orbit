<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Models\Activity;
use App\Models\SandboxImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Raises a failed base template build: a failed Activity entry, which the problem collector records
 * for the outer loop, and an error log line. It never throws, so a failure path can always call it.
 */
final readonly class SandboxImageAlerts
{
    public const string Command = 'orbit:sandbox-image-build';

    public function failed(SandboxImage $image, string $summary): void
    {
        $details = ['sandbox_image_id' => $image->id, 'step' => $image->failed_step, 'error_code' => $image->error_code, 'summary' => $summary];
        try {
            Log::error('The UpCloud sandbox base template build failed.', $details);
        } catch (Throwable) {
        }
        try {
            Activity::query()->create([
                'log_name' => 'compute', 'description' => 'sandbox_image_build_failed', 'properties' => $details,
                'request_id' => (string) Str::uuid(), 'command' => self::Command, 'status' => 'failed',
                'error_code' => 'compute.image_build_failed',
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
