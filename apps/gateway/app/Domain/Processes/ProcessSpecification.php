<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Data\Processes\AddProcessData;
use App\Models\Process;
use SensitiveParameter;

final readonly class ProcessSpecification
{
    /** @return array{runtime: ProcessRuntime, working_directory: string, runtime_config: array<string, mixed>, restart_policy: string} */
    public function attributes(#[SensitiveParameter] AddProcessData $data, ProcessTarget $target): array
    {
        $workingDirectory =
            $data->workingDirectory
            ?? ($data->runtime === ProcessRuntime::Systemd ? $target->defaultWorkingDirectory : '/app');
        $runtimeConfig = $data->runtime === ProcessRuntime::Systemd
            ? [
                'command' => $data->command,
                'environment_file' => $target->environmentFile,
            ]
            : [
                'image' => $data->image,
                'command' => $data->command,
                'environment' => $data->environment,
                'ports' => $data->ports,
                'volumes' => $data->volumes,
            ];

        return [
            'runtime' => $data->runtime,
            'working_directory' => $workingDirectory,
            'runtime_config' => $this->canonicalRuntimeConfig($data->runtime, $runtimeConfig),
            'restart_policy' => $data->restartPolicy,
        ];
    }

    /** @param array{runtime: ProcessRuntime, working_directory: string, runtime_config: array<string, mixed>, restart_policy: string} $attributes */
    public function matches(
        #[SensitiveParameter]
        Process $process,
        #[SensitiveParameter]
        array $attributes,
    ): bool {
        return
            $process->runtime === $attributes['runtime']
            && $process->working_directory === $attributes['working_directory']
            && $this->canonicalRuntimeConfig($process->runtime, $process->runtime_config)
            === $attributes['runtime_config']
            && $process->restart_policy === $attributes['restart_policy'];
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array<string, mixed>
     */
    private function canonicalRuntimeConfig(
        ProcessRuntime $runtime,
        #[SensitiveParameter]
        array $runtimeConfig,
    ): array {
        if ($runtime !== ProcessRuntime::Docker) {
            return $runtimeConfig;
        }

        $environment = $runtimeConfig['environment'] ?? [];

        if (! is_array($environment)) {
            return $runtimeConfig;
        }

        ksort($environment);
        $runtimeConfig['environment'] = $environment;

        return $runtimeConfig;
    }
}
