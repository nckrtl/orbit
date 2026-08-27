<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolStatus;
use App\Models\Tool;
use Illuminate\Support\Facades\DB;

trait MarksToolFailures
{
    protected function markToolFailure(
        ?Tool $tool,
        ToolOperation $operation,
        ToolOperationException $failure,
        ?string $installedVersion = null,
    ): void {
        if ($tool === null) {
            return;
        }

        $attributes = [
            'status' => ToolStatus::Failed,
            'failed_operation' => $operation,
            'error_code' => $failure->errorCode,
        ];

        if ($installedVersion !== null) {
            $attributes['installed_version'] = $installedVersion;
        }

        DB::transaction(static function () use ($tool, $attributes): void {
            if (! $tool->exists) {
                $tool->save();
            }

            $tool->update($attributes);
        });
    }
}
