<?php

declare(strict_types=1);

namespace App\Http\Requests\AppDefinitions;

use App\Data\AppDefinitions\AppDefinitionInputData;
use App\Data\Schedules\AddScheduleData;
use App\Domain\AppDefinitions\DefinitionEnvironment;
use App\Domain\Schedules\ScheduleSpecificationValidator;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class ScheduleDefinitionRequest extends FormRequest
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
            'spec.command' => ['required', 'string', 'max:4096', 'not_regex:/[\x00\r\n]/'],
            'spec.calendar' => ['required', 'string', 'max:255', 'regex:/\A[\x20-\x7E]+\z/D'],
            'spec.timeout_seconds' => ['required', 'integer', 'min:1', 'max:86400'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(AppDefinitionJsonInspector::class)->inspectSchedule($this->getContent());
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $name = $this->input('name');
            $command = $this->input('spec.command');
            $calendar = $this->input('spec.calendar');
            $timeout = $this->input('spec.timeout_seconds');

            if (! is_string($name) || ! is_string($command) || ! is_string($calendar) || ! is_int($timeout)) {
                return;
            }

            try {
                app(ScheduleSpecificationValidator::class)->validate(new AddScheduleData(
                    targetType: ScheduleTargetType::AppInstance,
                    targetId: 1,
                    name: $name,
                    calendar: $calendar,
                    command: $command,
                    timeoutSeconds: $timeout,
                    start: true,
                ));
            } catch (ResourceOperationException $exception) {
                $validator->errors()->add('spec', $exception->getMessage());
            }
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
}
