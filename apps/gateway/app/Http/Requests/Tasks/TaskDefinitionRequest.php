<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskDefinitionName;
use App\Domain\Tasks\TaskDeliverableType;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Rules\CommandPaths;
use App\Rules\DistinctDeliverableIds;
use App\Rules\FailsOnBase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class TaskDefinitionRequest extends FormRequest
{
    /** @var list<string> */
    private const array Keys = [
        'name',
        'title',
        'brief',
        'parameters',
        'status',
        'schedule',
        'phases',
        'subtasks',
    ];

    /** @var array<string, mixed>|null */
    private ?array $inspected = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $slug = 'regex:/\A'.TaskDefinitionName::Pattern.'\z/';

        return [
            'name' => ['required', 'string', 'max:'.TaskDefinitionName::MaxLength, $slug],
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:8000'],
            'parameters' => ['present', 'array', 'list'],
            'parameters.*' => ['required', 'array:name,type,required,default'],
            'parameters.*.name' => ['required', 'string', 'max:'.TaskDefinitionName::MaxLength, $slug],
            'parameters.*.type' => ['required', 'string', Rule::in(['text', 'app', 'subtasks'])],
            'parameters.*.required' => ['required', 'boolean:strict'],
            'parameters.*.default' => ['sometimes'],
            'status' => ['required', 'string', Rule::in(['backlog', 'todo'])],
            'schedule' => ['sometimes', 'nullable', 'array:cron,values'],
            'schedule.cron' => ['sometimes', 'string', 'max:100'],
            'schedule.values' => ['sometimes', 'array'],
            'phases' => ['sometimes', 'array', 'list'],
            'phases.*' => ['required', 'array:key,title,brief,repeat'],
            'phases.*.key' => ['required', 'string', 'max:'.TaskDefinitionName::MaxLength, $slug],
            'phases.*.title' => ['required', 'string', 'max:160'],
            'phases.*.brief' => ['required', 'string', 'max:8000'],
            'phases.*.repeat' => ['required', 'boolean:strict'],
            'subtasks' => ['required', 'array', 'list', 'min:1'],
            'subtasks.*' => ['required', 'array'],
            'subtasks.*.key' => ['required', 'string', 'max:'.TaskDefinitionName::MaxLength, $slug],
            'subtasks.*.title' => ['required', 'string', 'max:160'],
            'subtasks.*.kind' => ['required', 'string', 'max:32'],
            'subtasks.*.brief' => ['sometimes', 'string', 'max:8000'],
            'subtasks.*.phase' => ['sometimes', 'string', 'max:'.TaskDefinitionName::MaxLength],
            'subtasks.*.deliverables' => ['sometimes', 'array', 'list', 'max:5', new DistinctDeliverableIds],
            'subtasks.*.deliverables.*' => ['required', 'array:id,type,description,path,change,command,directory,fails_on_base,paths'],
            'subtasks.*.deliverables.*.id' => ['required', 'string', 'max:64', $slug],
            'subtasks.*.deliverables.*.type' => ['required', 'string', Rule::enum(TaskDeliverableType::class)],
            'subtasks.*.deliverables.*.description' => ['required', 'string', 'max:500'],
            'subtasks.*.deliverables.*.path' => ['required_if:subtasks.*.deliverables.*.type,file', 'prohibited_unless:subtasks.*.deliverables.*.type,file', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'subtasks.*.deliverables.*.change' => ['required_if:subtasks.*.deliverables.*.type,file', 'prohibited_unless:subtasks.*.deliverables.*.type,file', 'string', Rule::in(['created', 'modified', 'any'])],
            'subtasks.*.deliverables.*.fails_on_base' => ['sometimes', new FailsOnBase, 'boolean:strict'],
            'subtasks.*.deliverables.*.command' => ['required_if:subtasks.*.deliverables.*.type,command', 'prohibited_unless:subtasks.*.deliverables.*.type,command', 'string', 'max:1000'],
            'subtasks.*.deliverables.*.directory' => ['sometimes', 'prohibited_unless:subtasks.*.deliverables.*.type,command', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'subtasks.*.deliverables.*.paths' => [new CommandPaths, 'prohibited_unless:subtasks.*.deliverables.*.type,command', 'array', 'list', 'max:100'],
            'subtasks.*.deliverables.*.paths.*' => ['required', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'subtasks.*.routes' => ['sometimes', 'array'],
            'subtasks.*.implementer_model' => ['sometimes', 'string', 'max:200'],
            'subtasks.*.reviewer_model' => ['sometimes', 'string', 'max:200'],
            'subtasks.*.operation' => ['sometimes', 'string', 'max:200'],
            'subtasks.*.arguments' => ['sometimes', 'array'],
            'subtasks.*.question' => ['sometimes', 'string', 'max:8000'],
            'subtasks.*.options' => ['sometimes', 'array', 'list'],
            'subtasks.*.options.*' => ['required', 'string', 'max:'.TaskDefinitionName::MaxLength, $slug],
            'subtasks.*.evidence' => ['sometimes', 'array', 'list'],
            'subtasks.*.evidence.*' => ['required', 'string', 'max:'.TaskDefinitionName::MaxLength, $slug],
            'subtasks.*.min_probability' => ['sometimes', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $pathName = $this->route('name');

        if (! is_string($pathName)) {
            return;
        }

        $validator->after(function (Validator $validator) use ($pathName): void {
            if (($this->definition()['name'] ?? null) !== $pathName) {
                $validator->errors()->add('name', 'The definition name must match the name in the path.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->definition();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        if ($this->inspected !== null) {
            return $this->inspected;
        }

        try {
            $payload = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), self::Keys);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        // MCP removes the path argument from the body. An update then has no name unless the path supplies it.
        $pathName = $this->route('name');

        if (is_string($pathName) && ! array_key_exists('name', $payload)) {
            $payload['name'] = $pathName;
        }

        return $this->inspected = $payload;
    }
}
