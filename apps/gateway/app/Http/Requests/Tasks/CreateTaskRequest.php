<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\CreateTaskData;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableType;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Rules\DistinctDeliverableIds;
use App\Rules\ExactPestTestFile;
use App\Rules\FailsOnBase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class CreateTaskRequest extends FormRequest
{
    /** @return array<string, list<string|Enum|In|DistinctDeliverableIds|ExactPestTestFile|FailsOnBase>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:8000'],
            'deliverables' => ['sometimes', 'array', 'list', 'max:5', new DistinctDeliverableIds],
            'deliverables.*' => ['required', 'array:id,type,description,path,change,project,file,name,command,directory,fails_on_base'],
            'deliverables.*.id' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'deliverables.*.type' => ['required', 'string', Rule::enum(TaskDeliverableType::class)],
            'deliverables.*.description' => ['required', 'string', 'max:500'],
            'deliverables.*.path' => ['required_if:deliverables.*.type,file', 'prohibited_unless:deliverables.*.type,file', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'deliverables.*.change' => ['required_if:deliverables.*.type,file', 'prohibited_unless:deliverables.*.type,file', 'string', Rule::in(['created', 'modified', 'any'])],
            'deliverables.*.project' => ['required_if:deliverables.*.type,test', 'prohibited_unless:deliverables.*.type,test', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'deliverables.*.file' => ['required_if:deliverables.*.type,test', 'prohibited_unless:deliverables.*.type,test', 'string', 'max:500', new ExactPestTestFile],
            'deliverables.*.name' => ['required_if:deliverables.*.type,test', 'prohibited_unless:deliverables.*.type,test', 'string', 'max:200'],
            'deliverables.*.fails_on_base' => ['sometimes', new FailsOnBase, 'boolean:strict'],
            'deliverables.*.command' => ['required_if:deliverables.*.type,command', 'prohibited_unless:deliverables.*.type,command', 'string', 'max:1000'],
            'deliverables.*.directory' => ['sometimes', 'prohibited_unless:deliverables.*.type,command', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['title', 'brief', 'deliverables']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): CreateTaskData
    {
        return new CreateTaskData(
            title: (string) $this->validated('title'),
            brief: (string) $this->validated('brief'),
            deliverables: self::deliverables($this->validated('deliverables')),
        );
    }

    /** @return list<array<string, string|bool>> */
    public static function deliverables(mixed $validated): array
    {
        return is_array($validated) ? array_values(array_map(static fn (mixed $deliverable): array => TaskDeliverable::fromArray(is_array($deliverable) ? $deliverable : [])->toArray(), $validated)) : [];
    }
}
