<?php

declare(strict_types=1);

namespace App\Data\Processes;

use App\Domain\Processes\ProcessTargetType;
use App\Models\Process;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ProcessData extends Data
{
    /** @param array<string, mixed> $runtimeConfig */
    public function __construct(
        public int $id,
        public string $targetType,
        public int $targetId,
        public string $name,
        public string $runtime,
        public string $workingDirectory,
        public array $runtimeConfig,
        public string $restartPolicy,
        public bool $keepAlive,
        public string $desiredState,
        public string $status,
        public string $runtimeStatus,
        public ?string $failedStep,
        public ?string $errorCode,
        /** Ratio of one core (0..1, matching `NodeMetricsResponse::$cores`), null when unavailable. */
        public ?float $cpu = null,
        /** Resident memory in bytes, null when unavailable — never zero for "not running". */
        public ?int $memoryBytes = null,
    ) {}

    public static function fromModel(
        #[SensitiveParameter] Process $process,
        string $runtimeStatus,
        ?float $cpu = null,
        ?int $memoryBytes = null,
    ): self {
        /** @var ?string $failedStep */
        $failedStep = $process->getAttribute('failed_step');
        /** @var ?string $errorCode */
        $errorCode = $process->getAttribute('error_code');

        return new self(
            id: $process->id,
            targetType: ProcessTargetType::fromModelClass($process->owner_type)->value,
            targetId: $process->owner_id,
            name: $process->name,
            runtime: $process->runtime->value,
            workingDirectory: $process->working_directory,
            runtimeConfig: self::redactedRuntimeConfig($process),
            restartPolicy: $process->restart_policy,
            keepAlive: $process->keep_alive,
            desiredState: $process->desired_state->value,
            status: $process->status->value,
            runtimeStatus: $runtimeStatus,
            failedStep: $failedStep,
            errorCode: $errorCode,
            cpu: $cpu,
            memoryBytes: $memoryBytes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function redactedRuntimeConfig(#[SensitiveParameter] Process $process): array
    {
        $runtimeConfig = $process->runtime_config;
        $environment = $runtimeConfig['environment'] ?? null;

        if (! is_array($environment)) {
            return $runtimeConfig;
        }

        $runtimeConfig['environment'] = array_fill_keys(
            keys: array_keys($environment),
            value: '[REDACTED]',
        );

        return $runtimeConfig;
    }
}
