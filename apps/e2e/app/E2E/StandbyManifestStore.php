<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\StandbyGeneration;
use RuntimeException;

final readonly class StandbyManifestStore
{
    public function __construct(
        private AtomicJsonStore $store,
        private StatePaths $paths,
    ) {}

    public function promoted(): ?StandbyGeneration
    {
        $value = $this->store->read('standby/promoted.json');

        return $value === null ? null : StandbyGeneration::fromArray($value);
    }

    public function promote(StandbyGeneration $generation): void
    {
        $this->store->write('standby/promoted.json', $generation->toArray());
    }

    public function record(StandbyGeneration $generation): void
    {
        $this->store->write("standby/generations/{$generation->id}.json", $generation->toArray());
    }

    /** @return list<StandbyGeneration> */
    public function prunable(StandbyGeneration $current): array
    {
        $protected = [$current->id];
        if ($current->previousGenerationId !== null) {
            $protected[] = $current->previousGenerationId;
        }

        foreach (glob($this->paths->path('topologies').'/*.json') ?: [] as $file) {
            $relative = 'topologies/'.basename($file);
            $topology = $this->store->read($relative);
            $generationId = $topology['generation']['id'] ?? null;
            if (! is_string($generationId)) {
                throw new RuntimeException('A topology generation pin is uncertain.');
            }
            $protected[] = $generationId;
        }

        $prunable = [];
        foreach (glob($this->paths->path('standby/generations').'/*.json') ?: [] as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            $value = $this->store->read('standby/generations/'.basename($file));
            if ($value === null) {
                throw new RuntimeException('A standby generation disappeared during pruning.');
            }
            $generation = StandbyGeneration::fromArray($value);
            if ($generation->id !== $id) {
                throw new RuntimeException('A standby generation path does not match its identity.');
            }
            if (! in_array($id, $protected, true)) {
                $prunable[] = $generation;
            }
        }

        return $prunable;
    }

    public function forget(StandbyGeneration $generation): void
    {
        $file = $this->paths->path("standby/generations/{$generation->id}.json");
        if (! is_file($file) || is_link($file) || ! unlink($file)) {
            throw new RuntimeException('Unable to remove the exact standby generation manifest.');
        }
    }
}
