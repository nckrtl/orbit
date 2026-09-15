<?php

declare(strict_types=1);

namespace App\Http\Requests\Apps;

use App\Data\Apps\UpdateAppData;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class UpdateAppRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'required', 'string', 'alpha_dash:ascii', 'max:63'],
            'repository_url' => ['sometimes', 'required', 'string', 'max:2048'],
            'default_branch' => ['sometimes', 'required', 'string', 'max:255'],
            'root' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['slug', 'repository_url', 'default_branch', 'root'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (
                ! $this->exists('slug')
                && ! $this->exists('repository_url')
                && ! $this->exists('default_branch')
                && ! $this->exists('root')
            ) {
                $validator->errors()->add('body', 'Provide at least one App update.');
            }

            $repository = $this->input('repository_url');

            if (is_string($repository) && $repository !== '' && ! GitRepositoryOrigin::isValid($repository)) {
                $validator->errors()->add(
                    'repository_url',
                    'The repository URL must be a valid HTTPS or SSH Git origin.',
                );
            }

            $branch = $this->input('default_branch');

            if (is_string($branch) && ! GitBranchName::isValid($branch)) {
                $validator->errors()->add('default_branch', 'The default branch is not a valid Git branch name.');
            }

            $root = $this->input('root');

            if (is_string($root) && ! RelativeWebRoot::isValid($root)) {
                $validator->errors()->add('root', 'The root must be a normalized relative web path.');
            }
        }];
    }

    public function payload(): UpdateAppData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new UpdateAppData(
            slugProvided: array_key_exists('slug', $validated),
            slug: is_string($validated['slug'] ?? null) ? $validated['slug'] : null,
            repositoryUrlProvided: array_key_exists('repository_url', $validated),
            repositoryUrl: is_string($validated['repository_url'] ?? null) ? $validated['repository_url'] : null,
            defaultBranchProvided: array_key_exists('default_branch', $validated),
            defaultBranch: is_string($validated['default_branch'] ?? null) ? $validated['default_branch'] : null,
            rootProvided: array_key_exists('root', $validated),
            root: is_string($validated['root'] ?? null) ? $validated['root'] : null,
        );
    }
}
