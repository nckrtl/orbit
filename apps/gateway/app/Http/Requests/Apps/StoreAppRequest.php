<?php

declare(strict_types=1);

namespace App\Http\Requests\Apps;

use App\Data\Apps\CreateAppData;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class StoreAppRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['required', 'string', 'alpha_dash:ascii', 'max:63'],
            'repository_url' => ['required', 'string', 'max:2048'],
            'main_branch' => ['sometimes', 'string', 'max:255'],
            'root' => ['required', 'string', 'max:255'],
            'defaults' => ['nullable', 'array'],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['name', 'slug', 'repository_url', 'main_branch', 'root', 'defaults'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! is_string($this->input('repository_url'))) {
                return;
            }

            $repository = $this->string('repository_url')->toString();

            if ($repository !== '' && ! GitRepositoryOrigin::isValid($repository)) {
                $validator->errors()->add(
                    'repository_url',
                    'The repository URL must be a valid HTTPS or SSH Git origin.',
                );
            }

            $this->validateSourceDefaults($validator);
        }];
    }

    public function payload(): CreateAppData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $slug = (string) $validated['slug'];
        $defaults = is_array($validated['defaults'] ?? null) ? $validated['defaults'] : null;

        return new CreateAppData(
            name: is_string($validated['name'] ?? null) ? $validated['name'] : $slug,
            slug: $slug,
            repositoryUrl: (string) $validated['repository_url'],
            mainBranch: is_string($validated['main_branch'] ?? null) ? $validated['main_branch'] : null,
            root: (string) $validated['root'],
            defaults: $defaults,
        );
    }

    private function validateSourceDefaults(Validator $validator): void
    {
        $branch = $this->input('main_branch');

        if (is_string($branch) && ! GitBranchName::isValid($branch)) {
            $validator->errors()->add('main_branch', 'The main branch is not a valid Git branch name.');
        }

        $root = $this->input('root');

        if (is_string($root) && ! RelativeWebRoot::isValid($root)) {
            $validator->errors()->add('root', 'The root must be a normalized relative web path.');
        }
    }
}
