<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDefinitions;

use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Domain\AppDefinitions\DefinitionEnvironment;
use App\Domain\Processes\ProcessRuntime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class ProcessDefinitionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D',
            ],
            'environments' => ['required', 'array', 'list', 'min:1', 'max:2'],
            'environments.*' => ['required', 'string', 'distinct:strict', Rule::enum(DefinitionEnvironment::class)],
            'spec' => ['required', 'array'],
            'spec.runtime' => ['required', Rule::enum(ProcessRuntime::class)],
            'spec.command' => ['required', 'array', 'list', 'min:1', 'max:64'],
            'spec.command.*' => ['string', 'max:4096', 'not_regex:/[\x00\r\n]/'],
            'spec.image' => [
                'required_if:spec.runtime,docker',
                'prohibited_unless:spec.runtime,docker',
                'nullable',
                'string',
                'max:255',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/:@-]*\z/D',
                'not_regex:/[\s\x00-\x1F]/',
            ],
            'spec.working_directory' => [
                'sometimes',
                'string',
                'max:4096',
                'regex:/\A\/(?!.*(?:^|\/)\.\.(?:\/|$))[^\x00\r\n]*\z/D',
            ],
            'spec.environment' => ['sometimes', 'prohibited_unless:spec.runtime,docker', 'array', 'max:100'],
            'spec.environment.*' => ['string', 'max:4096', 'not_regex:/[\x00\r\n]/'],
            'spec.ports' => ['sometimes', 'prohibited_unless:spec.runtime,docker', 'array', 'list', 'max:100'],
            'spec.ports.*' => [
                'string',
                'regex:/\A(?:(?:\d{1,3}\.){3}\d{1,3}:)?\d{1,5}:\d{1,5}(?:\/(?:tcp|udp))?\z/D',
            ],
            'spec.volumes' => ['sometimes', 'prohibited_unless:spec.runtime,docker', 'array', 'list', 'max:100'],
            'spec.volumes.*' => ['array:source,target,read_only'],
            'spec.volumes.*.source' => [
                'required',
                'string',
                'max:4096',
                'regex:/\A(?:\/[A-Za-z0-9._\/ -]+|[A-Za-z0-9][A-Za-z0-9_.-]*)\z/D',
            ],
            'spec.volumes.*.target' => [
                'required',
                'string',
                'max:4096',
                'regex:/\A\/[A-Za-z0-9._\/ -]*\z/D',
            ],
            'spec.volumes.*.read_only' => ['sometimes', 'boolean'],
            'spec.restart_policy' => [
                'sometimes',
                Rule::in(['never', 'on-failure', 'always', 'unless-stopped']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(AppDefinitionJsonInspector::class)->inspectProcess($this->getContent());
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();

            $this->validateSystemdExecutable($validator, $data);
            $this->validateEnvironmentNames($validator, $data);
            $this->validatePorts($validator, $data);
        }];
    }

    public function payload(): AppDefinitionInputData
    {
        /** @var array{name: string, environments: list<string>, spec: array<string, mixed>} $validated */
        $validated = $this->validated();

        return new AppDefinitionInputData(
            name: $validated['name'],
            environments: $validated['environments'],
            spec: $validated['spec'],
        );
    }

    /** @param array<string, mixed> $data */
    private function validateSystemdExecutable(Validator $validator, array $data): void
    {
        if (data_get($data, 'spec.runtime') !== ProcessRuntime::Systemd->value) {
            return;
        }

        $executable = data_get($data, 'spec.command.0');

        if (is_string($executable) && str_starts_with($executable, '/')) {
            return;
        }

        $validator->errors()->add('spec.command.0', 'The systemd executable must be an absolute path.');
    }

    /** @param array<string, mixed> $data */
    private function validateEnvironmentNames(Validator $validator, array $data): void
    {
        $environment = data_get($data, 'spec.environment');

        if (! is_array($environment)) {
            return;
        }

        foreach (array_keys($environment) as $name) {
            if (is_string($name) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1) {
                continue;
            }

            $validator->errors()->add('spec.environment', 'The environment contains an invalid variable name.');

            return;
        }
    }

    /** @param array<string, mixed> $data */
    private function validatePorts(Validator $validator, array $data): void
    {
        $ports = data_get($data, 'spec.ports');

        if (! is_array($ports)) {
            return;
        }

        foreach ($ports as $index => $port) {
            if (! is_string($port)) {
                continue;
            }

            $segments = explode(':', explode('/', $port, limit: 2)[0]);
            $numericPorts = array_slice($segments, -2);
            $valid = count($numericPorts) === 2;

            foreach ($numericPorts as $numericPort) {
                $number = filter_var($numericPort, FILTER_VALIDATE_INT);
                $valid = $valid && is_int($number) && $number >= 1 && $number <= 65_535;
            }

            if (! $valid) {
                $validator->errors()->add("spec.ports.{$index}", 'Published ports must be between 1 and 65535.');
            }
        }
    }
}
