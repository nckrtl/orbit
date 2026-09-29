<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class ProjectLifecycleStepStore
{
    /** @return list<LifecycleStep> */
    public function ordered(Project $project, LifecyclePhase $phase): array
    {
        return array_values(ProjectLifecycleStep::query()
            ->where('project_id', $project->id)
            ->where('phase', $phase->value)
            ->orderBy('position')
            ->get()
            ->map(fn (ProjectLifecycleStep $row): LifecycleStep => $this->toDomain($row))
            ->all());
    }

    public function create(
        Project $project,
        LifecyclePhase $phase,
        LifecycleStep $step,
        ?string $before,
        ?string $after,
    ): LifecycleStep {
        return DB::transaction(function () use ($project, $phase, $step, $before, $after): LifecycleStep {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = $this->ordered($project, $phase);
            $placed = $this->insert($existing, $step, $before, $after);
            $this->assertValid($placed, $existing);
            $this->persist($project, $phase, $placed);

            return $step;
        }, 5);
    }

    public function update(
        Project $project,
        LifecyclePhase $phase,
        string $name,
        ?string $command,
        ?int $timeoutSeconds,
        ?string $before,
        ?string $after,
        bool $hasCommand,
        bool $hasTimeout,
    ): LifecycleStep {
        return DB::transaction(function () use ($project, $phase, $name, $command, $timeoutSeconds, $before, $after, $hasCommand, $hasTimeout): LifecycleStep {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = $this->ordered($project, $phase);
            $previous = $existing;
            $index = $this->indexByName($existing, $name, $phase);
            $current = $existing[$index];
            array_splice($existing, $index, 1);

            try {
                $updated = new LifecycleStep(
                    $current->name,
                    $hasCommand ? (string) $command : $current->command,
                    $hasTimeout ? (int) $timeoutSeconds : $current->timeoutSeconds,
                );
            } catch (InvalidArgumentException) {
                $this->invalid('A lifecycle step is invalid.');
            }

            $placed = $before === null && $after === null
                ? $this->restore($existing, $index, $updated)
                : $this->insert($existing, $updated, $before, $after);
            $this->assertValid($placed, $previous);
            $this->persist($project, $phase, $placed);

            return $updated;
        }, 5);
    }

    public function destroy(Project $project, LifecyclePhase $phase, string $name): LifecycleStep
    {
        return DB::transaction(function () use ($project, $phase, $name): LifecycleStep {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $existing = $this->ordered($project, $phase);
            $previous = $existing;
            $index = $this->indexByName($existing, $name, $phase);
            $removed = $existing[$index];
            array_splice($existing, $index, 1);
            $this->assertValid($existing, $previous);
            $this->persist($project, $phase, $existing);

            return $removed;
        }, 5);
    }

    /**
     * @param  list<LifecycleStep>  $steps
     * @return list<LifecycleStep>
     */
    private function insert(array $steps, LifecycleStep $step, ?string $before, ?string $after): array
    {
        foreach ($steps as $current) {
            if ($current->name === $step->name) {
                $this->invalid('The lifecycle step names must be unique.');
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
     * @param  list<LifecycleStep>  $steps
     * @return list<LifecycleStep>
     */
    private function restore(array $steps, int $index, LifecycleStep $step): array
    {
        array_splice($steps, $index, 0, [$step]);

        return $steps;
    }

    /** @param list<LifecycleStep> $steps */
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
     * @param  list<LifecycleStep>  $steps
     */
    private function indexByName(array $steps, string $name, LifecyclePhase $phase): int
    {
        foreach ($steps as $index => $step) {
            if ($step->name === $name) {
                return $index;
            }
        }

        throw new ResourceOperationException(
            errorCode: $phase === LifecyclePhase::Setup ? 'setup_step.not_found' : 'teardown_step.not_found',
            message: 'The lifecycle step was not found.',
            status: 404,
        );
    }

    /**
     * A list may total at most `LifecycleStep::MaxTotalTimeoutSeconds`. A list stored before that limit
     * can total more; it accepts any change that does not raise its total, so it can always be lowered
     * or shortened. At run time the request deadline still ends such a list cleanly.
     *
     * @param  list<LifecycleStep>  $steps
     * @param  list<LifecycleStep>  $previous
     */
    private function assertValid(array $steps, array $previous): void
    {
        if (count($steps) > 32) {
            $this->invalid('The lifecycle list has too many steps.');
        }

        $total = $this->totalTimeout($steps);

        if ($total > LifecycleStep::MaxTotalTimeoutSeconds && $total > $this->totalTimeout($previous)) {
            $this->invalid('The lifecycle list timeout total is too large.');
        }
    }

    /** @param list<LifecycleStep> $steps */
    private function totalTimeout(array $steps): int
    {
        return array_sum(array_map(
            static fn (LifecycleStep $step): int => $step->timeoutSeconds,
            $steps,
        ));
    }

    /** @param list<LifecycleStep> $steps */
    private function persist(Project $project, LifecyclePhase $phase, array $steps): void
    {
        ProjectLifecycleStep::query()
            ->where('project_id', $project->id)
            ->where('phase', $phase->value)
            ->delete();

        foreach ($steps as $position => $step) {
            ProjectLifecycleStep::query()->create([
                'project_id' => $project->id,
                'phase' => $phase->value,
                'name' => $step->name,
                'command' => $step->command,
                'timeout_seconds' => $step->timeoutSeconds,
                'position' => $position,
            ]);
        }
    }

    private function toDomain(ProjectLifecycleStep $row): LifecycleStep
    {
        return new LifecycleStep($row->name, $row->command, $row->timeout_seconds);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['body' => [$message]]);
    }
}
