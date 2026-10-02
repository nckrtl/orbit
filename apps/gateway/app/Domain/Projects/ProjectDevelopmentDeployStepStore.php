<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class ProjectDevelopmentDeployStepStore
{
    /** @return list<DevelopmentDeployStep> */
    public function ordered(Project $project): array
    {
        return array_values(ProjectDevelopmentDeployStep::query()
            ->where('project_id', $project->id)
            ->orderBy('position')
            ->get()
            ->map(fn (ProjectDevelopmentDeployStep $row): DevelopmentDeployStep => $this->toDomain($row))
            ->all());
    }

    public function create(
        Project $project,
        DevelopmentDeployStep $step,
        ?string $before,
        ?string $after,
    ): DevelopmentDeployStep {
        return DB::transaction(function () use ($project, $step, $before, $after): DevelopmentDeployStep {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = $this->ordered($project);
            $placed = $this->insert($existing, $step, $before, $after);
            $this->assertValid($placed);
            $this->persist($project, $placed);

            return $step;
        }, 5);
    }

    public function update(
        Project $project,
        string $name,
        ?string $command,
        ?int $timeoutSeconds,
        ?string $before,
        ?string $after,
        bool $hasCommand,
        bool $hasTimeout,
        ?bool $required = null,
    ): DevelopmentDeployStep {
        return DB::transaction(function () use ($project, $name, $command, $timeoutSeconds, $before, $after, $hasCommand, $hasTimeout, $required): DevelopmentDeployStep {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = $this->ordered($project);
            $index = $this->indexByName($existing, $name);
            $current = $existing[$index];
            array_splice($existing, $index, 1);

            try {
                $updated = new DevelopmentDeployStep(
                    $current->name,
                    $hasCommand ? (string) $command : $current->command,
                    $hasTimeout ? (int) $timeoutSeconds : $current->timeoutSeconds,
                    $required ?? $current->required,
                );
            } catch (InvalidArgumentException) {
                $this->invalid('A development deploy step is invalid.');
            }

            $placed = $before === null && $after === null
                ? $this->restore($existing, $index, $updated)
                : $this->insert($existing, $updated, $before, $after);
            $this->assertValid($placed);
            $this->persist($project, $placed);

            return $updated;
        }, 5);
    }

    public function destroy(Project $project, string $name): DevelopmentDeployStep
    {
        return DB::transaction(function () use ($project, $name): DevelopmentDeployStep {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = $this->ordered($project);
            $index = $this->indexByName($existing, $name);
            $removed = $existing[$index];
            array_splice($existing, $index, 1);
            $this->assertValid($existing);
            $this->persist($project, $existing);

            return $removed;
        }, 5);
    }

    /**
     * @param  list<DevelopmentDeployStep>  $steps
     * @return list<DevelopmentDeployStep>
     */
    private function insert(array $steps, DevelopmentDeployStep $step, ?string $before, ?string $after): array
    {
        foreach ($steps as $current) {
            if ($current->name === $step->name) {
                $this->invalid('The development deploy step names must be unique.');
            }
        }

        if ($before !== null && $after !== null) {
            $this->invalid('Provide only one of before or after.');
        }

        if ($before !== null) {
            array_splice($steps, $this->indexOfPlacement($steps, $before), 0, [$step]);

            return $steps;
        }

        if ($after !== null) {
            array_splice($steps, $this->indexOfPlacement($steps, $after) + 1, 0, [$step]);

            return $steps;
        }

        $steps[] = $step;

        return $steps;
    }

    /**
     * @param  list<DevelopmentDeployStep>  $steps
     * @return list<DevelopmentDeployStep>
     */
    private function restore(array $steps, int $index, DevelopmentDeployStep $step): array
    {
        array_splice($steps, $index, 0, [$step]);

        return $steps;
    }

    /** @param list<DevelopmentDeployStep> $steps */
    private function indexOfPlacement(array $steps, string $name): int
    {
        foreach ($steps as $index => $step) {
            if ($step->name === $name) {
                return $index;
            }
        }

        $this->invalid('The placement step is unknown.');
    }

    /**
     * @param  list<DevelopmentDeployStep>  $steps
     */
    private function indexByName(array $steps, string $name): int
    {
        foreach ($steps as $index => $step) {
            if ($step->name === $name) {
                return $index;
            }
        }

        throw new ResourceOperationException(
            errorCode: 'development_deploy_step.not_found',
            message: 'The development deploy step was not found.',
            status: 404,
        );
    }

    /** @param list<DevelopmentDeployStep> $steps */
    private function assertValid(array $steps): void
    {
        if (count($steps) > 32) {
            $this->invalid('The development deploy list has too many steps.');
        }

        $total = $this->totalTimeout($steps);

        if ($total > DevelopmentDeployStep::MaxTotalTimeoutSeconds) {
            $this->invalid('The development deploy list timeout total is too large.');
        }
    }

    /** @param list<DevelopmentDeployStep> $steps */
    private function totalTimeout(array $steps): int
    {
        return array_sum(array_map(
            static fn (DevelopmentDeployStep $step): int => $step->timeoutSeconds,
            $steps,
        ));
    }

    /** @param list<DevelopmentDeployStep> $steps */
    private function persist(Project $project, array $steps): void
    {
        ProjectDevelopmentDeployStep::query()
            ->where('project_id', $project->id)
            ->delete();

        foreach ($steps as $position => $step) {
            ProjectDevelopmentDeployStep::query()->create([
                'project_id' => $project->id,
                'name' => $step->name,
                'command' => $step->command,
                'timeout_seconds' => $step->timeoutSeconds,
                'required' => $step->required,
                'position' => $position,
            ]);
        }
    }

    private function toDomain(ProjectDevelopmentDeployStep $row): DevelopmentDeployStep
    {
        return new DevelopmentDeployStep($row->name, $row->command, $row->timeout_seconds, $row->required);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['body' => [$message]]);
    }
}
