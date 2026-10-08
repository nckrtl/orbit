<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Project;
use App\Rules\CommandPaths;

/** Shared by request-time validation and validation once the real review base resolves. */
final readonly class DeliverablePathChecker
{
    public function __construct(private DeliverablePathRepository $repository) {}

    /**
     * @param  list<array<string, mixed>>  $deliverables
     * @return array<string, string> Errors relative to the deliverables field.
     */
    public function check(Project $project, array $deliverables, string $commit, string $baseKind = 'resolved', ?Instance $workspace = null): array
    {
        $needsFiles = false;
        foreach ($deliverables as $deliverable) {
            if ((($deliverable['type'] ?? null) === 'file' && ($deliverable['change'] ?? null) !== 'created') || ($deliverable['paths'] ?? []) !== []) {
                $needsFiles = true;
            }
        }
        $files = $needsFiles ? $this->repository->files($project, $commit, $workspace) : [];
        $created = [];
        foreach ($deliverables as $deliverable) {
            if (($deliverable['type'] ?? null) === 'file' && ($deliverable['change'] ?? null) === 'created' && is_string($deliverable['path'] ?? null)) {
                $created[] = TaskDeliverable::relative($deliverable['path']);
            }
        }
        $errors = [];
        foreach ($deliverables as $index => $deliverable) {
            $id = is_string($deliverable['id'] ?? null) ? $deliverable['id'] : '';
            if (($deliverable['type'] ?? null) === 'file' && ($deliverable['change'] ?? null) !== 'created' && is_string($deliverable['path'] ?? null)) {
                $path = $deliverable['path'];
                $pattern = TaskDeliverable::relative($path);
                if (! array_any($files, static fn (string $file): bool => TaskDeliverableVerifier::matches($pattern, $file))) {
                    $errors["{$index}.path"] = "Deliverable {$id} path {$path} is missing on base {$commit} (base_kind={$baseKind}).";
                }
            }
            if (($deliverable['type'] ?? null) === 'command' && is_array($deliverable['paths'] ?? null)) {
                foreach ($deliverable['paths'] as $pathIndex => $path) {
                    if (! is_string($path)) {
                        continue;
                    }
                    $reason = CommandPaths::pathViolation($path, $files, $created, ($deliverable['fails_on_base'] ?? false) === true);
                    if ($reason !== null) {
                        $errors["{$index}.paths.{$pathIndex}"] = "Deliverable {$id} path {$path} {$reason} on base {$commit} (base_kind={$baseKind}).";
                    }
                }
            }
        }

        return $errors;
    }
}
